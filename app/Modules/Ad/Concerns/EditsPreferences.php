<?php

namespace App\Modules\Ad\Concerns;

use App\Modules\Ad\Gpp\Kind;
use App\Modules\Ad\Gpp\Kinds;
use App\Services\Apps\AppException;

/**
 * The Group Policy Preferences lists of the GPO settings dialog (Gpp\Kinds): loaded with the
 * GPO, edited as form rows in $gpp, sent back per list with PUT when it changed. Uses
 * TalksToApp (appRequest, succeeded) and the dialog state of Gpos (settingsFor).
 */
trait EditsPreferences
{
    /** @var array<string, list<array<string, mixed>>> kind key → rows in form shape (Kind::toForm), file order */
    public array $gpp = [];

    /** @var array<string, list<array<string, mixed>>> the rows as loaded */
    public array $gppOriginal = [];

    /** @var array<string, bool> kind key → the app has this list (404 = older PK95 AD) */
    public array $gppSupported = [];

    /** the row whose editor is open: "drives.2" */
    public ?string $gppEditing = null;

    /** the list whose 422 answer is being mapped (mapField) */
    private ?string $gppMapping = null;

    /** GET each list of the GPO; a list the app does not have stays unsupported */
    protected function loadPreferences(string $gpoId): void
    {
        $this->gpp = [];
        $this->gppSupported = [];
        $this->gppEditing = null;
        foreach (Kinds::all() as $key => $kind) {
            $this->gpp[$key] = [];
            $this->gppSupported[$key] = false;
            try {
                $response = $this->installedApp->client()->get('/api/gpos/'.$gpoId.'/'.$kind->endpoint());
            } catch (AppException) {
                continue;
            }
            if ($response->successful()) {
                $this->gppSupported[$key] = true;
                $this->gpp[$key] = $this->toRows($kind, (array) $response->json('items'));
            }
        }
        $this->gppOriginal = $this->gpp;
    }

    public function addGpp(string $key): void
    {
        $kind = Kinds::get($key);
        if ($kind === null || empty($this->gppSupported[$key])) {
            return;
        }
        if (count($this->gpp[$key]) >= $kind->max()) {
            $this->addError('gpp.'.$key, $kind->maxText());

            return;
        }
        $this->gpp[$key][] = $kind->newRow($this->gpp[$key]);
        $this->gppEditing = $key.'.'.(count($this->gpp[$key]) - 1);
    }

    public function editGpp(string $key, int $index): void
    {
        if (isset($this->gpp[$key][$index]) && ! $this->gpp[$key][$index]['readonly']) {
            $this->gppEditing = $key.'.'.$index;
        }
    }

    public function closeGpp(): void
    {
        $this->gppEditing = null;
    }

    public function removeGpp(string $key, int $index): void
    {
        if (! isset($this->gpp[$key][$index])) {
            return;
        }
        unset($this->gpp[$key][$index]);
        $this->gpp[$key] = array_values($this->gpp[$key]);
        $this->gppEditing = null;
    }

    /** $offset -1 = earlier in the file (processed before), +1 = later */
    public function moveGpp(string $key, int $index, int $offset): void
    {
        $to = $index + $offset;
        if (! isset($this->gpp[$key][$index], $this->gpp[$key][$to])) {
            return;
        }
        [$this->gpp[$key][$index], $this->gpp[$key][$to]] = [$this->gpp[$key][$to], $this->gpp[$key][$index]];
        if ($this->gppEditing === $key.'.'.$index) {
            $this->gppEditing = $key.'.'.$to;
        } elseif ($this->gppEditing === $key.'.'.$to) {
            $this->gppEditing = $key.'.'.$index;
        }
    }

    /** "Alle …" clears the group; a group of another condition stays selected */
    public function updatedGpp(mixed $value, ?string $path = null): void
    {
        if ($path !== null && preg_match('/^(\w+)\.(\d+)\.condition$/', $path, $m) && $value === 'all' && isset($this->gpp[$m[1]][(int) $m[2]])) {
            $this->gpp[$m[1]][(int) $m[2]]['group'] = '';
            $this->gpp[$m[1]][(int) $m[2]]['group_name'] = '';
        }
    }

    /** index of the row of this list whose editor is open */
    public function gppEditingIndex(string $key): ?int
    {
        return $this->gppEditing !== null && str_starts_with($this->gppEditing, $key.'.')
            ? (int) substr($this->gppEditing, strlen($key) + 1) : null;
    }

    /**
     * Indexes of rows of a list that are new or changed, and the number of changes (removed
     * rows count, a pure reordering counts once).
     *
     * @return array{0: list<int>, 1: int}
     */
    public function gppChanges(string $key): array
    {
        if (empty($this->gppSupported[$key])) {
            return [[], 0];
        }
        $rows = $this->gpp[$key] ?? [];
        $original = $this->gppOriginal[$key] ?? [];
        $before = collect($original)->keyBy('uid');
        $changed = [];
        foreach ($rows as $i => $row) {
            if ($row['uid'] === '' || $before->get($row['uid']) !== $row) {
                $changed[] = $i;
            }
        }
        $count = count($changed) + $before->keys()->diff(array_column($rows, 'uid'))->count();
        if ($count === 0 && array_column($rows, 'uid') !== array_column($original, 'uid')) {
            $count = 1;
        }

        return [$changed, $count];
    }

    /** changes of all lists, for the footer */
    public function gppChangeCount(): int
    {
        return array_sum(array_map(fn (string $key) => $this->gppChanges($key)[1], array_keys(Kinds::all())));
    }

    /** rows of the supported lists of a tab (computer | user) */
    public function gppRowCount(string $scope): int
    {
        return array_sum(array_map(fn (string $key) => ! empty($this->gppSupported[$key]) ? count($this->gpp[$key] ?? []) : 0, array_keys(Kinds::of($scope))));
    }

    protected function discardPreferences(): void
    {
        $this->gpp = $this->gppOriginal;
        $this->gppEditing = null;
    }

    /** a list that changed (only those are sent) */
    protected function gppChanged(string $key): bool
    {
        return ! empty($this->gppSupported[$key]) && ($this->gpp[$key] ?? []) !== ($this->gppOriginal[$key] ?? []);
    }

    /** a condition on a group needs the group; checked before anything is written */
    protected function checkPreferences(): void
    {
        foreach (array_keys(Kinds::all()) as $key) {
            if (! $this->gppChanged($key)) {
                continue;
            }
            foreach ($this->gpp[$key] as $i => $row) {
                if (! $row['readonly'] && $row['condition'] !== 'all' && $row['group'] === '') {
                    $this->addError('gpp.'.$key.'.'.$i.'.group', 'Wähle die Sicherheitsgruppe für die Bedingung.');
                }
            }
        }
    }

    /** PUT every changed list; false after the first error (field errors in the form) */
    protected function savePreferences(): bool
    {
        foreach (Kinds::all() as $key => $kind) {
            if (! $this->gppChanged($key)) {
                continue;
            }
            $items = array_map(fn (array $row) => $kind->toApi($row), $this->gpp[$key]);
            $response = $this->appRequest(fn ($api) => $api->put('/api/gpos/'.$this->settingsFor.'/'.$kind->endpoint(), ['items' => $items]));
            $this->gppMapping = $key;
            $ok = $this->succeeded($response, '');
            $this->gppMapping = null;
            if (! $ok) {
                return false;
            }
            $this->gpp[$key] = $this->toRows($kind, (array) $response->json('items'));
            $this->gppOriginal[$key] = $this->gpp[$key];
        }

        return true;
    }

    /** "items.2.target.group_id" of the list being saved → "gpp.drives.2.group"; null for other fields */
    protected function mapPreferenceField(string $field): ?string
    {
        $kind = $this->gppMapping !== null ? Kinds::get($this->gppMapping) : null;
        if ($kind === null || ($field !== 'items' && ! str_starts_with($field, 'items.'))) {
            return null;
        }
        $rest = (string) substr($field, strlen('items.'));

        return 'gpp.'.$kind->key().($rest === '' ? '' : '.'.$kind->errorField($rest));
    }

    /**
     * Groups the rows target (id → stored name), so a group the list no longer carries stays
     * selectable.
     *
     * @return array<string, string>
     */
    protected function preferenceGroups(): array
    {
        $groups = [];
        foreach ($this->gpp as $rows) {
            foreach ($rows as $row) {
                if ($row['group'] !== '') {
                    $groups[$row['group']] = $row['group_name'] ?: $row['group'];
                }
            }
        }

        return $groups;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function toRows(Kind $kind, array $items): array
    {
        return array_values(array_map(fn ($item) => $kind->toForm((array) $item), $items));
    }
}
