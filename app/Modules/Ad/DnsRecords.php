<?php

namespace App\Modules\Ad;

use App\Modules\Ad\Concerns\AdPage;
use App\Modules\Concerns\LoadsLazily;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 AD → DNS: the records of the AD zones (Samba's internal DNS). System records the DC
 * needs (SOA, NS, its A record, the SRV records, everything in _msdcs) are read-only (403);
 * editing a dynamic record makes it static. The record id changes with its content.
 */
class DnsRecords extends Component
{
    use AdPage;
    use LoadsLazily;

    public const PLACEHOLDER = [['header', 'DNS der Domäne', 1, 'sub' => 1], ['filters', 3], ['table', 8, 4]];

    #[Url]
    public string $zone = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $q = '';

    /** null closed, 'new' or the record id */
    public ?string $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'PTR'];

    public const TYPE_LABELS = [
        'A' => 'A – IPv4-Adresse',
        'AAAA' => 'AAAA – IPv6-Adresse',
        'CNAME' => 'CNAME – Alias auf einen anderen Namen',
        'MX' => 'MX – Mailserver',
        'TXT' => 'TXT – Text',
        'SRV' => 'SRV – Dienst mit Port',
        'PTR' => 'PTR – Rückwärtsauflösung',
    ];

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = ['name' => '', 'type' => 'A', 'value' => '', 'ttl' => '900', 'priority' => '10', 'weight' => '0', 'port' => '', 'dynamic' => false];
        $this->editing = 'new';
    }

    public function edit(string $id): void
    {
        $this->resetErrorBag();
        [$records] = $this->fetch('/api/dns/zones/'.$this->zone.'/records');
        $r = collect((array) $records)->firstWhere('id', $id);
        if (! is_array($r)) {
            $this->dispatch('notice', kind: 'error', text: 'Den Eintrag gibt es nicht mehr.');

            return;
        }
        $this->form = [
            'name' => (string) $r['name'], 'type' => (string) $r['type'], 'value' => (string) $r['value'], 'ttl' => (string) $r['ttl'],
            'priority' => (string) ($r['priority'] ?? 0), 'weight' => (string) ($r['weight'] ?? 0), 'port' => (string) ($r['port'] ?? 0),
            'dynamic' => (bool) ($r['dynamic'] ?? false),
        ];
        $this->editing = $id;
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $type = $this->form['type'];
        $body = ['name' => trim((string) $this->form['name']), 'type' => $type, 'value' => trim((string) $this->form['value']), 'ttl' => self::int($this->form['ttl'])];
        if (in_array($type, ['MX', 'SRV'], true)) {
            $body['priority'] = self::int($this->form['priority']);
        }
        if ($type === 'SRV') {
            $body['weight'] = self::int($this->form['weight']);
            $body['port'] = self::int($this->form['port']);
        }
        $path = '/api/dns/zones/'.$this->zone.'/records';
        $response = $this->appRequest(fn ($api) => $this->editing === 'new' ? $api->post($path, $body) : $api->put($path.'/'.$this->editing, $body));
        if ($this->succeeded($response)) {
            $this->done($this->editing === 'new' ? 'Eintrag angelegt.' : 'Eintrag gespeichert.');
            $this->editing = null;
        }
    }

    public function delete(string $id): void
    {
        $response = $this->appRequest(fn ($api) => $api->delete('/api/dns/zones/'.$this->zone.'/records/'.$id));
        if ($this->succeeded($response, '')) {
            $this->done('Eintrag gelöscht.');
        }
    }

    /** numbers go out as numbers; anything else as it is, the app answers 422 */
    private static function int(mixed $value): int|string
    {
        $value = trim((string) $value);

        return preg_match('/^\d+$/', $value) ? (int) $value : $value;
    }

    public function render()
    {
        $zones = [];
        $records = [];
        [$list, $loadError] = $this->fetch('/api/dns/zones');
        if (is_array($list)) {
            $zones = $list;
            if (! in_array($this->zone, array_column($zones, 'name'), true)) {
                // default: the realm's forward zone (not _msdcs, not reverse)
                $first = collect($zones)->first(fn ($z) => empty($z['system']) && empty($z['reverse'])) ?? ($zones[0] ?? null);
                $this->zone = (string) ($first['name'] ?? '');
            }
            if ($this->zone !== '') {
                [$r, $loadError] = $this->fetch('/api/dns/zones/'.$this->zone.'/records', array_filter(['type' => $this->type, 'q' => trim($this->q)]));
                $records = is_array($r) ? $r : [];
            }
        }
        $current = collect($zones)->firstWhere('name', $this->zone);

        return view('modules.ad.dns', [
            'zones' => $zones,
            'current' => $current,
            'rows' => $records,
            'types' => array_values(array_filter(self::TYPES, fn ($t) => $t !== 'PTR' || ($current['reverse'] ?? false))),
            'loadError' => $loadError,
        ]);
    }
}
