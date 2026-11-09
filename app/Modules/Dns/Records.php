<?php

namespace App\Modules\Dns;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 DNS: own DNS data (pk95dns docs/api.md → DNS zones and records). Zones are answered
 * authoritatively, single records only for their exact name; both apply before filtering
 * and also while protection is paused. Records Core created for deployed apps carry
 * managed_by "core".
 */
class Records extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    /** Zone filter: '' all, '0' single records only, otherwise a zone id. */
    #[Url]
    public string $zone = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $q = '';

    /** Record form: null closed, 'new' or the record id. */
    public ?string $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** Zone form: null closed, 'new' or the zone id. */
    public ?string $zoneEditing = null;

    /** @var array{name: string, ttl: string, primary_ns: string, enabled: bool} */
    public array $zoneForm = ['name' => '', 'ttl' => '300', 'primary_ns' => '', 'enabled' => true];

    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'PTR'];

    /** German short description per type (form select and help). */
    public const TYPE_LABELS = [
        'A' => 'A – IPv4-Adresse',
        'AAAA' => 'AAAA – IPv6-Adresse',
        'CNAME' => 'CNAME – Alias auf einen anderen Namen',
        'MX' => 'MX – Mailserver',
        'TXT' => 'TXT – Text',
        'SRV' => 'SRV – Dienst mit Port',
        'PTR' => 'PTR – Rückwärtsauflösung',
    ];

    /**
     * Zone names that never exist in the public DNS (RFC 6761/6762/8375 and common LAN
     * names). A zone over any other domain hides that domain's public names in the LAN.
     * Same rule as the web UI of PK95 DNS.
     */
    public const PRIVATE_SUFFIXES = ['lan', 'home', 'internal', 'local', 'localdomain', 'home.arpa', 'test', 'example', 'invalid',
        'intranet', 'corp', 'private', 'in-addr.arpa', 'ip6.arpa'];

    public const DEFAULT_TTL = 300;

    public function mount(): void
    {
        $this->form = self::emptyForm();
        if (! in_array($this->type, self::TYPES, true)) {
            $this->type = '';
        }
        if ($this->zone !== '' && ! ctype_digit($this->zone)) {
            $this->zone = '';
        }
    }

    public static function isPublicZone(string $name): bool
    {
        $name = rtrim(strtolower(trim($name)), '.');
        if ($name === '') {
            return false;
        }
        foreach (self::PRIVATE_SUFFIXES as $suffix) {
            if ($name === $suffix || str_ends_with($name, '.'.$suffix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyForm(string $type = 'A'): array
    {
        return ['name' => '', 'type' => $type, 'value' => '', 'ttl' => (string) self::DEFAULT_TTL, 'priority' => '10', 'weight' => '0',
            'port' => '', 'ptr' => false, 'enabled' => true, 'managed_by' => ''];
    }

    public function resetFilters(): void
    {
        $this->zone = '';
        $this->type = '';
        $this->q = '';
    }

    /** Shows the records of one zone ('0' = single records). */
    public function showZone(string $id): void
    {
        $this->zone = $id;
    }

    // ---------------------------------------------------------------- records

    public function create(): void
    {
        $this->resetErrorBag();
        $this->zoneEditing = null;
        $this->form = self::emptyForm(in_array($this->type, self::TYPES, true) ? $this->type : 'A');
        $this->editing = 'new';
    }

    public function edit(int $id): void
    {
        $r = $this->findRecord($id);
        if ($r === null) {
            return;
        }
        $this->resetErrorBag();
        $this->zoneEditing = null;
        $type = (string) $r['type'];
        $this->form = [
            'name' => (string) $r['name'], 'type' => $type, 'value' => (string) $r['value'], 'ttl' => (string) (int) $r['ttl'],
            'priority' => (string) (int) ($r['priority'] ?? 0), 'weight' => (string) (int) ($r['weight'] ?? 0),
            'port' => in_array($type, ['SRV'], true) ? (string) (int) ($r['port'] ?? 0) : '',
            'ptr' => (bool) ($r['ptr'] ?? false), 'enabled' => (bool) $r['enabled'], 'managed_by' => (string) ($r['managed_by'] ?? ''),
        ];
        $this->editing = (string) $id;
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->zoneEditing = null;
    }

    public function save(): void
    {
        $body = $this->recordBody();
        if ($body === null) {
            return;
        }
        $new = $this->editing === 'new';
        $response = $this->appRequest(fn ($api) => $new ? $api->post('/api/records', $body) : $api->put('/api/records/'.(int) $this->editing, $body));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: 'Gespeichert.');
            $this->editing = null;
        }
    }

    public function toggle(int $id): void
    {
        $r = $this->findRecord($id);
        if ($r !== null) {
            $this->succeeded($this->appRequest(fn ($api) => $api->put('/api/records/'.$id, array_merge(self::recordFields($r), ['enabled' => ! $r['enabled']]))));
        }
    }

    public function delete(int $id): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->delete('/api/records/'.$id)))) {
            $this->dispatch('notice', kind: 'success', text: 'Eintrag gelöscht.');
            if ($this->editing === (string) $id) {
                $this->editing = null;
            }
        }
    }

    /**
     * The request body from the form, only with the fields of the chosen type (the others
     * go as 0/false, like the app stores them). Null when a number field is not a number.
     *
     * @return array<string, mixed>|null
     */
    private function recordBody(): ?array
    {
        $this->resetErrorBag();
        $type = in_array($this->form['type'] ?? '', self::TYPES, true) ? $this->form['type'] : 'A';
        $numbers = ['ttl' => (string) self::DEFAULT_TTL];
        if (in_array($type, ['MX', 'SRV'], true)) {
            $numbers['priority'] = '0';
        }
        if ($type === 'SRV') {
            $numbers += ['weight' => '0', 'port' => ''];
        }
        $values = ['priority' => 0, 'weight' => 0, 'port' => 0];
        foreach ($numbers as $field => $default) {
            $raw = trim((string) ($this->form[$field] ?? ''));
            $raw = $raw === '' ? $default : $raw;
            if (! ctype_digit($raw)) {
                $this->addError('form.'.$field, 'Bitte eine ganze Zahl angeben.');

                continue;
            }
            $values[$field] = (int) $raw;
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return null;
        }
        $value = (string) ($this->form['value'] ?? '');

        return [
            'name' => strtolower(trim((string) $this->form['name'])),
            'type' => $type,
            'value' => $type === 'TXT' ? trim($value, "\r\n") : trim($value),
            'ttl' => $values['ttl'],
            'priority' => $values['priority'],
            'weight' => $values['weight'],
            'port' => $values['port'],
            'ptr' => in_array($type, ['A', 'AAAA'], true) && ! empty($this->form['ptr']),
            'enabled' => (bool) ($this->form['enabled'] ?? true),
            'managed_by' => (string) ($this->form['managed_by'] ?? ''),
        ];
    }

    /**
     * The writable fields of a record as the app sent it.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private static function recordFields(array $r): array
    {
        return ['name' => (string) $r['name'], 'type' => (string) $r['type'], 'value' => (string) $r['value'], 'ttl' => (int) $r['ttl'],
            'priority' => (int) ($r['priority'] ?? 0), 'weight' => (int) ($r['weight'] ?? 0), 'port' => (int) ($r['port'] ?? 0),
            'ptr' => (bool) ($r['ptr'] ?? false), 'enabled' => (bool) $r['enabled'], 'managed_by' => (string) ($r['managed_by'] ?? '')];
    }

    // ---------------------------------------------------------------- zones

    public function createZone(): void
    {
        $this->resetErrorBag();
        $this->editing = null;
        $this->zoneForm = ['name' => '', 'ttl' => (string) self::DEFAULT_TTL, 'primary_ns' => '', 'enabled' => true];
        $this->zoneEditing = 'new';
    }

    public function editZone(int $id): void
    {
        $z = $this->findZone($id);
        if ($z === null) {
            return;
        }
        $this->resetErrorBag();
        $this->editing = null;
        $this->zoneForm = ['name' => (string) $z['name'], 'ttl' => (string) (int) $z['ttl'], 'primary_ns' => (string) $z['primary_ns'], 'enabled' => (bool) $z['enabled']];
        $this->zoneEditing = (string) $id;
    }

    public function saveZone(): void
    {
        $this->resetErrorBag();
        $ttl = trim((string) $this->zoneForm['ttl']);
        $ttl = $ttl === '' ? (string) self::DEFAULT_TTL : $ttl;
        if (! ctype_digit($ttl)) {
            $this->addError('zoneForm.ttl', 'Bitte eine ganze Zahl angeben.');

            return;
        }
        $body = ['name' => rtrim(strtolower(trim($this->zoneForm['name'])), '.'), 'ttl' => (int) $ttl,
            'primary_ns' => rtrim(strtolower(trim($this->zoneForm['primary_ns'])), '.'), 'enabled' => (bool) $this->zoneForm['enabled']];
        $new = $this->zoneEditing === 'new';
        $response = $this->appRequest(fn ($api) => $new ? $api->post('/api/zones', $body) : $api->put('/api/zones/'.(int) $this->zoneEditing, $body));
        if ($this->succeeded($response, 'zoneForm.')) {
            $moved = $new ? (int) $response->json('record_count') : 0;
            $this->dispatch('notice', kind: 'success', text: $moved > 0
                ? 'Zone angelegt. '.Fmt::plural($moved, 'vorhandener Eintrag gehört', 'vorhandene Einträge gehören').' jetzt zu ihr.'
                : 'Gespeichert.');
            $this->zoneEditing = null;
        }
    }

    public function toggleZone(int $id): void
    {
        $z = $this->findZone($id);
        if ($z !== null) {
            $this->succeeded($this->appRequest(fn ($api) => $api->put('/api/zones/'.$id, ['name' => (string) $z['name'], 'ttl' => (int) $z['ttl'],
                'primary_ns' => (string) $z['primary_ns'], 'enabled' => ! $z['enabled']])), 'zoneForm.');
        }
    }

    public function deleteZone(int $id): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->delete('/api/zones/'.$id)), 'zoneForm.')) {
            $this->dispatch('notice', kind: 'success', text: 'Zone entfernt.');
            if ($this->zone === (string) $id) {
                $this->zone = '';
            }
            if ($this->zoneEditing === (string) $id) {
                $this->zoneEditing = null;
            }
        }
    }

    // ---------------------------------------------------------------- render

    public function render()
    {
        [$zones, $zonesError] = $this->load('/api/zones');
        $zones = is_array($zones) ? array_values($zones) : [];
        [$rows, $rowsError] = $this->load('/api/records', $this->query());

        return view('modules.dns.records', [
            'zones' => $zones,
            'zoneNames' => array_column($zones, 'name', 'id'),
            'rows' => is_array($rows) ? array_values($rows) : [],
            'filtered' => $this->zone !== '' || $this->type !== '' || trim($this->q) !== '',
            'loadError' => $zonesError ?? $rowsError,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function query(): array
    {
        return array_filter(['zone_id' => $this->zone, 'type' => in_array($this->type, self::TYPES, true) ? $this->type : '', 'q' => trim($this->q)],
            fn ($v) => $v !== '');
    }

    /**
     * A record by id: first among the shown ones (filters), else among all.
     *
     * @return array<string, mixed>|null
     */
    private function findRecord(int $id): ?array
    {
        $queries = $this->query() === [] ? [[]] : [$this->query(), []];
        foreach ($queries as $query) {
            $response = $this->appRequest(fn ($api) => $api->get('/api/records', $query));
            if (! $this->succeeded($response)) {
                return null;
            }
            foreach ((array) $response->json() as $r) {
                if ((int) ($r['id'] ?? 0) === $id) {
                    return $r;
                }
            }
        }
        $this->dispatch('notice', kind: 'error', text: 'Den Eintrag gibt es nicht mehr.');

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findZone(int $id): ?array
    {
        $response = $this->appRequest(fn ($api) => $api->get('/api/zones'));
        if (! $this->succeeded($response, 'zoneForm.')) {
            return null;
        }
        foreach ((array) $response->json() as $z) {
            if ((int) ($z['id'] ?? 0) === $id) {
                return $z;
            }
        }
        $this->dispatch('notice', kind: 'error', text: 'Die Zone gibt es nicht mehr.');

        return null;
    }
}
