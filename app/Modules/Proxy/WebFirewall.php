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

    /** @var array{use_internal_networks: bool, bypass_cidrs: string, new_host_mode: string} global settings (PK95 Proxy 1.9.0) */
    public array $settings = ['use_internal_networks' => false, 'bypass_cidrs' => '', 'new_host_mode' => 'detect'];

    /** the Proxy has central settings hosts inherit (level, limits, groups) and central exceptions */
    public bool $hasCentral = false;

    /** @var array<string, mixed> central level, limits and groups (Waf::tuningForm) */
    public array $central = [];

    /** @var array<string, mixed>|null CRS defaults of the limits */
    public ?array $centralDefaults = null;

    /** @var array<string, int|null> */
    public array $centralSuggested = [];

    /** @var list<array<string, mixed>> exceptions for all hosts */
    public array $centralExceptions = [];

    /** @var list<array<string, mixed>> rule groups (/api/waf/groups) for the central form */
    public array $groups = [];

    /** the settings are loaded once, polling must not overwrite what is being typed */
    public bool $settingsLoaded = false;

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

    /**
     * Opens the dialog for an exception: this rule, on this host, under the path of the hit and
     * (PK95 Proxy 1.9.0) only for the parameter the rule hit, when the Proxy accepts that target.
     */
    public function newException(int $hostId, string $domain, string $ruleId, string $path, string $target = ''): void
    {
        $this->resetErrorBag();
        $collections = $target !== '' ? (Waf::status($this->installedApp)['exception_targets'] ?? []) : [];
        $usable = Waf::exceptionTarget($target, $collections);
        $this->exception = ['host_id' => $hostId, 'domain' => $domain, 'rule_id' => $ruleId, 'path_prefix' => Waf::pathPrefix($path), 'note' => '',
            // only this host (default) or all hosts (a central exception)
            'scope' => 'host',
            'target' => $usable ?? '', 'use_target' => $usable !== null,
            // the rule hit something an exception cannot name: it holds for the whole rule
            'target_hint' => $target !== '' && $usable === null && $collections !== [] ? $target : ''];
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
        if (! empty($e['use_target']) && trim((string) ($e['target'] ?? '')) !== '') {
            $body['target'] = trim((string) $e['target']);
        }
        $central = $this->hasCentral && ($e['scope'] ?? 'host') === 'all';
        $response = $this->appRequest(fn ($api) => $api->post($central ? '/api/waf/settings/exceptions' : '/api/hosts/'.(int) $e['host_id'].'/waf/exceptions', $body));
        if ($response === null) {
            return;
        }
        if (! $response->successful()) {
            // "target" or "exceptions.0.target": the field of the dialog either way
            foreach (Waf::fieldErrors((array) $response->json('fields')) as $field => $message) {
                $this->addError('exception.'.preg_replace('/^exceptions\.\d+\./', '', $field), $message);
            }
            $this->dispatch('notice', kind: 'error', text: (string) ($response->json('error') ?? "Die App antwortet mit HTTP {$response->status()}."));

            return;
        }
        $where = ($body['path_prefix'] !== '' ? ' unter '.$body['path_prefix'] : ($central ? ' überall' : ' für den ganzen Host')).(isset($body['target']) ? ', nur '.$body['target'] : '');
        $this->dispatch('notice', kind: 'success', text: "Ausnahme angelegt: Regel {$body['rule_id']}{$where} (".($central ? 'alle Hosts' : $e['domain']).').');
        if ($central) {
            $this->centralExceptions[] = (array) $response->json();
        }
        $this->exception = null;
    }

    /** Global settings of PK95 Proxy 1.9.0 (PUT replaces them). */
    public function saveSettings(): void
    {
        $this->resetErrorBag();
        $body = ['use_internal_networks' => (bool) $this->settings['use_internal_networks'], 'bypass_cidrs' => Waf::lines((string) $this->settings['bypass_cidrs'])];
        if ($this->hasCentral) {
            $body['new_host_mode'] = (string) $this->settings['new_host_mode'];
            $body['central'] = Waf::tuningPayload($this->central, $this->centralDefaults);
        }
        $response = $this->appRequest(fn ($api) => $api->put('/api/waf/settings', $body));
        if ($response === null) {
            return;
        }
        if (! $response->successful()) {
            // central.limits.max_args → the central form, the rest → settings
            foreach (Waf::fieldErrors((array) $response->json('fields')) as $field => $message) {
                $this->addError(str_starts_with($field, 'central.') ? $field : 'settings.'.$field, $message);
            }
            $this->dispatch('notice', kind: 'error', text: (string) ($response->json('error') ?? "Die App antwortet mit HTTP {$response->status()}."));

            return;
        }
        $this->fillSettings((array) $response->json());
        $this->dispatch('notice', kind: 'success', text: 'Einstellungen gespeichert.');
    }

    /** A higher central block level takes the detection level along. */
    public function updatedCentral(mixed $value, string $key): void
    {
        if ($key === 'paranoia' && (int) ($this->central['detection_paranoia'] ?? 0) < (int) $value) {
            $this->central['detection_paranoia'] = (string) $value;
        }
    }

    public function deleteCentralException(string $id): void
    {
        $response = $this->appRequest(fn ($api) => $api->delete('/api/waf/settings/exceptions/'.rawurlencode($id)));
        if ($this->succeeded($response, 'settings.')) {
            $this->centralExceptions = array_values(array_filter($this->centralExceptions, fn ($e) => (string) ($e['id'] ?? '') !== $id));
            $this->dispatch('notice', kind: 'success', text: 'Ausnahme gelöscht.');
        }
    }

    /** @param  array<string, mixed>  $s */
    private function fillSettings(array $s): void
    {
        $this->settings = ['use_internal_networks' => (bool) ($s['use_internal_networks'] ?? false), 'bypass_cidrs' => implode("\n", (array) ($s['bypass_cidrs'] ?? [])),
            'new_host_mode' => isset(Waf::MODES[$s['new_host_mode'] ?? '']) ? $s['new_host_mode'] : 'detect'];
        $this->settingsLoaded = true;
        $this->hasCentral = is_array($s['central'] ?? null);
        if ($this->hasCentral) {
            $this->centralDefaults = (array) ($s['defaults'] ?? []);
            $this->centralSuggested = (array) ($s['suggested'] ?? []);
            $this->central = Waf::tuningForm($s['central'], $this->centralDefaults);
            $this->centralExceptions = array_values(array_filter((array) ($s['exceptions'] ?? []), 'is_array'));
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
        if ($status['v19'] && ! $this->settingsLoaded) {
            [$settings] = $this->load('/api/waf/settings');
            if (is_array($settings)) {
                $this->fillSettings($settings);
            }
            if ($this->hasCentral) {
                [$groups] = $this->load('/api/waf/groups');
                $this->groups = is_array($groups) ? array_values(array_filter($groups, 'is_array')) : [];
            }
        }
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
            'v19' => $status['v19'],
            'bypassedToday' => $status['bypassed_today'],
        ]);
    }
}
