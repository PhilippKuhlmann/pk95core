<?php

namespace App\Modules\Ad;

use App\Modules\Ad\Concerns\AdPage;
use App\Modules\Ad\Concerns\EditsPreferences;
use App\Modules\Ad\Gpp\Kinds;
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
    use EditsPreferences;

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

    /** tab of the settings dialog: computer | user (the preferences lists are sections of their tab) */
    public string $settingsTab = 'computer';

    /** filter of the settings dialog: label or help contains it */
    public string $settingsSearch = '';

    /** filter of the settings dialog: only configured settings */
    public bool $onlyConfigured = false;

    /**
     * „Als Netzlaufwerk verteilen“ from PK95 File: a UNC path (?laufwerk=\\files.ad.example.com\Projekte).
     * The GPO the user opens next gets a new drive row with it on the user tab.
     */
    #[Url(as: 'laufwerk', except: '')]
    public string $driveMap = '';

    public const STATUS = [
        'enabled' => 'Aktiv',
        'user_disabled' => 'Benutzerkonfiguration aus',
        'computer_disabled' => 'Computerkonfiguration aus',
        'disabled' => 'Aus',
    ];

    public const SCOPES = ['computer' => 'Computerkonfiguration', 'user' => 'Benutzerkonfiguration'];

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
        $this->gppEditing = null;
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
        $this->loadPreferences($id);
        $this->settingsSearch = '';
        $this->onlyConfigured = false;
        // start where something is configured: computer, else user (settings or preferences lists)
        $this->settingsTab = $this->configuredCount('computer') + $this->gppRowCount('computer') === 0
            && $this->configuredCount('user') + $this->gppRowCount('user') > 0 ? 'user' : 'computer';
        $this->settingsFor = $id;
        $this->addSharedDrive();
    }

    /** the drive map PK95 File asked for: a new row with the share's path, its editor open */
    private function addSharedDrive(): void
    {
        $path = trim($this->driveMap);
        if ($path === '') {
            return;
        }
        if (empty($this->gppSupported['drives'])) {
            $this->dispatch('notice', kind: 'error', text: 'Netzlaufwerke per Gruppenrichtlinie gibt es ab PK95 AD 1.1.0.');

            return;
        }
        $this->driveMap = '';
        $this->settingsTab = 'user';
        $this->settingsSearch = '';
        $this->onlyConfigured = false;
        $this->addGpp('drives');
        $index = $this->gppEditingIndex('drives');
        if ($index !== null) {
            $this->gpp['drives'][$index]['path'] = $path;
            // the share name as label: \\files.ad.example.com\Projekte → Projekte
            $this->gpp['drives'][$index]['label'] = rtrim((string) preg_replace('/^.*\\\\/', '', $path), '$');
        }
    }

    /** "Abbrechen" on the hint: no drive row */
    public function forgetDriveMap(): void
    {
        $this->driveMap = '';
    }

    public function updatedSettingsTab(string $tab): void
    {
        if (! in_array($tab, ['computer', 'user'], true)) {
            $this->settingsTab = 'computer';
        }
    }

    /** back to the values as loaded (settings and preferences lists) */
    public function discardSettings(): void
    {
        $this->settings = $this->settingsOriginal;
        $this->discardPreferences();
        $this->resetErrorBag();
    }

    public const EDITIONS = ['home' => 'Home', 'pro' => 'Pro', 'enterprise' => 'Enterprise', 'education' => 'Education'];

    /**
     * The Windows editions a setting or enum option works on (PK95 AD 1.2.0): "ab Pro" for every
     * edition but Home, else "nur Enterprise/Education"; null when the field is missing or names
     * every edition.
     */
    public static function editionsLabel(mixed $editions): ?string
    {
        $names = array_intersect_key(self::EDITIONS, array_flip(array_map('strval', (array) $editions)));
        if (! $names || count($names) === count(self::EDITIONS)) {
            return null;
        }

        return array_keys($names) === ['pro', 'enterprise', 'education'] ? 'ab Pro' : 'nur '.implode('/', $names);
    }

    /** an enum option's text with its editions, unless the label already says so */
    public static function optionLabel(array $option): string
    {
        $label = (string) $option['label'];
        $only = self::editionsLabel($option['editions'] ?? null);

        return $only === null || str_contains($label, $only) ? $label : $label.' ('.$only.')';
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

    /** item fields of 422 answers of a preferences list ("items.2.target.group_id") → the form ("gpp.drives.2.group") */
    protected function mapField(string $field): string
    {
        return $this->mapPreferenceField($field) ?? $field;
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
        if ($body === [] && $this->gppChangeCount() === 0) {
            $this->settingsFor = null;

            return;
        }
        $this->checkPreferences();
        if ($this->showFirstError($catalog)) {
            return;
        }
        if ($body !== []) {
            $response = $this->appRequest(fn ($api) => $api->put('/api/gpos/'.$this->settingsFor.'/settings', ['settings' => $body]));
            if (! $this->succeeded($response, '')) {
                $this->showFirstError($catalog);

                return;
            }
            $this->settingsOriginal = $this->settings;
        }
        if (! $this->savePreferences()) {
            $this->showFirstError($catalog);

            return;
        }
        $this->done('Einstellungen gespeichert. Windows-Rechner übernehmen sie bei der nächsten Aktualisierung (etwa alle 90 Minuten oder mit gpupdate).');
        $this->settingsFor = null;
        $this->gppEditing = null;
    }

    /**
     * Switches to the tab of the first field error (and opens a preferences row); true when
     * there is one.
     *
     * @param  list<array<string, mixed>>  $catalog
     */
    private function showFirstError(array $catalog): bool
    {
        $scopes = array_column($catalog, 'scope', 'key');
        foreach (array_keys($this->getErrorBag()->messages()) as $key) {
            if (preg_match('/^gpp\.(\w+)(\.(\d+))?/', $key, $m) && ($kind = Kinds::get($m[1])) !== null) {
                // a section of its tab; the filter must not hide it
                $this->settingsTab = $kind->scope();
                $this->settingsSearch = '';
                $this->onlyConfigured = false;
                $this->gppEditing = isset($m[3]) ? $m[1].'.'.$m[3] : $this->gppEditing;

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
     * Security groups for the item-level targeting of the preferences lists; a group a row
     * targets that the list does not carry (any more) stays selectable with its stored name.
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
        foreach ($this->preferenceGroups() as $id => $name) {
            $options[$id] ??= ['value' => $id, 'label' => $name];
        }

        return array_values($options);
    }

    /**
     * Members for local groups: users and security groups of the domain (no local accounts); a
     * member a row names that the lists do not carry stays selectable under "Andere".
     *
     * @return list<array{value: string, label: string, hint?: string, group: string}>
     */
    private function memberOptions(): array
    {
        $options = [];
        [$users] = $this->fetch('/api/users');
        foreach ((array) ($users['items'] ?? []) as $user) {
            $label = (string) (($user['display_name'] ?? '') ?: ($user['username'] ?? ''));
            $options[(string) $user['id']] = ['value' => (string) $user['id'], 'label' => $label, 'hint' => (string) ($user['username'] ?? ''), 'group' => 'Benutzer'];
        }
        [$groups] = $this->fetch('/api/groups', ['type' => 'security']);
        foreach ((array) ($groups['items'] ?? []) as $group) {
            $options[(string) $group['id']] = ['value' => (string) $group['id'], 'label' => (string) $group['name'], 'hint' => (string) ($group['ou_path'] ?? ''), 'group' => 'Gruppen'];
        }
        foreach ($this->gpp['local_groups'] ?? [] as $row) {
            foreach ((array) $row['names'] as $id => $name) {
                $options[(string) $id] ??= ['value' => (string) $id, 'label' => $name !== '' ? $name : (string) $id, 'group' => 'Andere'];
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
        $memberOptions = [];
        $inheritance = null;
        if (! $this->notReady) {
            [$list, $loadError] = $this->fetch('/api/gpos');
            $gpos = is_array($list) ? $list : [];
            if ($this->settingsFor !== null) {
                $catalog = $this->catalog();
                if (in_array(true, $this->gppSupported, true)) {
                    $groupOptions = $this->securityGroups();
                }
                if (! empty($this->gppSupported['local_groups'])) {
                    $memberOptions = $this->memberOptions();
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
            'memberOptions' => $memberOptions,
            // settings dialog: what changed (footer "n Änderungen", marked rows)
            'gppChangeCount' => $this->settingsFor !== null ? $this->gppChangeCount() : 0,
            'settingChanges' => count(array_filter(array_keys($this->settings), fn ($k) => ($this->settings[$k] ?? null) !== ($this->settingsOriginal[$k] ?? null))),
            // catalog grouped: scope (computer before user) → group → items
            'catalog' => collect($catalog)->groupBy('scope')->sortKeys()->map(fn ($items) => $items->groupBy(fn ($i) => $i['group'] ?? 'Allgemein')),
            'loadError' => $loadError,
        ]);
    }
}
