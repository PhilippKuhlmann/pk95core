<?php

namespace App\Modules\Ad;

use App\Modules\Ad\Concerns\AdPage;
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
        $this->settingsFor = $id;
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
        $body = [];
        foreach ($this->catalog() as $item) {
            $key = $item['key'];
            $value = $this->settings[$key] ?? null;
            if ($value !== ($this->settingsOriginal[$key] ?? null)) {
                $body[$key] = self::toApi($item, $value);
            }
        }
        if ($body === []) {
            $this->settingsFor = null;

            return;
        }
        $response = $this->appRequest(fn ($api) => $api->put('/api/gpos/'.$this->settingsFor.'/settings', ['settings' => $body]));
        if ($this->succeeded($response, '')) {
            $this->done('Einstellungen gespeichert. Windows-Rechner übernehmen sie bei der nächsten Aktualisierung (etwa alle 90 Minuten oder mit gpupdate).');
            $this->settingsFor = null;
        }
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
        $inheritance = null;
        if (! $this->notReady) {
            [$list, $loadError] = $this->fetch('/api/gpos');
            $gpos = is_array($list) ? $list : [];
            if ($this->settingsFor !== null) {
                $catalog = $this->catalog();
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
            // catalog grouped: scope (computer before user) → group → items
            'catalog' => collect($catalog)->groupBy('scope')->sortKeys()->map(fn ($items) => $items->groupBy(fn ($i) => $i['group'] ?? 'Allgemein')),
            'loadError' => $loadError,
        ]);
    }
}
