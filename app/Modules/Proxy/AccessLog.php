<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 Proxy: live access log with filters (host, status, IP, country, path, bots) and
 * details per request. Live mode reloads the newest entries every two seconds.
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

    public bool $live = true;

    /** Number of entries shown ("Ältere laden" adds 100, the panel answers at most 500). */
    public int $limit = 100;

    /** Highest id seen before the last refresh: newer rows are highlighted. */
    public int $seen = 0;

    /** @var list<int> rows whose details are open */
    public array $open = [];

    public const STATUSES = ['' => 'Alle Status', '2xx' => '2xx Erfolg', '3xx' => '3xx Weiterleitung', '4xx' => '4xx Client-Fehler', '5xx' => '5xx Server-Fehler'];

    public function updated(string $property): void
    {
        if (in_array($property, ['host', 'status', 'ip', 'country', 'q', 'hideBots'], true)) {
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

    public function render()
    {
        $query = array_filter([
            'host' => $this->host, 'status' => $this->status, 'ip' => trim($this->ip), 'country' => $this->country,
            'q' => $this->q, 'hide_bots' => $this->hideBots ? '1' : null, 'limit' => $this->limit,
        ], fn ($v) => $v !== '' && $v !== null);
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
        ]);
    }
}
