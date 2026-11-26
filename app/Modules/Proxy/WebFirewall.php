<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 Proxy: the web firewall (Coraza + OWASP CRS, PK95 Proxy 1.8.0). Figures of hits and
 * blocks, the log of hits with filters and, per rule of a hit, „Ausnahme anlegen“ for that
 * host and path.
 */
class WebFirewall extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    /** period of the figures: 1, 7 or 30 days */
    #[Url(except: '1')]
    public string $days = '1';

    /** filters of the log, named as on /api/waf/events (host = host id) */
    #[Url(except: '')]
    public string $host = '';

    #[Url(except: '')]
    public string $rule = '';

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $ip = '';

    #[Url(except: '')]
    public string $country = '';

    #[Url(except: '')]
    public string $q = '';

    public int $limit = 100;

    /** @var array<string, mixed>|null the exception being created from a hit */
    public ?array $exception = null;

    public const DAYS = ['1' => '24 Stunden', '7' => '7 Tage', '30' => '30 Tage'];

    public const FILTERS = ['host', 'rule', 'action', 'ip', 'country', 'q'];

    public function mount(): void
    {
        if (! isset(self::DAYS[$this->days])) {
            $this->days = '1';
        }
        if (! isset(Waf::ACTIONS[$this->action])) {
            $this->action = '';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->limit = 100;
        }
    }

    public function period(string $days): void
    {
        $this->days = isset(self::DAYS[$days]) ? $days : '1';
    }

    public function filterBy(string $key, string $value): void
    {
        if (in_array($key, self::FILTERS, true)) {
            $this->{$key} = $value;
            $this->limit = 100;
        }
    }

    public function clearFilters(): void
    {
        foreach (self::FILTERS as $key) {
            $this->{$key} = '';
        }
        $this->limit = 100;
    }

    public function older(): void
    {
        $this->limit = min(500, $this->limit + 100);
    }

    /** Opens the dialog for an exception: this rule, on this host, under the path of the hit. */
    public function newException(int $hostId, string $domain, string $ruleId, string $path): void
    {
        $this->resetErrorBag();
        $this->exception = ['host_id' => $hostId, 'domain' => $domain, 'rule_id' => $ruleId, 'path_prefix' => Waf::pathPrefix($path), 'note' => ''];
    }

    public function cancel(): void
    {
        $this->exception = null;
    }

    public function saveException(): void
    {
        $e = $this->exception;
        if ($e === null) {
            return;
        }
        $this->resetErrorBag();
        $rule = trim((string) $e['rule_id']);
        if (! Waf::excludable($rule)) {
            $this->addError('exception.rule_id', 'Für diese Regel gibt es keine Ausnahme: Sie wertet die Punktzahl aus, eine Ausnahme schaltet den Schutz auf dem Pfad ganz ab.');

            return;
        }
        $body = ['rule_id' => (int) $rule, 'path_prefix' => trim((string) $e['path_prefix']), 'note' => trim((string) $e['note'])];
        $response = $this->appRequest(fn ($api) => $api->post('/api/hosts/'.(int) $e['host_id'].'/waf/exceptions', $body));
        if ($this->succeeded($response, 'exception.')) {
            $where = $body['path_prefix'] !== '' ? ' unter '.$body['path_prefix'] : ' für den ganzen Host';
            $this->dispatch('notice', kind: 'success', text: "Ausnahme angelegt: Regel {$body['rule_id']}{$where} ({$e['domain']}).");
            $this->exception = null;
        }
    }

    public function render()
    {
        $status = Waf::status($this->installedApp);
        if ($status === null) {
            return view('modules.proxy.waf', ['status' => null]);
        }
        $query = array_filter([
            'host' => $this->host, 'rule' => trim($this->rule), 'action' => $this->action, 'ip' => trim($this->ip),
            'country' => $this->country, 'q' => trim($this->q), 'limit' => $this->limit,
        ], fn ($v) => $v !== '' && $v !== null);
        [$rows, $error] = $this->load('/api/waf/events', $query);
        [$stats, $statsError] = $this->load('/api/waf/stats', array_filter([
            'from' => Waf::from((int) $this->days), 'to' => now()->getTimestampMs(), 'host' => $this->host,
        ], fn ($v) => $v !== ''));
        [$hosts] = $this->load('/api/hosts');
        $hostNames = collect(is_array($hosts) ? $hosts : [])->where('kind', 'proxy')
            ->mapWithKeys(fn ($h) => [(string) $h['id'] => (string) ($h['domains'][0] ?? '#'.$h['id'])])->sort()->all();
        $rows = is_array($rows) ? array_values(array_filter($rows['items'] ?? $rows, 'is_array')) : [];

        return view('modules.proxy.waf', [
            'status' => $status,
            'rows' => $rows,
            'loadError' => $error,
            'stats' => is_array($stats) ? $stats : null,
            'statsError' => $statsError,
            'hostNames' => $hostNames,
            'filtered' => collect(self::FILTERS)->contains(fn ($k) => $this->{$k} !== ''),
            'hostErrors' => Waf::hostErrors($status),
        ]);
    }
}
