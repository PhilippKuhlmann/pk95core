<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use App\Services\Apps\AppException;
use App\Support\Settings;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 Proxy: live access log with filters (host, status, IP, country, path, bots, clients)
 * and details per request. Live mode reloads the newest entries every two seconds.
 *
 * Clients (e.g. "Uptime-Monitor") can be hidden or shown alone. The panel filters them
 * (exclude_client / client on /api/requests), never this page: filtering loaded rows here
 * would break the paging. PK95 Proxy before 1.3.0 does not know the parameters (and ignores
 * them), so the filter is only offered when GET /api/requests/clients answers. The panel takes
 * at most 100 exclude_client values and also splits them at commas; Core sends at most
 * MAX_HIDDEN and refuses names with a comma.
 */
class AccessLog extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    #[Url]
    public string $host = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $ip = '';

    #[Url]
    public string $country = '';

    #[Url]
    public string $q = '';

    #[Url(as: 'hide_bots')]
    public bool $hideBots = false;

    /** Only this client (exact name). */
    #[Url]
    public string $client = '';

    /** @var list<string> hidden clients; also kept in the setting HIDDEN_SETTING */
    #[Url(as: 'hide')]
    public array $hidden = [];

    public bool $live = true;

    /** Number of entries shown ("Ältere laden" adds 100, the panel answers at most 500). */
    public int $limit = 100;

    /** Highest id seen before the last refresh: newer rows are highlighted. */
    public int $seen = 0;

    /** @var list<int> rows whose details are open */
    public array $open = [];

    /** null = not asked yet; false = this Proxy has no client filter (older version). */
    #[Locked]
    public ?bool $clientFilter = null;

    /** @var list<array{client: string, count: int}> clients of the last 24 h, most first */
    #[Locked]
    public array $clients = [];

    /** For which host/bot filter and minute the client list was loaded (reloaded every 60 s). */
    #[Locked]
    public string $clientsKey = '';

    public const STATUSES = ['' => 'Alle Status', '2xx' => '2xx Erfolg', '3xx' => '3xx Weiterleitung', '4xx' => '4xx Client-Fehler', '5xx' => '5xx Server-Fehler'];

    public const HIDDEN_SETTING = 'proxy_log_hidden_clients';

    public const MAX_HIDDEN = 20;

    public function mount(): void
    {
        // the URL wins (a shared link); without it the last choice comes back
        if (! request()->query->has('hide')) {
            $this->hidden = self::clean((array) Settings::get(self::HIDDEN_SETTING));
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['host', 'status', 'ip', 'country', 'q', 'hideBots', 'client', 'hidden'], true)) {
            $this->limit = 100;
            $this->open = [];
        }
    }

    public function toggleLive(): void
    {
        $this->live = ! $this->live;
    }

    public function older(): void
    {
        $this->limit = min(500, $this->limit + 100);
    }

    public function toggle(int $id): void
    {
        $this->open = in_array($id, $this->open, true) ? array_values(array_diff($this->open, [$id])) : [...$this->open, $id];
    }

    public function filterBy(string $key, string $value): void
    {
        if (in_array($key, ['ip', 'host'], true)) {
            $this->{$key} = $value;
            $this->updated($key);
        }
    }

    public function clearCountry(): void
    {
        $this->country = '';
        $this->updated('country');
    }

    public function hideClient(string $name): void
    {
        $name = self::name($name);
        if ($name === '' || in_array($name, $this->hidden, true)) {
            return;
        }
        // the panel splits exclude_client at commas too: such a name would never match
        if (str_contains($name, ',')) {
            $this->dispatch('notice', kind: 'error', text: "„{$name}“ enthält ein Komma und lässt sich deshalb nicht ausblenden.");

            return;
        }
        if (count($this->hidden) >= self::MAX_HIDDEN) {
            $this->dispatch('notice', kind: 'error', text: 'Höchstens '.self::MAX_HIDDEN.' Clients lassen sich ausblenden.');

            return;
        }
        $this->setHidden([...$this->hidden, $name]);
        if ($this->client === $name) {
            $this->client = '';
        }
    }

    /** The multi-select (wire:model.live="hidden") changed the list: same rules as hideClient(). */
    public function updatedHidden(): void
    {
        $names = self::clean((array) $this->hidden);
        $comma = array_values(array_filter($names, fn ($n) => str_contains($n, ',')));
        if ($comma) {
            $this->dispatch('notice', kind: 'error', text: "„{$comma[0]}“ enthält ein Komma und lässt sich deshalb nicht ausblenden.");
        }
        if (count((array) $this->hidden) > self::MAX_HIDDEN) {
            $this->dispatch('notice', kind: 'error', text: 'Höchstens '.self::MAX_HIDDEN.' Clients lassen sich ausblenden.');
        }
        $this->setHidden(array_values(array_diff($names, $comma)));
        if (in_array($this->client, $this->hidden, true)) {
            $this->client = '';
        }
    }

    public function showClient(string $name): void
    {
        $this->setHidden(array_values(array_diff($this->hidden, [$name])));
    }

    public function showAllClients(): void
    {
        $this->setHidden([]);
    }

    public function onlyClient(string $name): void
    {
        $this->client = self::name($name);
        $this->updated('client');
    }

    public function clearClient(): void
    {
        $this->client = '';
        $this->updated('client');
    }

    public function render()
    {
        $this->loadClients();
        $filter = $this->clientFilter === true;

        $query = http_build_query(array_filter([
            'host' => $this->host, 'status' => $this->status, 'ip' => trim($this->ip), 'country' => $this->country,
            'q' => $this->q, 'hide_bots' => $this->hideBots ? '1' : null, 'limit' => $this->limit,
            'client' => $filter ? $this->client : null,
        ], fn ($v) => $v !== '' && $v !== null), '', '&', PHP_QUERY_RFC3986);
        // repeated key, not "a,b": client names may contain commas
        foreach ($filter ? $this->hidden : [] as $name) {
            $query .= ($query === '' ? '' : '&').'exclude_client='.rawurlencode($name);
        }
        [$rows, $error] = $this->load('/api/requests', $query);
        $rows = is_array($rows) ? $rows : [];
        [$hosts] = $this->load('/api/hosts');
        $domains = collect(is_array($hosts) ? $hosts : [])->flatMap(fn ($h) => $h['domains'])->unique()->sort()->values()->all();
        if ($this->host !== '' && ! in_array($this->host, $domains, true)) {
            array_unshift($domains, $this->host);
        }

        $seen = $this->seen;
        $this->seen = max($seen, (int) ($rows[0]['id'] ?? 0));
        $statuses = self::STATUSES;
        if ($this->status !== '' && ! isset($statuses[$this->status])) {
            $statuses[$this->status] = 'Status '.$this->status;
        }

        return view('modules.proxy.log', [
            'rows' => $rows,
            'fresh' => $seen,
            'domains' => $domains,
            'statuses' => $statuses,
            'more' => count($rows) >= $this->limit && $this->limit < 500,
            'loadError' => $error,
            'clientFilter' => $filter,
            // names to pick: the panel's list, plus the clients of the rows on screen
            'clientNames' => collect($this->clients)->pluck('client')
                ->merge(collect($rows)->pluck('client'))->map(fn ($c) => self::name((string) $c))->filter()->unique()
                ->reject(fn ($c) => in_array($c, $this->hidden, true))->values()->all(),
            'clientCounts' => $counts = collect($this->clients)->mapWithKeys(fn ($c) => [$c['client'] => $c['count']])->all(),
            // the multi-select also lists the hidden ones (to show them again)
            'clientOptions' => collect($this->clients)->pluck('client')->merge(collect($rows)->pluck('client'))->merge($this->hidden)
                ->map(fn ($c) => self::name((string) $c))->filter()->unique()->values()
                ->map(fn ($c) => ['value' => $c, 'label' => $c, 'hint' => isset($counts[$c]) ? Fmt::num($counts[$c]) : null])->all(),
        ]);
    }

    // ------------------------------------------------------------------------------------

    /** @param list<string> $names */
    private function setHidden(array $names): void
    {
        $this->hidden = self::clean($names);
        Settings::put(self::HIDDEN_SETTING, $this->hidden);
        $this->updated('hidden');
    }

    /**
     * The panel's client list (last 24 h) also tells whether it can filter by client at all:
     * 404 = an older PK95 Proxy. Reloaded every 60 s or when host or bot filter change.
     */
    private function loadClients(): void
    {
        $key = $this->host.'|'.($this->hideBots ? '1' : '0').'|'.intdiv(now()->getTimestamp(), 60);
        if ($this->clientFilter !== null && $this->clientsKey === $key) {
            return;
        }
        $this->clientsKey = $key;
        try {
            $response = $this->installedApp->client()->get('/api/requests/clients', array_filter([
                'range' => '24h', 'host' => $this->host, 'hide_bots' => $this->hideBots ? '1' : null,
            ], fn ($v) => $v !== '' && $v !== null));
        } catch (AppException) {
            $this->clientFilter ??= false;

            return;
        }
        if ($response->status() === 404) {
            [$this->clientFilter, $this->clients] = [false, []];

            return;
        }
        if (! $response->successful()) {
            $this->clientFilter ??= false; // unknown: keep what we knew

            return;
        }
        $this->clientFilter = true;
        // {range, from, to, computed_at, clients: [{client, count}]} (PK95 Proxy 1.3.0); a bare list is fine too
        $json = (array) $response->json();
        $this->clients = collect((array) ($json['clients'] ?? (array_is_list($json) ? $json : [])))
            ->filter(fn ($c) => is_array($c) && self::name((string) ($c['client'] ?? '')) !== '')
            ->map(fn ($c) => ['client' => self::name((string) $c['client']), 'count' => (int) ($c['count'] ?? 0)])
            ->take(100)->values()->all();
    }

    private static function name(string $name): string
    {
        return mb_substr(trim($name), 0, 200);
    }

    /**
     * @param  array<mixed>  $names
     * @return list<string>
     */
    private static function clean(array $names): array
    {
        return collect($names)->map(fn ($n) => is_string($n) ? self::name($n) : '')->filter()->unique()
            ->take(self::MAX_HIDDEN)->values()->all();
    }
}
