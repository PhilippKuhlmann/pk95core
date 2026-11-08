<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 Proxy: hosts (reverse proxy, redirect, 404 host, status page) with certificate and
 * target state, maintenance switch and the create/edit form.
 */
class Hosts extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    #[Url(as: 'kind')]
    public string $kindFilter = '';

    /** null = list, 'new' = create form, otherwise the id of the host being edited */
    public ?string $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public const KINDS = ['proxy' => 'Reverse Proxy', 'redirect' => 'Weiterleitung', 'dead' => '404-Host', 'status' => 'Statusseite'];

    public const KIND_HINTS = [
        'proxy' => 'Leitet die Domain an einen Dienst in deinem Netz weiter.',
        'redirect' => 'Schickt Besucher der Domain zu einer anderen Adresse.',
        'dead' => 'Belegt die Domain mit gültigem Zertifikat und antwortet immer mit 404.',
        'status' => 'Zeigt öffentlich, welche deiner Dienste laufen.',
    ];

    public const EMPTY = [
        '' => ['Noch keine Hosts', 'Ein Host leitet eine Domain an einen Dienst in deinem Netz weiter. Lege ihn zunächst deaktiviert an und schalte ihn ein, wenn Port 80 und 443 auf diesen Server zeigen.'],
        'proxy' => ['Noch keine Reverse Proxys', 'Ein Reverse Proxy leitet eine Domain an einen Dienst in deinem Netz weiter.'],
        'redirect' => ['Noch keine Weiterleitungen', 'Eine Weiterleitung schickt Besucher einer Domain zu einer anderen Adresse, zum Beispiel von einer alten auf eine neue Domain.'],
        'dead' => ['Noch keine 404-Hosts', 'Ein 404-Host belegt eine Domain mit gültigem Zertifikat und antwortet immer mit „Nicht gefunden“. Das hilft bei Domains, die du besitzt, aber nicht nutzt.'],
        'status' => ['Noch keine Statusseiten', 'Eine Statusseite zeigt Besuchern öffentlich, welche deiner Dienste laufen. Sie enthält nur Domains und Zustände, keine internen Adressen.'],
    ];

    public function mount(): void
    {
        if (! isset(self::KINDS[$this->kindFilter])) {
            $this->kindFilter = '';
        }
    }

    public function filter(string $kind): void
    {
        $this->kindFilter = isset(self::KINDS[$kind]) ? $kind : '';
    }

    public function create(): void
    {
        $this->resetErrorBag();
        $this->form = self::toForm(null, $this->kindFilter ?: 'proxy');
        $this->editing = 'new';
    }

    public function edit(int $id): void
    {
        $host = $this->findHost($id);
        if ($host !== null) {
            $this->resetErrorBag();
            $this->form = self::toForm($host);
            $this->editing = (string) $id;
        }
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function save(): void
    {
        $body = self::payload($this->form);
        $new = $this->editing === 'new';
        $response = $this->appRequest(fn ($api) => $new
            ? $api->post('/api/hosts', $body)
            : $api->put('/api/hosts/'.(int) $this->editing, $body));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: 'Gespeichert und in Caddy übernommen.');
            $this->editing = null;
        }
    }

    public function toggle(int $id): void
    {
        $host = $this->findHost($id);
        if ($host === null) {
            return;
        }
        $enabled = ! $host['enabled'];
        if ($this->put($host, ['enabled' => $enabled])) {
            $this->dispatch('notice', kind: 'success', text: $host['domains'][0].($enabled ? ' ist aktiv.' : ' ist deaktiviert.'));
        }
    }

    public function maintenance(int $id): void
    {
        $host = $this->findHost($id);
        if ($host === null) {
            return;
        }
        $on = ! $host['maintenance'];
        if ($this->put($host, ['maintenance' => $on])) {
            $this->dispatch('notice', kind: 'success', text: $on ? $host['domains'][0].' zeigt jetzt die Wartungsseite.' : 'Wartung von '.$host['domains'][0].' beendet.');
        }
    }

    public function delete(int $id): void
    {
        $host = $this->findHost($id);
        $response = $this->appRequest(fn ($api) => $api->delete('/api/hosts/'.$id));
        if ($this->succeeded($response)) {
            $this->dispatch('notice', kind: 'success', text: ($host['domains'][0] ?? 'Host').' wurde gelöscht.');
            if ($this->editing === (string) $id) {
                $this->editing = null;
            }
        }
    }

    public function render()
    {
        [$hosts, $error] = $this->load('/api/hosts');
        $hosts = is_array($hosts) ? $hosts : [];
        [$lists] = $this->load('/api/access-lists');
        $lists = is_array($lists) ? $lists : [];
        [$checks] = $this->load('/api/hosts/checks');
        $checks = collect(is_array($checks) ? $checks : [])->keyBy('host_id')->all();

        $counts = array_count_values(array_column($hosts, 'kind'));
        $shown = $this->kindFilter === '' ? $hosts : array_values(array_filter($hosts, fn ($h) => $h['kind'] === $this->kindFilter));

        return view('modules.proxy.hosts', [
            'hosts' => $hosts,
            'shown' => $shown,
            'counts' => $counts,
            'checks' => $checks,
            'lists' => $lists,
            'listNames' => array_column($lists, 'name', 'id'),
            'listsWithUsers' => array_values(array_map(fn ($l) => (string) $l['id'], array_filter($lists, fn ($l) => ! empty($l['users'])))),
            'proxies' => array_values(array_filter($hosts, fn ($h) => $h['kind'] === 'proxy')),
            'loadError' => $error,
        ]);
    }

    // ------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function findHost(int $id): ?array
    {
        $response = $this->appRequest(fn ($api) => $api->get('/api/hosts'));
        if (! $this->succeeded($response)) {
            return null;
        }
        foreach ((array) $response->json() as $host) {
            if ((int) $host['id'] === $id) {
                return $host;
            }
        }
        $this->dispatch('notice', kind: 'error', text: 'Den Host gibt es nicht mehr.');

        return null;
    }

    /**
     * PUT of an existing host with some fields changed (the panel expects the full host).
     *
     * @param  array<string, mixed>  $host
     * @param  array<string, mixed>  $changes
     */
    private function put(array $host, array $changes): bool
    {
        $body = array_merge(array_intersect_key($host, array_flip(self::FIELDS)), $changes);

        return $this->succeeded($this->appRequest(fn ($api) => $api->put('/api/hosts/'.(int) $host['id'], $body)));
    }

    /** Fields of HostInput (the panel rejects unknown fields). */
    public const FIELDS = [
        'access_list_id', 'tls_min', 'tls_bsi', 'allow_http', 'block_exploits', 'maintenance', 'maintenance_bypass',
        'maintenance_text', 'kind', 'redirect_url', 'redirect_code', 'redirect_keep_path', 'status_title', 'status_hosts',
        'domains', 'scheme', 'upstream_host', 'upstream_port', 'skip_verify', 'upstream_host_header', 'hsts',
        'security_headers', 'enabled', 'notes',
    ];

    /**
     * Host of the panel → form state (strings for inputs, as in the original dialog).
     *
     * @param  array<string, mixed>|null  $h
     * @return array<string, mixed>
     */
    public static function toForm(?array $h, string $kind = 'proxy'): array
    {
        $h ??= [
            'kind' => $kind, 'redirect_url' => '', 'redirect_code' => 302, 'redirect_keep_path' => true, 'status_title' => '',
            'status_hosts' => [], 'maintenance' => false, 'maintenance_bypass' => false, 'maintenance_text' => '', 'tls_min' => '1.2',
            'tls_bsi' => false, 'allow_http' => false, 'access_list_id' => 0, 'domains' => [], 'scheme' => 'http', 'upstream_host' => '',
            'upstream_port' => '', 'skip_verify' => false, 'upstream_host_header' => false, 'hsts' => false, 'security_headers' => 'basic',
            'block_exploits' => false, 'enabled' => false, 'notes' => '',
        ];

        return [
            'kind' => $h['kind'],
            'domains' => implode("\n", $h['domains']),
            'scheme' => $h['scheme'] ?: 'http',
            'upstream_host' => (string) $h['upstream_host'],
            'upstream_port' => (string) ($h['upstream_port'] ?: ''),
            'skip_verify' => (bool) $h['skip_verify'],
            'upstream_host_header' => (bool) $h['upstream_host_header'],
            'force_https' => ! $h['allow_http'],
            'hsts' => (bool) $h['hsts'],
            'tls_min' => $h['tls_min'] ?: '1.2',
            'tls_bsi' => (bool) $h['tls_bsi'],
            'security_headers' => $h['security_headers'] ?: 'basic',
            'access_list_id' => (string) ($h['access_list_id'] ?? 0),
            'block_exploits' => (bool) $h['block_exploits'],
            'redirect_url' => (string) $h['redirect_url'],
            'redirect_code' => (string) ($h['redirect_code'] ?: 302),
            'redirect_keep_path' => (bool) $h['redirect_keep_path'],
            'status_title' => (string) $h['status_title'],
            'status_hosts' => array_map('strval', (array) $h['status_hosts']),
            'maintenance' => (bool) $h['maintenance'],
            'maintenance_bypass' => (bool) $h['maintenance_bypass'],
            'maintenance_text' => (string) $h['maintenance_text'],
            'enabled' => (bool) $h['enabled'],
            'notes' => (string) $h['notes'],
        ];
    }

    /**
     * Form state → HostInput, with the same rules as the original dialog.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function payload(array $f): array
    {
        $kind = (string) ($f['kind'] ?? 'proxy');
        $https = ($f['scheme'] ?? 'http') === 'https';
        $force = (bool) ($f['force_https'] ?? true);

        return [
            'domains' => array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', (string) ($f['domains'] ?? ''))), fn ($d) => $d !== '')),
            'scheme' => $https ? 'https' : 'http',
            'upstream_host' => trim((string) ($f['upstream_host'] ?? '')),
            'upstream_port' => (int) ($f['upstream_port'] ?? 0),
            'skip_verify' => $https && ! empty($f['skip_verify']),
            'upstream_host_header' => ! empty($f['upstream_host_header']),
            'hsts' => $force && ! empty($f['hsts']),
            'tls_min' => ($f['tls_min'] ?? '1.2') === '1.3' ? '1.3' : '1.2',
            'tls_bsi' => ! empty($f['tls_bsi']),
            'allow_http' => ! $force,
            'block_exploits' => ! empty($f['block_exploits']),
            'security_headers' => in_array($f['security_headers'] ?? '', ['off', 'basic', 'strict'], true) ? $f['security_headers'] : 'basic',
            'enabled' => ! empty($f['enabled']),
            'notes' => (string) ($f['notes'] ?? ''),
            'access_list_id' => in_array($kind, ['redirect', 'dead'], true) ? 0 : (int) ($f['access_list_id'] ?? 0),
            'kind' => $kind,
            'redirect_url' => trim((string) ($f['redirect_url'] ?? '')),
            'redirect_code' => (int) ($f['redirect_code'] ?? 302),
            'redirect_keep_path' => ! empty($f['redirect_keep_path']),
            'status_title' => (string) ($f['status_title'] ?? ''),
            'status_hosts' => array_values(array_map('intval', (array) ($f['status_hosts'] ?? []))),
            'maintenance' => ! empty($f['maintenance']),
            'maintenance_bypass' => ! empty($f['maintenance_bypass']),
            'maintenance_text' => (string) ($f['maintenance_text'] ?? ''),
        ];
    }
}
