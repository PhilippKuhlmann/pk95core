<?php

namespace App\Modules\Ad;

use App\Modules\Ad\Concerns\AdPage;
use App\Services\Apps\AppException;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 AD → Gruppenrichtlinien: list, create, rename/status, delete; links (add, enable,
 * enforce, order, remove); the settings form built from GET /api/gpos/catalog (bool, int,
 * enum, string, text, scripts; empty = not configured = null); and the inheritance of a
 * container with "Vererbung blockieren".
 */
class Gpos extends Component
{
    use AdPage;

    /** container whose resulting GPO order is shown (domain or OU id) */
    #[Url]
    public string $target = '';

    /** null closed, 'new' or the GPO id */
    public ?string $editing = null;

    /** @var array{name: string, status: string} */
    public array $form = ['name' => '', 'status' => 'enabled'];

    /** links dialog: the GPO id */
    public ?string $linksFor = null;

    public string $linkTarget = '';

    /** settings dialog: the GPO id */
    public ?string $settingsFor = null;

    /** @var array<string, mixed> form values per catalog key (bool: ''|'1'|'0', scripts: list) */
    public array $settings = [];

    /** @var array<string, mixed> the values as loaded */
    public array $settingsOriginal = [];

    /** @var array<string, mixed> other_settings of the GPO in the dialog */
    public array $otherSettings = [];

    /** @var list<array<string, mixed>> drive maps of the GPO in the dialog, form shape (driveToForm), file order */
    public array $drives = [];

    /** @var list<array<string, mixed>> the drive maps as loaded */
    public array $drivesOriginal = [];

    /** the app has GET/PUT /api/gpos/{id}/drives (PK95 AD 1.1.0+) */
    public bool $drivesSupported = false;

    /** index in $drives whose editor is open */
    public ?int $driveEditing = null;

    /** tab of the settings dialog: computer | user | drives */
    public string $settingsTab = 'computer';

    /** filter of the settings dialog: label or help contains it */
    public string $settingsSearch = '';

    /** filter of the settings dialog: only configured settings */
    public bool $onlyConfigured = false;

    /** who a drive map applies to (form field "condition" ↔ target {group_id, not}) */
    public const DRIVE_CONDITIONS = [
        'all' => 'Alle Benutzer, für die die Gruppenrichtlinie gilt',
        'member' => 'Nur Mitglieder der Sicherheitsgruppe …',
        'not_member' => 'Alle außer Mitglieder der Sicherheitsgruppe …',
    ];

    public const STATUS = [
        'enabled' => 'Aktiv',
        'user_disabled' => 'Benutzerkonfiguration aus',
        'computer_disabled' => 'Computerkonfiguration aus',
        'disabled' => 'Aus',
    ];

    public const SCOPES = ['computer' => 'Computerkonfiguration', 'user' => 'Benutzerkonfiguration'];

    /** drive map actions, like the GPMC */
    public const DRIVE_ACTIONS = [
        'update' => 'Aktualisieren',
        'create' => 'Erstellen',
        'replace' => 'Ersetzen',
        'delete' => 'Löschen',
    ];

    public const DRIVE_ACTION_HELP = [
        'update' => 'Verbindet das Laufwerk oder ändert eine bestehende Verbindung (üblich).',
        'create' => 'Verbindet nur, wenn der Buchstabe noch frei ist.',
        'replace' => 'Trennt das Laufwerk und verbindet es neu.',
        'delete' => 'Trennt das Laufwerk.',
    ];

    public const DRIVE_VISIBILITY = ['unchanged' => 'Unverändert', 'hide' => 'Ausblenden', 'show' => 'Anzeigen'];

    public const DRIVES_MAX = 50;

    // --- GPO ---------------------------------------------------------------------------

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['name' => '', 'status' => 'enabled'];
        $this->editing = 'new';
    }

    public function edit(string $id): void
    {
        $this->resetErrorBag();
        $gpo = $this->gpo($id);
        if ($gpo === null) {
            return;
        }
        $this->form = ['name' => (string) $gpo['name'], 'status' => (string) $gpo['status']];
        $this->editing = $id;
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->linksFor = null;
        $this->settingsFor = null;
        $this->linkTarget = '';
        $this->driveEditing = null;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $name = trim($this->form['name']);
        if ($this->editing === 'new') {
            $response = $this->appRequest(fn ($api) => $api->post('/api/gpos', ['name' => $name]));
            if ($this->succeeded($response)) {
                $this->done('Gruppenrichtlinie '.$name.' angelegt. Verknüpfe sie mit der Domäne oder einer OU, damit sie gilt.');
                $this->editing = null;
            }

            return;
        }
        $gpo = $this->gpo((string) $this->editing);
        if ($gpo === null) {
            return;
        }
        $body = [];
        if (! $gpo['builtin'] && $name !== $gpo['name']) {
            $body['name'] = $name;
        }
        if ($this->form['status'] !== $gpo['status']) {
            $body['status'] = $this->form['status'];
        }
        if ($body !== []) {
            $response = $this->appRequest(fn ($api) => $api->put('/api/gpos/'.$this->editing, $body));
            if (! $this->succeeded($response)) {
                return;
            }
        }
        $this->done('Gruppenrichtlinie gespeichert.');
        $this->editing = null;
    }

    public function delete(string $id): void
    {
        $response = $this->appRequest(fn ($api) => $api->delete('/api/gpos/'.$id));
        if ($this->succeeded($response, '')) {
            $this->done('Gruppenrichtlinie gelöscht.');
        }
    }

    // --- links -------------------------------------------------------------------------

    public function links(string $id): void
    {
        $this->resetErrorBag();
        $this->linkTarget = '';
        $this->linksFor = $id;
    }

    public function addLink(): void
    {
        $this->resetErrorBag();
        if ($this->linkTarget === '') {
            $this->addError('linkTarget', 'Wähle die Domäne oder eine OU.');

            return;
        }
        $response = $this->appRequest(fn ($api) => $api->post('/api/gpos/'.$this->linksFor.'/links', ['target_id' => $this->linkTarget, 'enabled' => true, 'enforced' => false]));
        if ($this->succeeded($response, '')) {
            $this->linkTarget = '';
            $this->done('Verknüpft.');
        }
    }

    /** enabled / enforced / order of one link */
    public function updateLink(string $gpoId, string $targetId, string $field, int|bool $value): void
    {
        if (! in_array($field, ['enabled', 'enforced', 'order'], true)) {
            return;
        }
        $response = $this->appRequest(fn ($api) => $api->put('/api/gpos/'.$gpoId.'/links/'.$targetId, [$field => $field === 'order' ? (int) $value : (bool) $value]));
        if ($this->succeeded($response, '')) {
            $this->done(match ($field) {
                'enabled' => $value ? 'Verknüpfung aktiviert.' : 'Verknüpfung deaktiviert.',
                'enforced' => $value ? 'Verknüpfung erzwungen.' : 'Nicht mehr erzwungen.',
                default => 'Reihenfolge geändert.',
            });
        }
    }

    public function removeLink(string $gpoId, string $targetId): void
    {
        $response = $this->appRequest(fn ($api) => $api->delete('/api/gpos/'.$gpoId.'/links/'.$targetId));
        if ($this->succeeded($response, '')) {
            $this->done('Verknüpfung entfernt.');
        }
    }

    // --- settings ----------------------------------------------------------------------

    public function openSettings(string $id): void
    {
        $this->resetErrorBag();
        $response = $this->appRequest(fn ($api) => $api->get('/api/gpos/'.$id));
        if (! $this->succeeded($response, '')) {
            return;
        }
        $values = (array) $response->json('settings');
        $form = [];
        foreach ($this->catalog() as $item) {
            $form[$item['key']] = self::toForm($item, $values[$item['key']] ?? null);
        }
        $this->settings = $form;
        $this->settingsOriginal = $form;
        $this->otherSettings = (array) $response->json('other_settings');
        $this->loadDrives($id);
        $this->settingsSearch = '';
        $this->onlyConfigured = false;
        // start where something is configured: computer, user, then the drive maps
        $this->settingsTab = 'computer';
        foreach (['computer', 'user'] as $scope) {
            if ($this->configuredCount($scope) > 0) {
                $this->settingsTab = $scope;
                break;
            }
            if ($scope === 'user' && $this->drives !== []) {
                $this->settingsTab = 'drives';
            }
        }
        $this->settingsFor = $id;
    }

    public function updatedSettingsTab(string $tab): void
    {
        if (! in_array($tab, ['computer', 'user', 'drives'], true)) {
            $this->settingsTab = 'computer';
        }
    }

    /** back to the values as loaded (settings and drive maps) */
    public function discardSettings(): void
    {
        $this->settings = $this->settingsOriginal;
        $this->drives = $this->drivesOriginal;
        $this->driveEditing = null;
        $this->resetErrorBag();
    }

    /**
     * The badge of a setting row: label and tone (gray = not configured, green/red = bool,
     * blue = a value).
     *
     * @param  array<string, mixed>  $item  catalog item
     * @return array{0: string, 1: string}
     */
    public static function settingStatus(array $item, mixed $value): array
    {
        if ($item['type'] === 'bool') {
            return match ((string) $value) {
                '1' => ['Aktiviert', 'green'],
                '0' => ['Deaktiviert', 'red'],
                default => ['Nicht konfiguriert', 'gray'],
            };
        }
        if ($item['type'] === 'scripts') {
            $count = count(array_filter((array) $value, fn ($s) => trim((string) ($s['path'] ?? '')) !== ''));

            return $count === 0 ? ['Nicht konfiguriert', 'gray'] : [$count === 1 ? '1 Skript' : $count.' Skripte', 'blue'];
        }
        $text = trim((string) $value);
        if ($text === '') {
            return ['Nicht konfiguriert', 'gray'];
        }

        return match ($item['type']) {
            'int' => [$text.(! empty($item['unit']) ? ' '.$item['unit'] : ''), 'blue'],
            'enum' => [(string) (collect((array) ($item['options'] ?? []))->first(fn ($o) => (string) $o['value'] === $text)['label'] ?? $text), 'blue'],
            default => [Str::limit(preg_replace('/\s+/', ' ', $text), 40), 'blue'],
        };
    }

    /** @param  array<string, mixed>  $item */
    public static function isConfigured(array $item, mixed $value): bool
    {
        return self::settingStatus($item, $value)[1] !== 'gray';
    }

    /** configured settings of one scope (computer | user), from the form as it is now */
    private function configuredCount(string $scope): int
    {
        return count(array_filter($this->catalog(), fn ($i) => ($i['scope'] ?? '') === $scope && self::isConfigured($i, $this->settings[$i['key']] ?? null)));
    }

    /**
     * Indexes of drive rows that are new or changed, and the number of changes (removed rows
     * count, a pure reordering counts once).
     *
     * @return array{0: list<int>, 1: int}
     */
    private function driveChanges(): array
    {
        if (! $this->drivesSupported) {
            return [[], 0];
        }
        $before = collect($this->drivesOriginal)->keyBy('uid');
        $changed = [];
        foreach ($this->drives as $i => $drive) {
            if ($drive['uid'] === '' || $before->get($drive['uid']) !== $drive) {
                $changed[] = $i;
            }
        }
        $removed = $before->keys()->diff(array_column($this->drives, 'uid'))->count();
        $count = count($changed) + $removed;
        if ($count === 0 && array_column($this->drives, 'uid') !== array_column($this->drivesOriginal, 'uid')) {
            $count = 1;
        }

        return [$changed, $count];
    }

    // --- drive maps (PK95 AD 1.1.0) ----------------------------------------------------

    /** GET /api/gpos/{id}/drives; 404 = an app before 1.1.0 (the GPO itself was just read) */
    private function loadDrives(string $id): void
    {
        $this->drives = [];
        $this->drivesOriginal = [];
        $this->driveEditing = null;
        $this->drivesSupported = false;
        try {
            $response = $this->installedApp->client()->get('/api/gpos/'.$id.'/drives');
        } catch (AppException) {
            return;
        }
        if (! $response->successful()) {
            return;
        }
        $this->drivesSupported = true;
        $this->drives = array_values(array_map(fn ($d) => self::driveToForm((array) $d), (array) $response->json('items')));
        $this->drivesOriginal = $this->drives;
    }

    public function addDrive(): void
    {
        if (count($this->drives) >= self::DRIVES_MAX) {
            $this->addError('drives', 'Höchstens '.self::DRIVES_MAX.' Netzlaufwerke je Gruppenrichtlinie.');

            return;
        }
        $this->drives[] = self::driveToForm(['letter' => $this->freeLetter()]);
        $this->driveEditing = count($this->drives) - 1;
    }

    public function editDrive(int $index): void
    {
        if (isset($this->drives[$index]) && ! $this->drives[$index]['readonly']) {
            $this->driveEditing = $index;
        }
    }

    public function closeDrive(): void
    {
        $this->driveEditing = null;
    }

    public function removeDrive(int $index): void
    {
        unset($this->drives[$index]);
        $this->drives = array_values($this->drives);
        $this->driveEditing = null;
    }

    /** $offset -1 = earlier in the file (processed before), +1 = later */
    public function moveDrive(int $index, int $offset): void
    {
        $to = $index + $offset;
        if (! isset($this->drives[$index], $this->drives[$to])) {
            return;
        }
        [$this->drives[$index], $this->drives[$to]] = [$this->drives[$to], $this->drives[$index]];
        if ($this->driveEditing === $index) {
            $this->driveEditing = $to;
        } elseif ($this->driveEditing === $to) {
            $this->driveEditing = $index;
        }
    }

    /** the first letter from H on (then D to G) no other fixed drive of this GPO uses */
    private function freeLetter(): string
    {
        $used = array_column(array_filter($this->drives, fn ($d) => ! $d['first_available']), 'letter');
        foreach ([...range('H', 'Z'), ...range('D', 'G')] as $letter) {
            if (! in_array($letter, $used, true)) {
                return $letter;
            }
        }

        return 'Z';
    }

    /**
     * API item → form row. Read-only items (written by the GPMC with features PK95 AD does
     * not edit) keep the item as it came, they go back unchanged.
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    public static function driveToForm(array $d): array
    {
        $target = is_array($d['target'] ?? null) ? $d['target'] : null;
        $readonly = (bool) ($d['readonly'] ?? false);

        return [
            'uid' => (string) ($d['uid'] ?? ''),
            'letter' => strtoupper((string) ($d['letter'] ?? 'H')),
            'first_available' => (bool) ($d['first_available'] ?? false),
            'path' => (string) ($d['path'] ?? ''),
            'label' => (string) ($d['label'] ?? ''),
            'action' => (string) ($d['action'] ?? 'update'),
            'reconnect' => (bool) ($d['reconnect'] ?? true),
            'visibility' => (string) ($d['visibility'] ?? 'unchanged'),
            'condition' => $target === null ? 'all' : (! empty($target['not']) ? 'not_member' : 'member'),
            'group' => (string) ($target['group_id'] ?? ''),
            'group_name' => (string) ($target['group_name'] ?? ''),
            'changed_at' => (int) ($d['changed_at'] ?? 0),
            'readonly' => $readonly,
            'warning' => (string) ($d['warning'] ?? ''),
            'raw' => $readonly ? $d : null,
        ];
    }

    /**
     * Form row → API item: new items without uid, existing ones with their uid; read-only
     * items exactly as they came.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function driveToApi(array $f): array
    {
        if ($f['readonly'] && is_array($f['raw'])) {
            return $f['raw'];
        }
        $item = [
            'letter' => strtoupper(trim((string) $f['letter'])),
            'first_available' => (bool) $f['first_available'],
            'path' => trim((string) $f['path']),
            'label' => trim((string) $f['label']),
            'action' => (string) $f['action'],
            'reconnect' => (bool) $f['reconnect'],
            'visibility' => (string) $f['visibility'],
            'target' => $f['condition'] !== 'all' && $f['group'] !== ''
                ? ['group_id' => (string) $f['group'], 'not' => $f['condition'] === 'not_member']
                : null,
        ];

        return $f['uid'] !== '' ? ['uid' => $f['uid']] + $item : $item;
    }

    /** "Alle Benutzer" clears the group; a group of another condition stays selected */
    public function updatedDrives(mixed $value, ?string $key = null): void
    {
        if ($key !== null && preg_match('/^(\d+)\.condition$/', $key, $m) && $value === 'all' && isset($this->drives[(int) $m[1]])) {
            $this->drives[(int) $m[1]]['group'] = '';
            $this->drives[(int) $m[1]]['group_name'] = '';
        }
    }

    /** "Gilt für" of a drive row: Alle Benutzer / Mitglieder von X / Alle außer X */
    public static function driveAudience(array $drive, ?string $groupName = null): string
    {
        if ($drive['condition'] === 'all' || $drive['group'] === '') {
            return 'Alle Benutzer';
        }
        $name = $groupName ?: ($drive['group_name'] ?: $drive['group']);

        return $drive['condition'] === 'not_member' ? 'Alle außer '.$name : 'Mitglieder von '.$name;
    }

    /** item fields of 422 answers ("items.2.target.group_id") → the form ("drives.2.group") */
    protected function mapField(string $field): string
    {
        if (! str_starts_with($field, 'items')) {
            return $field;
        }
        $field = 'drives'.substr($field, strlen('items'));

        return preg_replace('/\.target(\.group_id)?$/', '.group', $field);
    }

    public function addScript(string $key): void
    {
        $list = array_values((array) ($this->settings[$key] ?? []));
        $list[] = ['path' => '', 'parameters' => ''];
        $this->settings[$key] = $list;
    }

    public function removeScript(string $key, int $index): void
    {
        $list = array_values((array) ($this->settings[$key] ?? []));
        unset($list[$index]);
        $this->settings[$key] = array_values($list);
    }

    public function saveSettings(): void
    {
        $this->resetErrorBag();
        $catalog = $this->catalog();
        $body = [];
        foreach ($catalog as $item) {
            $key = $item['key'];
            $value = $this->settings[$key] ?? null;
            if ($value !== ($this->settingsOriginal[$key] ?? null)) {
                $body[$key] = self::toApi($item, $value);
            }
        }
        $drivesChanged = $this->drivesSupported && $this->drives !== $this->drivesOriginal;
        if ($body === [] && ! $drivesChanged) {
            $this->settingsFor = null;

            return;
        }
        // a condition on a group needs the group; checked before anything is written
        if ($drivesChanged) {
            foreach ($this->drives as $i => $drive) {
                if (! $drive['readonly'] && $drive['condition'] !== 'all' && $drive['group'] === '') {
                    $this->addError('drives.'.$i.'.group', 'Wähle die Sicherheitsgruppe für die Bedingung.');
                }
            }
            if ($this->showFirstError($catalog)) {
                return;
            }
        }
        if ($body !== []) {
            $response = $this->appRequest(fn ($api) => $api->put('/api/gpos/'.$this->settingsFor.'/settings', ['settings' => $body]));
            if (! $this->succeeded($response, '')) {
                $this->showFirstError($catalog);

                return;
            }
            $this->settingsOriginal = $this->settings;
        }
        if ($drivesChanged) {
            $items = array_map(fn ($d) => self::driveToApi($d), $this->drives);
            $response = $this->appRequest(fn ($api) => $api->put('/api/gpos/'.$this->settingsFor.'/drives', ['items' => $items]));
            if (! $this->succeeded($response, '')) {
                $this->showFirstError($catalog);

                return;
            }
            $this->drives = array_values(array_map(fn ($d) => self::driveToForm((array) $d), (array) $response->json('items')));
            $this->drivesOriginal = $this->drives;
        }
        $this->done('Einstellungen gespeichert. Windows-Rechner übernehmen sie bei der nächsten Aktualisierung (etwa alle 90 Minuten oder mit gpupdate).');
        $this->settingsFor = null;
        $this->driveEditing = null;
    }

    /**
     * Switches to the tab of the first field error (and opens a drive row); true when there
     * is one.
     *
     * @param  list<array<string, mixed>>  $catalog
     */
    private function showFirstError(array $catalog): bool
    {
        $scopes = array_column($catalog, 'scope', 'key');
        foreach (array_keys($this->getErrorBag()->messages()) as $key) {
            if (preg_match('/^drives(\.(\d+))?/', $key, $m)) {
                $this->settingsTab = 'drives';
                $this->driveEditing = isset($m[2]) ? (int) $m[2] : $this->driveEditing;

                return true;
            }
            if (preg_match('/^settings\.([\w-]+)/', $key, $m) && isset($scopes[$m[1]])) {
                $this->settingsTab = $scopes[$m[1]] === 'user' ? 'user' : 'computer';
                $this->settingsSearch = '';
                $this->onlyConfigured = false;

                return true;
            }
        }

        return false;
    }

    /**
     * API value → form value: null stays "not configured" ('' or [] in the form).
     *
     * @param  array<string, mixed>  $item
     */
    public static function toForm(array $item, mixed $value): mixed
    {
        return match ($item['type']) {
            'bool' => $value === null ? '' : ($value ? '1' : '0'),
            'scripts' => array_values(array_map(fn ($s) => ['path' => (string) ($s['path'] ?? ''), 'parameters' => (string) ($s['parameters'] ?? '')], (array) ($value ?? []))),
            default => $value === null ? '' : (string) $value,
        };
    }

    /**
     * Form value → API value: empty = null (not configured).
     *
     * @param  array<string, mixed>  $item
     */
    public static function toApi(array $item, mixed $value): mixed
    {
        switch ($item['type']) {
            case 'bool':
                return $value === '' || $value === null ? null : $value === '1';
            case 'int':
                $value = trim((string) $value);

                return $value === '' ? null : (preg_match('/^-?\d+$/', $value) ? (int) $value : $value);
            case 'enum':
                foreach ((array) ($item['options'] ?? []) as $option) {
                    if ((string) $option['value'] === (string) $value) {
                        return $option['value'];
                    }
                }

                return null;
            case 'scripts':
                $list = array_values(array_filter((array) $value, fn ($s) => trim((string) ($s['path'] ?? '')) !== ''));

                return $list === [] ? null : array_map(fn ($s) => ['path' => trim((string) $s['path']), 'parameters' => (string) ($s['parameters'] ?? '')], $list);
            default:
                return trim((string) $value) === '' ? null : (string) $value;
        }
    }

    // --- inheritance -------------------------------------------------------------------

    public function blockInheritance(string $ouId, bool $blocked): void
    {
        $response = $this->appRequest(fn ($api) => $api->put('/api/ous/'.$ouId, ['block_inheritance' => ! $blocked]));
        if ($this->succeeded($response, '')) {
            $this->done($blocked ? 'Vererbung wieder zugelassen.' : 'Vererbung blockiert.');
        }
    }

    // --- loading -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function gpo(string $id): ?array
    {
        $response = $this->appRequest(fn ($api) => $api->get('/api/gpos/'.$id));

        return $this->succeeded($response, '') ? (array) $response->json() : null;
    }

    /**
     * Security groups for the item-level targeting of drive maps; a group a drive targets
     * that the list does not carry (any more) stays selectable with its stored name.
     *
     * @return list<array{value: string, label: string, hint?: string}>
     */
    private function securityGroups(): array
    {
        [$list] = $this->fetch('/api/groups', ['type' => 'security']);
        $options = [];
        foreach ((array) ($list['items'] ?? []) as $group) {
            $options[(string) $group['id']] = ['value' => (string) $group['id'], 'label' => (string) $group['name'], 'hint' => (string) ($group['ou_path'] ?? '')];
        }
        foreach ($this->drives as $drive) {
            if ($drive['group'] !== '' && ! isset($options[$drive['group']])) {
                $options[$drive['group']] = ['value' => $drive['group'], 'label' => $drive['group_name'] ?: $drive['group']];
            }
        }

        return array_values($options);
    }

    /** @return list<array<string, mixed>> */
    private function catalog(): array
    {
        [$catalog] = $this->fetch('/api/gpos/catalog');

        return array_values(array_filter((array) $catalog, fn ($i) => is_array($i) && isset($i['key'], $i['type'])));
    }

    public function render()
    {
        $ous = $this->ous();
        $gpos = [];
        $loadError = null;
        $catalog = [];
        $groupOptions = [];
        $inheritance = null;
        if (! $this->notReady) {
            [$list, $loadError] = $this->fetch('/api/gpos');
            $gpos = is_array($list) ? $list : [];
            if ($this->settingsFor !== null) {
                $catalog = $this->catalog();
                if ($this->drivesSupported) {
                    $groupOptions = $this->securityGroups();
                }
            }
            $this->target = $this->target !== '' ? $this->target : (string) ($ous[0]['id'] ?? '');
            if ($this->target !== '') {
                [$inheritance] = $this->fetch('/api/ous/'.$this->target.'/gpo-inheritance');
            }
        }

        // links per container (for the order buttons)
        $linkCounts = [];
        foreach ($gpos as $gpo) {
            foreach ((array) ($gpo['links'] ?? []) as $link) {
                $linkCounts[$link['target_id']] = ($linkCounts[$link['target_id']] ?? 0) + 1;
            }
        }
        $current = $this->linksFor !== null ? collect($gpos)->firstWhere('id', $this->linksFor) : null;
        $linked = collect((array) ($current['links'] ?? []))->pluck('target_id')->all();
        $targets = self::ouOptions($ous, true);

        return view('modules.ad.gpos', [
            'gpos' => $gpos,
            'current' => $current,
            'linkCounts' => $linkCounts,
            'linkOptions' => array_values(array_filter($targets, fn ($o) => ! in_array($o['value'], $linked, true))),
            'targetOptions' => $targets,
            'targetNode' => collect($ous)->firstWhere('id', $this->target),
            'inheritance' => $inheritance,
            'groupOptions' => $groupOptions,
            // settings dialog: what changed (footer "n Änderungen", marked rows)
            'driveChanges' => $this->settingsFor !== null ? $this->driveChanges() : [[], 0],
            'settingChanges' => count(array_filter(array_keys($this->settings), fn ($k) => ($this->settings[$k] ?? null) !== ($this->settingsOriginal[$k] ?? null))),
            // catalog grouped: scope (computer before user) → group → items
            'catalog' => collect($catalog)->groupBy('scope')->sortKeys()->map(fn ($items) => $items->groupBy(fn ($i) => $i['group'] ?? 'Allgemein')),
            'loadError' => $loadError,
        ]);
    }
}
