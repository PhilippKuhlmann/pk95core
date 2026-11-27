<?php

namespace Tests\Feature;

use App\Http\Support\Format;
use App\Livewire\Dashboard\AppPanel;
use App\Models\InstalledApp;
use App\Modules\Proxy\AccessLog;
use App\Modules\Proxy\Certificates;
use App\Modules\Proxy\Daily;
use App\Modules\Proxy\ErrorPages;
use App\Modules\Proxy\Hosts;
use App\Modules\Proxy\Overview;
use App\Modules\Proxy\ProxyModule;
use App\Modules\Proxy\Security;
use App\Modules\Proxy\Streams;
use App\Modules\Proxy\Views;
use App\Modules\Proxy\Visitors;
use App\Modules\Proxy\WebFirewall;
use App\Services\Certsync\Certsync;
use App\Services\Dashboard\AppFigures;
use App\Services\Dashboard\Problems;
use App\Support\Settings;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Js;
use Livewire\Features\SupportLazyLoading\SupportLazyLoading;
use Livewire\Livewire;
use Tests\Unit\CertsyncKeysTest;

/**
 * The PK95 Proxy module against a fake of the panel API (host network:
 * http://host.docker.internal:8090).
 */
class ProxyModuleTest extends FeatureTestCase
{
    private InstalledApp $installed;

    /** @var list<array{method: string, path: string, query: string, body: mixed}> */
    private array $calls = [];

    /** @var array<int, array<string, mixed>> */
    private array $hosts = [];

    /** @var list<array<string, mixed>>|null GET /api/requests/clients; null = older Proxy (404) */
    private ?array $clientList = null;

    /** @var array<string, mixed> replaces keys of the faked /api/stats answer */
    private array $statsOverride = [];

    /** @var array<string, mixed>|null GET /api/hosts/daily (PK95 Proxy 1.4.0); null = older Proxy (404) */
    private ?array $daily = null;

    /** @var array<string, mixed>|null GET /api/hosts/visitors (PK95 Proxy 1.5.0); null = older Proxy (404) */
    private ?array $visitors = null;

    /** @var list<array<string, mixed>> more Let's Encrypt certificates for other applications (/api/acme-certs) */
    private array $extraAcme = [];

    /** @var array<string, mixed> more guard fields in the faked /api/security answer (e.g. repeat_hits of Proxy 1.6.0) */
    private array $guardExtra = [];

    /** @var list<array<string, mixed>> more bans in the faked /api/security answer */
    private array $extraBans = [];

    /**
     * The web firewall of the panel (PK95 Proxy 1.8.0); null = an older Proxy (no waf_mode,
     * 404 on every WAF route).
     *
     * @var array{status: array<string, mixed>, hosts: array<int, array<string, mixed>>, events: list<array<string, mixed>>, stats: array<string, mixed>}|null
     */
    private ?array $waf = null;

    private function enableWaf(): void
    {
        $now = (int) (microtime(true) * 1000);
        $this->waf = [
            'status' => ['crs_version' => '4.10.0', 'coraza_version' => '3.3.0', 'module' => true, 'rules_loaded' => true, 'error' => '',
                'hosts' => [['host_id' => 1, 'domain' => 'app.example.de', 'mode' => 'detect', 'error' => '']]],
            'hosts' => [1 => ['mode' => 'detect', 'paranoia' => 1, 'anomaly_threshold' => 5, 'body_limit_kb' => 1024, 'profiles' => [],
                'exceptions' => [['id' => 1, 'rule_id' => 920350, 'path_prefix' => '/api/', 'note' => 'App sendet IP als Host', 'created_at' => $now]], 'error' => '']],
            'events' => [['id' => 501, 'ts' => $now - 60000, 'host_id' => 1, 'domain' => 'app.example.de', 'method' => 'GET', 'path' => '/search?q=1%27+or+1=1',
                'ip' => '198.51.100.23', 'country' => 'NL', 'anomaly_score' => 10, 'action' => 'detect',
                'rules' => [['id' => 942100, 'msg' => 'SQL Injection Attack Detected via libinjection', 'severity' => 'critical', 'target' => 'ARGS:q'],
                    ['id' => 949110, 'msg' => 'Inbound Anomaly Score Exceeded', 'severity' => 'critical', 'target' => '']]]],
            'stats' => ['from' => $now - 86400000, 'to' => $now, 'computed_at' => $now, 'hits' => 37, 'blocks' => 4,
                'top_rules' => [['rule_id' => 942100, 'msg' => 'SQL Injection Attack Detected via libinjection', 'count' => 21]],
                'top_hosts' => [['host_id' => 1, 'domain' => 'app.example.de', 'count' => 37]], 'bucket_ms' => 3600000, 'series' => []],
        ];
    }

    /** PK95 Proxy 1.9.0 on top: levels 1–4, groups, bypass networks, limits, exceptions per parameter. */
    private function enableWaf19(): void
    {
        $this->enableWaf();
        $this->waf['status'] += ['exception_targets' => ['ARGS', 'ARGS_NAMES', 'ARGS_GET', 'ARGS_POST', 'REQUEST_HEADERS', 'REQUEST_COOKIES', 'FILES'], 'bypassed_today' => 7];
        $this->waf['hosts'][1] += ['inherit' => false, 'detection_paranoia' => 1, 'disabled_groups' => [], 'bypass_cidrs' => [],
            'limits' => array_fill_keys(['allowed_methods', 'allowed_content_types', 'restricted_extensions', 'max_args', 'arg_name_length', 'arg_length',
                'total_arg_length', 'max_file_size_kb', 'combined_file_sizes_kb'], null),
            'defaults' => ['allowed_methods' => ['GET', 'HEAD', 'POST', 'OPTIONS'],
                'allowed_content_types' => ['application/x-www-form-urlencoded', 'multipart/form-data', 'application/json'],
                'restricted_extensions' => ['.bak', '.sql'], 'max_args' => null, 'arg_name_length' => null, 'arg_length' => null,
                'total_arg_length' => null, 'max_file_size_kb' => null, 'combined_file_sizes_kb' => null],
            'suggested' => ['max_args' => 255, 'arg_name_length' => 100, 'arg_length' => 400, 'total_arg_length' => 64000, 'max_file_size_kb' => 1024, 'combined_file_sizes_kb' => 1024]];
        $this->waf['groups'] = [['id' => 'php', 'label' => 'PHP', 'kind' => 'technology', 'description' => 'Angriffe auf PHP-Anwendungen.'],
            ['id' => 'java', 'label' => 'Java', 'kind' => 'technology', 'description' => 'Angriffe auf Java-Anwendungen.'],
            ['id' => 'sqli', 'label' => 'SQL-Injection', 'kind' => 'attack', 'description' => 'Eingeschleuste Datenbankbefehle.']];
        $limits = $this->waf['hosts'][1]['limits'];
        $this->waf['settings'] = ['use_internal_networks' => false, 'bypass_cidrs' => [], 'new_host_mode' => 'detect',
            'central' => ['paranoia' => 2, 'detection_paranoia' => 3, 'anomaly_threshold' => 5, 'body_limit_kb' => 1024, 'disabled_groups' => ['java'], 'limits' => $limits],
            'exceptions' => [['id' => 7, 'rule_id' => 920350, 'path_prefix' => '', 'note' => 'IP als Host', 'target' => '', 'created_at' => 1]],
            'defaults' => $this->waf['hosts'][1]['defaults'], 'suggested' => $this->waf['hosts'][1]['suggested']];
        $this->waf['events'][0]['block_paranoia'] = 1;
        $this->waf['events'][0]['min_paranoia'] = 1;
        $this->waf['events'][0]['detection_score'] = 13;
        $this->waf['events'][0]['rules'][0] += ['paranoia' => 1];
        $this->waf['events'][0]['rules'][] = ['id' => 920320, 'msg' => 'Missing User Agent Header', 'severity' => 'notice', 'target' => 'REQUEST_HEADERS:User-Agent', 'paranoia' => 2];
        $this->waf['events'][0]['rules'][] = ['id' => 930100, 'msg' => 'Path Traversal', 'severity' => 'critical', 'target' => 'REQUEST_URI', 'paranoia' => 1];
        $this->waf['stats']['hits_by_paranoia'] = ['0' => 3, '1' => 30, '2' => 4, '3' => 0, '4' => 0];
    }

    /** The faked WAF routes, null when the request is not one of them. */
    private function wafRoute(Request $r, string $path)
    {
        $m = [];
        if ($this->waf === null) {
            return str_starts_with($path, '/api/waf/') || preg_match('#^/api/hosts/\d+/waf#', $path) ? Http::response(['error' => 'nicht gefunden'], 404) : null;
        }
        $data = $r->data();
        $route = match ($path) {
            '/api/waf/status' => $this->waf['status'],
            '/api/waf/events' => $this->waf['events'],
            '/api/waf/stats' => $this->waf['stats'],
            '/api/waf/profiles' => [['id' => 'wordpress', 'label' => 'WordPress', 'description' => 'Nimmt Regeln für den Block-Editor zurück.'],
                ['id' => 'nextcloud', 'label' => 'Nextcloud', 'description' => 'WebDAV und Uploads.']],
            default => null,
        };
        if ($route !== null) {
            return Http::response($route);
        }
        // PK95 Proxy 1.9.0: rule groups and global settings (404 before)
        if ($path === '/api/waf/groups') {
            return isset($this->waf['groups']) ? Http::response($this->waf['groups']) : Http::response(['error' => 'nicht gefunden'], 404);
        }
        if ($path === '/api/waf/settings') {
            if (! isset($this->waf['settings'])) {
                return Http::response(['error' => 'nicht gefunden'], 404);
            }
            if ($r->method() === 'PUT') {
                foreach ($data['bypass_cidrs'] ?? [] as $i => $cidr) {
                    if (in_array($cidr, ['0.0.0.0/0', '::/0'], true)) {
                        return Http::response(['error' => 'Ungültige Eingabe', 'fields' => ['bypass_cidrs.'.$i => 'Zu groß.']], 422);
                    }
                }
                if (($data['central']['paranoia'] ?? 1) > ($data['central']['detection_paranoia'] ?? 4)) {
                    return Http::response(['error' => 'Ungültige Eingabe', 'fields' => ['central.detection_paranoia' => 'Nicht unter der Blockstufe.']], 422);
                }
                // a partial update (central.limits too)
                if (isset($data['central']['limits'], $this->waf['settings']['central']['limits'])) {
                    $data['central']['limits'] = array_replace($this->waf['settings']['central']['limits'], $data['central']['limits']);
                }
                if (isset($data['central'], $this->waf['settings']['central'])) {
                    $data['central'] = array_replace($this->waf['settings']['central'], $data['central']);
                }
                $this->waf['settings'] = array_replace($this->waf['settings'], $data);
            }

            return Http::response($this->waf['settings']);
        }
        if (preg_match('#^/api/waf/settings/exceptions(?:/(\d+))?$#', $path, $m)) {
            if ($r->method() === 'DELETE') {
                $this->waf['settings']['exceptions'] = array_values(array_filter($this->waf['settings']['exceptions'], fn ($e) => (string) $e['id'] !== $m[1]));

                return Http::response(null, 204);
            }
            $new = ['id' => 50 + count($this->waf['settings']['exceptions']), 'rule_id' => $data['rule_id'], 'path_prefix' => $data['path_prefix'] ?? '',
                'note' => $data['note'] ?? '', 'target' => $data['target'] ?? '', 'created_at' => 1];
            $this->waf['settings']['exceptions'][] = $new;

            return Http::response($new, 201);
        }
        if (preg_match('#^/api/hosts/(\d+)/waf/exceptions(?:/([^/]+))?$#', $path, $m)) {
            $id = (int) $m[1];
            if ($r->method() === 'DELETE') {
                $this->waf['hosts'][$id]['exceptions'] = array_values(array_filter($this->waf['hosts'][$id]['exceptions'], fn ($e) => (string) $e['id'] !== $m[2]));

                return Http::response(null, 204);
            }
            foreach ($this->waf['hosts'][$id]['exceptions'] ?? [] as $e) {
                if ($e['rule_id'] === $data['rule_id'] && $e['path_prefix'] === ($data['path_prefix'] ?? '') && ($e['target'] ?? '') === ($data['target'] ?? '')) {
                    return Http::response(['error' => 'Diese Ausnahme gibt es schon.', 'id' => $e['id']], 409);
                }
            }
            // ids are numbers (int64 in PK95 Proxy)
            $new = ['id' => 100 + count($this->waf['hosts'][$id]['exceptions'] ?? []), 'rule_id' => $data['rule_id'], 'path_prefix' => $data['path_prefix'] ?? '',
                'note' => $data['note'] ?? '', 'created_at' => 1] + (isset($data['target']) ? ['target' => $data['target']] : []);
            $this->waf['hosts'][$id]['exceptions'][] = $new;

            return Http::response($new, 201);
        }
        if (preg_match('#^/api/hosts/(\d+)/waf$#', $path, $m)) {
            $id = (int) $m[1];
            $current = $this->waf['hosts'][$id] ?? ['mode' => 'detect', 'paranoia' => 1, 'anomaly_threshold' => 5, 'body_limit_kb' => 1024, 'profiles' => [], 'exceptions' => [], 'error' => ''];
            if ($r->method() === 'PUT') {
                if (($this->hosts[$id]['kind'] ?? 'proxy') !== 'proxy' && ($data['mode'] ?? 'off') !== 'off') {
                    return Http::response(['error' => 'Ungültige Eingabe', 'fields' => ['mode' => 'Nur für Reverse Proxys.']], 422);
                }
                if (($data['anomaly_threshold'] ?? 5) < 2) {
                    return Http::response(['error' => 'Ungültige Eingabe', 'fields' => ['anomaly_threshold' => 'Zwischen 2 und 100.']], 422);
                }
                // 1.9.0: detection not below the block level, no world-wide bypass, methods not empty
                $paranoia = $data['paranoia'] ?? $current['paranoia'];
                if (array_key_exists('detection_paranoia', $data) && $data['detection_paranoia'] !== null && $data['detection_paranoia'] < $paranoia) {
                    return Http::response(['error' => 'Ungültige Eingabe', 'fields' => ['detection_paranoia' => 'Nicht unter der Blockstufe.']], 422);
                }
                foreach ($data['bypass_cidrs'] ?? [] as $i => $cidr) {
                    if (in_array($cidr, ['0.0.0.0/0', '::/0'], true)) {
                        return Http::response(['error' => 'Ungültige Eingabe', 'fields' => ['bypass_cidrs.'.$i => 'Zu groß.']], 422);
                    }
                }
                if (($data['limits']['allowed_methods'] ?? null) === []) {
                    return Http::response(['error' => 'Ungültige Eingabe', 'fields' => ['limits.allowed_methods' => 'Mindestens eine Methode.']], 422);
                }
                // a partial update: what is not sent stays (inside limits too)
                if (isset($data['limits'], $current['limits'])) {
                    $data['limits'] = array_replace($current['limits'], $data['limits']);
                }
                if (array_key_exists('detection_paranoia', $data) && $data['detection_paranoia'] === null) {
                    $data['detection_paranoia'] = $paranoia;
                }
                $current = array_replace($current, array_diff_key($data, ['error' => 1]));
                $this->waf['hosts'][$id] = $current;
            }
            if (isset($this->waf['settings']['central'], $current['limits'])) {
                $six = ['paranoia', 'detection_paranoia', 'anomaly_threshold', 'body_limit_kb', 'disabled_groups', 'limits'];
                $current['effective'] = ($current['inherit'] ?? false) ? $this->waf['settings']['central'] : array_intersect_key($current, array_flip($six));
            }

            return Http::response($current);
        }

        return null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // certificate days are counted from now(): a second passing mid-test must not turn 5 into 4
        $this->freezeSecond();
        config(['pk95.catalog_dir' => base_path('catalog'), 'pk95.modules' => ['proxy' => ProxyModule::class]]);
        $this->signIn();
        $menu = array_map(fn ($k) => ['key' => $k, 'label' => $k, 'icon' => 'globe'], array_keys((new ProxyModule)->pages()));
        $this->installed = InstalledApp::factory()->create([
            'app_id' => 'proxy', 'version' => '1.0.0',
            'manifest' => ['id' => 'proxy', 'name' => 'PK95 Proxy', 'version' => '1.0.0', 'protocol' => 1, 'icon' => 'globe',
                'menu' => $menu, 'capabilities' => ['events', 'backup'], 'events' => ['host.down']],
        ]);
        $this->hosts[1] = $this->host(1, ['app.example.de'], ['enabled' => true]);
        $this->hosts[2] = $this->host(2, ['alt.example.de'], ['kind' => 'redirect', 'redirect_url' => 'https://neu.example.de']);
        Http::fake(['host.docker.internal:8090/*' => fn (Request $r) => $this->panel($r)]);
        // pages render fully here; the lazy placeholder has its own test
        Livewire::withoutLazyLoading();
    }

    /**
     * @param  list<string>  $domains
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function host(int $id, array $domains, array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'access_list_id' => 0, 'tls_min' => '1.2', 'tls_bsi' => false, 'allow_http' => false, 'block_exploits' => true,
            'maintenance' => false, 'maintenance_bypass' => false, 'maintenance_text' => '', 'kind' => 'proxy', 'redirect_url' => '',
            'redirect_code' => 302, 'redirect_keep_path' => true, 'status_title' => '', 'status_hosts' => [], 'domains' => $domains,
            'scheme' => 'http', 'upstream_host' => '10.0.0.5', 'upstream_port' => 8080, 'skip_verify' => false, 'upstream_host_header' => false,
            'hsts' => true, 'security_headers' => 'basic', 'enabled' => false, 'notes' => '', 'created_at' => 1, 'updated_at' => 1,
        ];
    }

    private function panel(Request $r)
    {
        $url = parse_url($r->url());
        $path = $url['path'];
        $this->calls[] = ['method' => $r->method(), 'path' => $path, 'query' => $url['query'] ?? '', 'body' => $r->data()];
        $now = (int) (microtime(true) * 1000);
        if ($waf = $this->wafRoute($r, $path)) {
            return $waf;
        }

        if ($r->method() === 'PUT' && preg_match('#^/api/hosts/(\d+)$#', $path, $m)) {
            $this->hosts[(int) $m[1]] = $r->data() + ['id' => (int) $m[1], 'created_at' => 1, 'updated_at' => 2];

            return Http::response($this->hosts[(int) $m[1]]);
        }
        if ($r->method() === 'POST' && $path === '/api/hosts') {
            if ($r->data()['domains'] === []) {
                return Http::response(['error' => 'Mindestens eine Domain angeben'], 400);
            }

            return Http::response(['id' => 3] + $r->data(), 201);
        }

        $bucket = ['ts' => $now - 3600000, 'requests' => 12, 's2xx' => 10, 's3xx' => 1, 's4xx' => 1, 's5xx' => 0, 'bytes' => 5000, 'bytes_in' => 300];

        return match ($r->method().' '.$path) {
            // a Proxy with web firewall adds the read-only waf_mode
            'GET /api/hosts' => Http::response(array_values(array_map(fn ($h) => $this->waf === null ? $h
                : $h + ['waf_mode' => $h['kind'] === 'proxy' ? ($this->waf['hosts'][$h['id']]['mode'] ?? 'detect') : 'off']
                    + (isset($this->waf['hosts'][$h['id']]['inherit']) ? ['waf_inherit' => $this->waf['hosts'][$h['id']]['inherit']] : []), $this->hosts))),
            // the panel answers with the saved rules (here: what was sent)
            'PUT /api/security/guard' => Http::response($r->data()),
            'GET /api/hosts/daily' => $this->daily === null ? Http::response(['error' => 'nicht gefunden'], 404) : Http::response($this->daily),
            'GET /api/hosts/visitors' => $this->visitors === null ? Http::response(['error' => 'nicht gefunden'], 404) : Http::response($this->visitors),
            'GET /api/hosts/checks' => Http::response([['host_id' => 1, 'upstream_known' => true, 'upstream_up' => false, 'upstream_error' => 'Verbindung abgelehnt',
                'domains' => [['domain' => 'app.example.de', 'status' => 'ok', 'not_after' => now()->getTimestamp() + 40 * 86400, 'issuer' => "Let's Encrypt"]]]]),
            'GET /api/access-lists' => Http::response([['id' => 1, 'name' => 'Intern', 'title' => '', 'mode' => 'any', 'allow' => ['10.0.0.0/8'], 'deny' => [],
                'session_hours' => 12, 'users' => [], 'is_internal' => true, 'used_by' => []],
                ['id' => 2, 'name' => 'Familie', 'title' => 'Anmeldung', 'mode' => 'all', 'allow' => [], 'deny' => ['203.0.113.7'],
                    'session_hours' => 168, 'users' => [['username' => 'anna']], 'is_internal' => false, 'used_by' => ['app.example.de']]]),
            'GET /api/health' => Http::response(['now' => $now, 'hosts' => [[
                'host_id' => 1, 'domains' => ['app.example.de'], 'target' => 'http://10.0.0.5:8080', 'enabled' => true, 'maintenance' => false,
                'known' => true, 'up' => false, 'since' => $now - 600000, 'fails' => 3, 'last_ts' => $now, 'status' => 0, 'latency_ms' => 0,
                'error' => 'Verbindung abgelehnt', 'uptime_24h' => 99.996, 'uptime_7d' => null, 'uptime_30d' => null, 'avg_ms' => 12.5,
                'timeline' => [['ts' => $now - 3600000, 'ok' => 10, 'total' => 12]]]],
                'events' => [['id' => 2, 'host_id' => 1, 'domain' => 'app.example.de', 'ts' => $now - 600000, 'up' => false, 'detail' => 'Verbindung abgelehnt']]]),
            'GET /api/streams' => Http::response(['service_ok' => true, 'streams' => [['id' => 4, 'name' => 'Minecraft', 'protocol' => 'tcp', 'listen_port' => 25565,
                'target_host' => '10.0.0.9', 'target_port' => 25565, 'access_mode' => 'internal', 'ip_allow' => [], 'enabled' => true, 'notes' => '',
                'status' => ['active' => 1, 'total' => 7, 'denied' => 2, 'bytes_in' => 1000, 'bytes_out' => 2000]]],
                'recent' => [['ts' => $now, 'stream_id' => 4, 'name' => 'Minecraft', 'protocol' => 'tcp', 'ip' => '198.51.100.4', 'country' => 'DE',
                    'bytes_in' => 10, 'bytes_out' => 20, 'seconds' => 3]]]),
            'GET /api/certificates' => Http::response(['hosts' => [['host_id' => 1, 'domain' => 'app.example.de', 'kind' => 'proxy', 'enabled' => true,
                'status' => 'ok', 'source' => 'auto', 'issuer' => "Let's Encrypt", 'not_after' => now()->getTimestamp() + 5 * 86400]], 'custom' => []]),
            'GET /api/acme-certs' => Http::response([['id' => 7, 'domain' => 'mail.example.de', 'note' => 'Mailserver', 'created_at' => $now,
                'path' => '/var/lib/proxypanel/acme-export/mail.example.de', 'status' => ['issuer' => "Let's Encrypt", 'not_after' => now()->getTimestamp() + 80 * 86400]], ...$this->extraAcme]),
            'GET /api/acme-certs/7/fullchain.pem' => Http::response("-----BEGIN CERTIFICATE-----\n", 200,
                ['Content-Type' => 'application/x-pem-file', 'Content-Disposition' => 'attachment; filename="mail.example.de.fullchain.pem"']),
            'GET /api/error-pages' => Http::response(['defaults' => array_fill_keys(array_keys(ErrorPages::GROUPS), ['title' => 'Standard', 'text' => 'Text']),
                'config' => ['enabled' => true, 'pages' => array_fill_keys(array_keys(ErrorPages::GROUPS), ['title' => 'Eigener Titel', 'text' => 'x', 'html' => ''])]]),
            'PUT /api/error-pages' => Http::response($r->data()),
            'GET /api/error-pages/preview/unavailable' => Http::response('<h1>Dienst nicht erreichbar</h1>', 200, ['Content-Type' => 'text/html']),
            'GET /api/settings' => Http::response(['acme_email' => 'a@example.de', 'retention_days' => 30, 'trusted_proxies' => [],
                'default_action' => '404', 'default_html' => '', 'default_redirect' => '']),
            'PUT /api/settings' => Http::response($r->data()),
            'GET /api/status' => Http::response(['caddy_ok' => true, 'geoip' => true, 'rows' => 1234, 'oldest_ts' => $now - 86400000, 'db_bytes' => 2048000]),
            'GET /api/updates' => Http::response(['agent' => ['checked_at' => $now, 'debian' => '13.1', 'kernel' => '6.8', 'running' => '',
                'components' => [['name' => 'Caddy', 'package' => 'caddy', 'installed' => 'v2.10.2']], 'upgrades' => [], 'runs' => []],
                'queued' => false, 'policy' => ['os' => 'off', 'caddy' => false, 'panel' => false], 'go_version' => 'go1.26.0',
                'modules' => [['path' => 'modernc.org/sqlite', 'version' => 'v1.61.1']], 'vulns' => ['checked_at' => $now, 'vulns' => []],
                'geoip_date' => $now, 'core_mode' => true]),
            'POST /api/updates/request' => Http::response(['ok' => true], 202),
            'GET /api/security' => Http::response(['mode' => 'block', 'blocked' => ['CN'], 'allowed' => [], 'queued' => false, 'running' => '',
                'firewall' => ['mode' => 'block', 'countries' => ['CN'], 'networks' => 1200, 'applied_at' => $now, 'dropped' => 55],
                'guard' => ['enabled' => true, 'window_minutes' => 10, 'scan_hits' => 3, 'not_found' => 30, 'auth_fails' => 10, 'unknown_hosts' => 5,
                    'ban_hours' => 24, 'exempt' => [], 'notify' => false] + $this->guardExtra,
                'bans' => [['ip' => '203.0.113.9', 'country' => 'NL', 'reason' => 'scan', 'strikes' => 2, 'detail' => '/.env', 'host' => 'app.example.de',
                    'created_at' => $now, 'expires_at' => 0], ...$this->extraBans], 'bans_total' => 1, 'ban_status' => ['applied_at' => $now, 'dropped' => 9],
                'hide_server' => false, 'blocklist_networks' => 0]),
            'PUT /api/security/countries' => Http::response(['ok' => true]),
            'DELETE /api/security/bans' => Http::response(['ok' => true]),
            'GET /api/blocklists' => Http::response(['lists' => [['id' => 'firehol1', 'name' => 'FireHOL Level 1', 'description' => 'Bekannte Angreifer',
                'recommended' => true, 'custom' => false, 'urls' => [], 'enabled' => true, 'count' => 4500, 'fetched_at' => $now]],
                'status' => ['networks' => 4500, 'dropped' => 3]]),
            'GET /api/stats' => Http::response($this->statsOverride + ['range' => '24h', 'from' => $now - 86400000, 'to' => $now, 'computed_at' => $now, 'bucket_ms' => 3600000,
                'requests' => 12, 'visitors' => 3, 'bytes' => 5000, 'bytes_in' => 300, 'upload_since' => 0, 'errors_4xx' => 1, 'errors_5xx' => 0,
                'bots' => 2, 'avg_ms' => 12, 'p95_ms' => 40, 'series' => [$bucket],
                'hosts' => [['key' => 'app.example.de', 'count' => 10], ['key' => 'scan.example.de', 'count' => 2]],
                'paths' => [['key' => '/', 'count' => 12]], 'ips' => [['key' => '198.51.100.4', 'count' => 12]],
                'countries' => [['key' => 'DE', 'count' => 11, 'bytes' => 4000], ['key' => 'LAN', 'count' => 1, 'bytes' => 1000]],
                'clients' => [], 'protos' => [], 'statuses' => [['key' => '200', 'count' => 10]], 'referers' => []]),
            // PK95 Proxy with client filter; null = an older version (404)
            'GET /api/requests/clients' => $this->clientList === null ? Http::response(['error' => 'unbekannt'], 404) : Http::response(['range' => '24h', 'from' => $now - 86400000, 'to' => $now, 'computed_at' => $now, 'clients' => $this->clientList]),
            'GET /api/requests' => Http::response([['id' => 99, 'ts' => $now, 'host' => 'app.example.de', 'method' => 'GET', 'path' => '/login',
                'proto' => 'HTTP/2.0', 'status' => 404, 'bytes' => 512, 'bytes_in' => 0, 'duration_ms' => 3.2, 'ip' => '198.51.100.4', 'country' => 'DE',
                'client' => 'Firefox', 'is_bot' => false, 'ua' => 'Mozilla/5.0', 'referer' => '']]),
            default => Http::response(['error' => 'unbekannt: '.$r->method().' '.$path], 404),
        };
    }

    /** Query strings of the requests to /api/requests, in order. */
    private function requestQueries(): array
    {
        return array_values(array_map(fn ($c) => $c['query'], array_filter($this->calls, fn ($c) => $c['method'] === 'GET' && $c['path'] === '/api/requests')));
    }

    public function test_log_hides_clients_through_the_panel_and_remembers_them(): void
    {
        $this->clientList = [['client' => 'Uptime-Monitor', 'count' => 2880], ['client' => 'Firefox', 'count' => 12]];

        $page = Livewire::test(AccessLog::class, ['installedApp' => $this->installed])
            ->assertSee('Clients ausblenden …')
            ->assertViewHas('clientOptions', fn ($o) => $o[0] === ['value' => 'Uptime-Monitor', 'label' => 'Uptime-Monitor', 'hint' => '2.880'])
            ->assertSee('aria-label="Client Firefox ausblenden"', false)
            // on phones the client column is hidden: the details carry the same actions
            ->call('toggle', 99)
            ->assertSee('Nur dieser Client')
            ->assertSee('Client „Firefox“ ausblenden');
        $this->assertStringContainsString('range=24h', collect($this->calls)->firstWhere('path', '/api/requests/clients')['query']);

        $page->call('hideClient', 'Uptime-Monitor')
            ->assertSet('hidden', ['Uptime-Monitor'])
            ->assertSee('1 Client ausgeblendet')
            ->assertSee('Alle zeigen');
        $this->assertStringEndsWith('&exclude_client=Uptime-Monitor', last($this->requestQueries()));
        $this->assertSame(['Uptime-Monitor'], Settings::get(AccessLog::HIDDEN_SETTING));

        // repeated key per hidden client
        $page->call('hideClient', 'curl')->assertSee('2 Clients ausgeblendet');
        $this->assertStringEndsWith('&exclude_client=Uptime-Monitor&exclude_client=curl', last($this->requestQueries()));

        // the panel splits exclude_client at commas: such a name is refused, not silently ignored
        $page->call('hideClient', 'curl, wget')
            ->assertDispatched('notice', kind: 'error', text: '„curl, wget“ enthält ein Komma und lässt sich deshalb nicht ausblenden.')
            ->assertSet('hidden', ['Uptime-Monitor', 'curl']);

        $page->call('showClient', 'curl')->call('onlyClient', 'Firefox')->assertSee('Nur Client: Firefox');
        $this->assertStringContainsString('client=Firefox', last($this->requestQueries()));

        $page->call('clearClient')->call('showAllClients')->assertSet('hidden', [])->assertDontSee('data-client-chips', false);
        $this->assertStringNotContainsString('client', last($this->requestQueries()));
        $this->assertSame([], Settings::get(AccessLog::HIDDEN_SETTING));
    }

    public function test_the_client_multi_select_binds_the_hidden_list(): void
    {
        $this->clientList = [['client' => 'Uptime-Monitor', 'count' => 2880], ['client' => 'Firefox', 'count' => 12]];

        // wire:model.live="hidden" on the combobox: same rules as the row action
        $page = Livewire::test(AccessLog::class, ['installedApp' => $this->installed])
            ->set('hidden', ['Uptime-Monitor', 'Firefox'])
            ->assertSee('2 Clients ausgeblendet');
        $this->assertSame(['Uptime-Monitor', 'Firefox'], Settings::get(AccessLog::HIDDEN_SETTING));
        $this->assertStringEndsWith('&exclude_client=Uptime-Monitor&exclude_client=Firefox', last($this->requestQueries()));
        // hidden ones stay in the options, so they can be shown again
        $page->assertViewHas('clientOptions', fn ($o) => collect($o)->pluck('value')->all() === ['Uptime-Monitor', 'Firefox']);

        $page->set('hidden', ['Uptime-Monitor', 'curl, wget'])
            ->assertDispatched('notice', kind: 'error', text: '„curl, wget“ enthält ein Komma und lässt sich deshalb nicht ausblenden.')
            ->assertSet('hidden', ['Uptime-Monitor']);
    }

    public function test_hidden_clients_come_back_after_a_reload_and_the_url_wins(): void
    {
        $this->clientList = [['client' => 'Uptime-Monitor', 'count' => 2880]];
        Settings::put(AccessLog::HIDDEN_SETTING, ['Uptime-Monitor']);
        $log = route('apps.page', [$this->installed, 'log']);

        $this->get($log)->assertOk()->assertSee('1 Client ausgeblendet');
        $this->assertStringContainsString('exclude_client=Uptime-Monitor', last($this->requestQueries()));

        // a link with its own selection shows exactly that
        $this->get($log.'?hide[0]=Firefox')->assertOk();
        $this->assertStringEndsWith('&exclude_client=Firefox', last($this->requestQueries()));
    }

    public function test_an_older_proxy_gets_no_client_filter_and_nothing_is_filtered_here(): void
    {
        Settings::put(AccessLog::HIDDEN_SETTING, ['Uptime-Monitor']);

        $this->get(route('apps.page', [$this->installed, 'log']))->assertOk()
            ->assertSee('Clients ausblenden: braucht PK95 Proxy 1.3.0')
            ->assertSee('1 ausgeblendeter Client wirkt erst mit PK95 Proxy 1.3.0')
            ->assertDontSee('Client ausblenden …')
            ->assertDontSee('aria-label="Client Firefox ausblenden"', false)
            ->assertSee('Firefox');
        $this->assertStringNotContainsString('client', last($this->requestQueries()), 'no parameter the old panel would ignore');
    }

    public function test_every_page_renders_with_panel_data(): void
    {
        $expect = [
            'overview' => ['Anfragen im Zeitverlauf', 'Herkunft der Anfragen', 'unbekannt', 'Deutschland'],
            'log' => ['/login', 'Firefox'],
            'hosts' => ['app.example.de', 'Verbindung abgelehnt', 'Weiterleitung', 'Angriffsfilter'],
            'health' => ['Ausgefallen', '99,99 %', 'Ausfälle und Erholungen'],
            'streams' => ['Minecraft', '1 offen, 7 gesamt', 'Letzte Verbindungen'],
            'access-lists' => ['Familie', 'anna', 'Legt deine internen Netze fest'],
            'certificates' => ['mail.example.de', 'Automatisch', '5 Tage'],
            'security' => ['Ländersperre', '203.0.113.9', 'FireHOL Level 1', '1.200 Netzbereiche'],
            'error-pages' => ['Eigener Titel', 'Dienst nicht erreichbar'],
            'updates' => ['v2.10.2', 'Keine bekannten Schwachstellen'],
            'settings' => ['Standardseite', '1.234', 'Benachrichtigungen in Core'],
        ];
        foreach ($expect as $page => $texts) {
            $response = $this->get(route('apps.page', [$this->installed, $page]))->assertOk();
            foreach ($texts as $text) {
                $response->assertSee($text, false);
            }
            // @js() inside <x-…> attributes is not compiled and would end up as broken JS
            $response->assertDontSee('@js(', false);
        }
    }

    public function test_hosts_are_cards_below_md_and_a_table_from_md_on(): void
    {
        $html = $this->get(route('apps.page', [$this->installed, 'hosts']))->assertOk()->getContent();

        // one markup: table classes from md on, a grid card per host below
        $this->assertStringContainsString('block w-full text-left text-sm md:table', $html);
        $this->assertStringContainsString('hidden border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 md:table-header-group', $html);
        $this->assertStringContainsString('grid grid-cols-[auto_minmax(0,1fr)]', $html);
        $this->assertStringContainsString('md:table-row', $html);
        // column labels and touch-sized actions only on the card
        foreach (['Ziel', 'Zertifikat', 'Zugriff'] as $label) {
            $this->assertMatchesRegularExpression('/md:hidden[^"]*">'.$label.'</', $html);
        }
        $this->assertStringContainsString('max-md:min-h-11', $html);
        $this->assertStringContainsString('<span class="md:hidden">Löschen</span>', $html);
        $this->assertStringContainsString('<span class="md:sr-only">Wartung starten</span>', $html);
    }

    public function test_every_domain_can_be_opened_in_a_new_tab(): void
    {
        $this->hosts[3] = $this->host(3, ['www.example.de', 'example.de'], ['enabled' => false]);
        $this->hosts[4] = $this->host(4, ['*.lab.example.de'], ['enabled' => true]);

        $html = $this->get(route('apps.page', [$this->installed, 'hosts']))->assertOk()->getContent();

        // one icon per domain, https, a new tab without opener or referrer
        $this->assertSame(4, substr_count($html, 'data-host-open'), 'app, alt, www and example.de; not the wildcard');
        $this->assertStringContainsString('<a href="https://app.example.de" target="_blank" rel="noopener noreferrer" data-host-open', $html);
        $this->assertStringContainsString('data-tip="https://app.example.de in neuem Tab öffnen"', $html);
        $this->assertStringContainsString('aria-label="https://app.example.de in neuem Tab öffnen"', $html);
        $this->assertStringNotContainsString('https://*.lab.example.de', $html);
        // the domain itself still leads to its access log
        $this->assertStringContainsString('?host=app.example.de" wire:navigate data-tip="Zugriffe ansehen"', $html);
        // a disabled host: muted icon, its own tip, still named for screen readers
        $this->assertMatchesRegularExpression('/<a href="https:\/\/www\.example\.de" target="_blank" rel="noopener noreferrer" data-host-open\s+data-tip="Host ist deaktiviert"\s+aria-label="https:\/\/www\.example\.de in neuem Tab öffnen \(Host ist deaktiviert\)"\s+class="[^"]*text-gray-300/u', $html);
        // touch size on the card
        $this->assertStringContainsString('max-md:h-11 max-md:w-11', $html);
    }

    public function test_open_url_is_https_and_skips_wildcards(): void
    {
        $this->assertSame('https://app.example.de', Hosts::openUrl(' app.example.de '));
        $this->assertNull(Hosts::openUrl('*.example.de'));
        $this->assertNull(Hosts::openUrl(''));
    }

    // ---- page views per host and day (GET /api/hosts/daily, PK95 Proxy 1.4.0) ----------------

    /** @return array<string, mixed> the panel's answer, exactly in its format */
    private static function dailyFixture(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/proxy/hosts-daily.json')), true);
    }

    /** @return list<string> */
    private function dailyQueries(): array
    {
        return array_values(array_map(fn ($c) => $c['query'], array_filter($this->calls, fn ($c) => $c['path'] === '/api/hosts/daily')));
    }

    public function test_hosts_show_page_views_of_today_and_seven_days(): void
    {
        $this->daily = self::dailyFixture();

        $html = $this->get(route('apps.page', [$this->installed, 'hosts']))->assertOk()->getContent();

        // app.example.de: 100 today, 812 in 7 days, one bar per day with its tooltip
        $this->assertMatchesRegularExpression('/>100<\/span> heute\s*<span[^>]*><span class="md:hidden">\/ <\/span><span class="tabular-nums">812<\/span> in 7 Tagen/', $html);
        $this->assertStringContainsString('data-tip="14.11.: 100 Seitenaufrufe, 7 Besucher, 2.100 Zugriffe"', $html);
        $this->assertStringContainsString("data-tip=\"13.11.: 120 Seitenaufrufe, 4 Besucher, 2.100 Zugriffe\nGeschätzt, vor Einführung der Seitenzählung\"", $html);
        $this->assertStringContainsString('aria-label="Seitenaufrufe pro Tag: 08.11. 110, 09.11. 120, 10.11. 115', $html);
        $this->assertSame(2, substr_count($html, 'data-daily-bars'), 'one strip per host');
        // the header names what is left out; the card has its label
        $this->assertStringContainsString('<span data-tip="Seitenaufrufe ohne Bots">Aufrufe</span>', $html);
        $this->assertMatchesRegularExpression('/md:hidden[^"]*">Aufrufe</', $html);
        // bots are always left out
        $this->assertSame(['days=7&hide_bots=1'], $this->dailyQueries());
        $this->assertStringNotContainsString('data-daily-missing', $html);
    }

    public function test_page_views_leave_out_the_hidden_clients_and_say_so(): void
    {
        $this->installed->update(['version' => '1.4.0']);
        Settings::put(AccessLog::HIDDEN_SETTING, ['Uptime-Monitor', 'curl/8.0']);
        $this->daily = self::dailyFixture();

        $html = $this->get(route('apps.page', [$this->installed, 'hosts']))->assertOk()->getContent();
        $views = $this->get(route('apps.page', [$this->installed, 'views']))->assertOk()->getContent();

        $this->assertSame(['days=7&hide_bots=1&exclude_client=Uptime-Monitor&exclude_client=curl%2F8.0'], array_values(array_unique($this->dailyQueries())));
        $this->assertStringContainsString('data-tip="Seitenaufrufe ohne Bots und ohne 2 ausgeblendete Clients: Uptime-Monitor, curl/8.0"', $html);
        $this->assertMatchesRegularExpression('/Ohne Bots und ohne 2 ausgeblendete Clients\s*<span[^>]*>Uptime-Monitor, curl\/8\.0<\/span>/', $views);
        $this->assertStringContainsString('ändern unter Zugriffe', $views);
    }

    public function test_an_older_proxy_says_page_views_need_1_4_0(): void
    {
        $this->get(route('apps.page', [$this->installed, 'hosts']))->assertOk()
            ->assertSee('Aufrufe pro Tag: braucht PK95 Proxy 1.4.0');
        $this->get(route('apps.page', [$this->installed, 'views']))->assertOk()
            ->assertSee('Braucht PK95 Proxy 1.4.0')
            ->assertDontSee('data-views-table', false);
    }

    public function test_views_page_is_a_heatmap_of_hosts_and_days(): void
    {
        $this->daily = self::dailyFixture();

        $html = $this->get(route('apps.page', [$this->installed, 'views']))->assertOk()->getContent();

        // most page views first; totals per host and per day, and in all
        $this->assertTrue(strpos($html, '>app.example.de</a>') < strpos($html, '>doku.example.de</a>'));
        $this->assertTrue(strpos($html, '>doku.example.de</a>') < strpos($html, '>alt.example.de</a>'));
        $this->assertStringContainsString('<td class="px-2 py-2">105</td>', $html, '14.11.: 100 + 5 + 0');
        $this->assertStringContainsString('<td class="px-3 py-2">847</td>', $html, '812 + 35 + 0');
        // heatmap: the biggest cell is the strongest, an estimated day is italic with a tip
        $this->assertMatchesRegularExpression('/class="[^"]*bg-cerulean-500 text-white[^"]*italic"\s*>.*?data-tip="11\.11\.: 130 Seitenaufrufe/s', $html);
        $this->assertStringContainsString('data-tip="Geschätzt, vor Einführung der Seitenzählung"', $html);
        $this->assertStringContainsString('<span class="sr-only">, geschätzt, vor Einführung der Seitenzählung</span>', $html);
        $this->assertStringContainsString('data-tip="08.11.: keine Aufrufe"', $html, 'doku.example.de had none that day');
        // what is counted and since when
        $this->assertStringContainsString('Ohne Bots.', $html);
        $this->assertStringContainsString('Seitenaufrufe werden seit 14.11.2026 gezählt, frühere Tage sind geschätzt', $html);
        $this->assertStringContainsString('<caption class="sr-only">Seitenaufrufe pro Host und Tag, letzte 7 Tage</caption>', $html);
    }

    public function test_views_switch_metric_range_sort_and_host(): void
    {
        $this->daily = self::dailyFixture();

        $page = Livewire::test(Views::class, ['installedApp' => $this->installed])
            ->call('setMetric', 'visitors')
            ->assertSet('metric', 'visitors')
            ->assertSee('<td class="px-3 py-2">53</td>', false); // 41 + 9 + 3 visitors

        $this->assertInOrder($page->call('sortBy', 'host')->assertSet('dir', 'asc')->html(), ['>alt.example.de</a>', '>app.example.de</a>', '>doku.example.de</a>']);
        $this->assertInOrder($page->call('sortBy', 'host')->assertSet('dir', 'desc')->html(), ['>doku.example.de</a>', '>app.example.de</a>', '>alt.example.de</a>']);
        $this->assertInOrder($page->call('sortBy', '2026-11-13')->assertSet('sort', '2026-11-13')->html(), ['>app.example.de</a>', '>doku.example.de</a>']);

        // the combobox picks one host
        $page->set('host', 'doku.example.de')
            ->assertSee('>doku.example.de</a>', false)
            ->assertDontSee('>app.example.de</a>', false);

        // 30 days are asked from the panel; unknown values fall back
        $page->call('setDays', 30)->assertSet('days', 30);
        $this->assertContains('days=30&hide_bots=1', $this->dailyQueries());
        $page->call('setDays', 5)->assertSet('days', 7)
            ->call('setMetric', 'bytes')->assertSet('metric', 'pages')
            ->call('sortBy', 'x')->assertSet('sort', 'total');
    }

    /** @param list<string> $needles */
    private function assertInOrder(string $html, array $needles): void
    {
        $at = -1;
        foreach ($needles as $needle) {
            $pos = strpos($html, $needle, $at + 1);
            $this->assertNotFalse($pos, $needle.' after position '.$at);
            $at = $pos;
        }
    }

    // ---- who visited a host on a day (GET /api/hosts/visitors, PK95 Proxy 1.5.0) -------------

    /** @return array<string, mixed> the panel's answer, exactly in its format */
    private static function visitorsFixture(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/proxy/hosts-visitors.json')), true);
    }

    /** @return list<string> */
    private function visitorQueries(): array
    {
        return array_values(array_map(fn ($c) => $c['query'], array_filter($this->calls, fn ($c) => $c['path'] === '/api/hosts/visitors')));
    }

    public function test_a_number_opens_who_visited_that_host_on_that_day(): void
    {
        $this->daily = self::dailyFixture();
        $this->visitors = self::visitorsFixture();
        Settings::put('automation_public_ip', '203.0.113.50');
        // PK95 DNS knows the LAN address: its client name wins over the reverse DNS name
        InstalledApp::factory()->create(['app_id' => 'dns', 'version' => '1.0.0', 'status' => InstalledApp::RUNNING]);
        Http::fake(['host.docker.internal:8053/*' => Http::response([
            'clients' => [['id' => 1, 'name' => 'mac', 'ids' => ['10.10.10.134']]],
            'recent' => [['ip' => '10.10.10.134', 'hostname' => 'macbook.fritz.box', 'name' => '', 'client_id' => null]],
        ])]);

        // every number is a button with its tooltip
        $html = $this->get(route('apps.page', [$this->installed, 'views']))->assertOk()->getContent();
        $this->assertStringContainsString("wire:click=\"showVisitors('app.example.de', '2026-11-14')\" data-visitors-cell", $html);
        $this->assertStringContainsString('data-tip="14.11.: 100 Seitenaufrufe, 7 Besucher, 2.100 Zugriffe&#10;Besucher anzeigen"', $html);
        $this->assertStringContainsString('aria-label="app.example.de, 14.11.: 100 Seitenaufrufe. Besucher anzeigen"', $html);

        $page = Livewire::test(Views::class, ['installedApp' => $this->installed])
            ->call('showVisitors', 'app.example.de', '2026-11-14')
            ->assertSee('role="dialog" aria-modal="true"', false)
            ->assertSee('app.example.de · Sa 14.11.')
            ->assertSee('4 Besucher · 13 Seitenaufrufe · 224 Zugriffe');
        $dialog = $page->html();

        // most page views first; LAN name from PK95 DNS, country, own public IP, clients and time span
        $this->assertInOrder($dialog, ['>10.10.10.134<', '>91.97.75.147<', '>203.0.113.50<', '>185.220.101.7<']);
        $this->assertStringContainsString('data-ip-name>· mac</span>', $dialog);
        $this->assertStringContainsString('Lokales Netz', $dialog);
        $this->assertStringContainsString('Niederlande', $dialog);
        $this->assertSame(1, substr_count($dialog, 'eigene öffentliche IP'));
        $this->assertStringContainsString('Safari, Firefox · 07:02:11–19:45:00 Uhr', $dialog);
        // the paths, oldest first, with method, status and client; a hint when the list is cut
        $this->assertInOrder($dialog, ['/wp-login.php', '/.env', '/xmlrpc.php']);
        $this->assertSame(1, substr_count($dialog, 'data-paths-truncated'));
        // observations, no verdicts: only at the scanner
        $this->assertSame(3, substr_count($dialog, 'data-visitor-hint'));
        $this->assertStringContainsString('>Viele Zugriffe, wenige Seiten</span>', $dialog);
        $this->assertStringContainsString('>Nur Fehlerstatus</span>', $dialog);
        $this->assertStringContainsString('>Typische Scanner-Pfade</span>', $dialog);
        // open the requests of that visitor on that day; hide a client
        $this->assertStringContainsString('?host=app.example.de&amp;ip=91.97.75.147&amp;day=2026-11-14', $dialog);
        $this->assertStringContainsString('wire:click="hideClient('.Js::from('curl/8.0').')"', $dialog);
        $this->assertStringNotContainsString('data-visitors-estimated', $dialog);

        // page views only, then all requests; bots always out
        $page->set('visitAll', true);
        $this->assertSame([
            'host=app.example.de&date=2026-11-14&hide_bots=1&pages_only=1',
            'host=app.example.de&date=2026-11-14&hide_bots=1&pages_only=0',
        ], array_values(array_unique($this->visitorQueries())));

        $page->call('closeVisitors')->assertDontSee('data-modal', false);
    }

    public function test_visitors_of_an_estimated_day_and_of_an_older_proxy(): void
    {
        $this->daily = self::dailyFixture();

        Livewire::test(Views::class, ['installedApp' => $this->installed])
            ->call('showVisitors', 'app.example.de', '2026-11-13')
            ->assertSee('Seitenaufrufe an diesem Tag geschätzt')
            ->assertSee('„Nur Seitenaufrufe“ listet hier nur Besucher mit geschätzten Seitenaufrufen.')
            ->assertSee('Braucht PK95 Proxy 1.5.0')
            ->assertDontSee('data-visitors-summary', false)
            ->call('showVisitors', 'app.example.de', 'gestern')
            ->assertSet('visitDate', '2026-11-13');
    }

    public function test_a_client_hidden_in_the_dialog_is_left_out_everywhere(): void
    {
        $this->installed->update(['version' => '1.5.0']);
        $this->daily = self::dailyFixture();
        $this->visitors = self::visitorsFixture();

        Livewire::test(Views::class, ['installedApp' => $this->installed])
            ->call('showVisitors', 'app.example.de', '2026-11-14')
            ->call('hideClient', 'curl/8.0')
            ->assertDispatched('notice', kind: 'success', text: '„curl/8.0“ ist ausgeblendet, auch in den Zugriffen und Aufrufen.')
            ->call('hideClient', 'a, b')
            ->assertDispatched('notice', kind: 'error', text: '„a, b“ enthält ein Komma und lässt sich deshalb nicht ausblenden.');

        $this->assertSame(['curl/8.0'], Settings::get(AccessLog::HIDDEN_SETTING));
        $this->assertStringEndsWith('&exclude_client=curl%2F8.0', $this->visitorQueries()[array_key_last($this->visitorQueries())]);
    }

    public function test_the_access_log_filters_one_day(): void
    {
        $page = Livewire::test(AccessLog::class, ['installedApp' => $this->installed])
            ->set('day', '2026-11-14')
            ->assertSee('Tag: Sa 14.11.')
            // PK95 Proxy 1.0.0 cannot filter by time yet: say so instead of showing every day silently
            ->assertSee('Tagesfilter braucht PK95 Proxy 1.5.0');
        // local midnight to midnight (Europe/Berlin, UTC+1 in November)
        $this->assertStringContainsString('from=1794610800000&to=1794697200000', $this->requestQueries()[array_key_last($this->requestQueries())]);

        $page->call('clearDay')->assertSet('day', '')->assertDontSee('data-day-filter', false);
        $this->assertStringNotContainsString('from=', $this->requestQueries()[array_key_last($this->requestQueries())]);

        $this->installed->update(['version' => '1.5.0']);
        Livewire::test(AccessLog::class, ['installedApp' => $this->installed->fresh()])->set('day', '2026-11-14')
            ->assertDontSee('Tagesfilter braucht', false);
        // a build without a version number: unknown, so no hint
        $this->installed->update(['version' => 'dev']);
        Livewire::test(AccessLog::class, ['installedApp' => $this->installed->fresh()])->set('day', '2026-11-14')
            ->assertDontSee('Tagesfilter braucht', false);
    }

    public function test_names_without_a_host_of_their_own_are_a_group_at_the_end(): void
    {
        $daily = self::dailyFixture();
        $add = function (string $host, string $type, int $pages) use (&$daily): void {
            $daily['hosts'][] = ['host' => $host, 'host_type' => $type,
                'total' => ['pages' => $pages, 'visitors' => 2, 'requests' => 50],
                'series' => [['date' => '2026-11-14', 'pages' => $pages, 'visitors' => 2, 'requests' => 50, 'estimated' => false]]];
        };
        $add('', 'none', 900);
        $add('old.example.de', 'unknown', 7);
        $add('203.0.113.9', 'ip', 4);
        $this->daily = $daily;
        $visitors = self::visitorsFixture();
        $this->visitors = ['host' => '', 'host_type' => 'none', 'total_visitors' => 1234, 'visitors_truncated' => true] + $visitors;

        $html = $this->get(route('apps.page', [$this->installed, 'views']))->assertOk()->getContent();

        // even with the most page views they come after the configured hosts, marked as what they are
        $this->assertInOrder($html, ['>app.example.de</a>', '>alt.example.de</a>', 'data-other-hosts', 'ohne Host-Header', '>old.example.de</a>', '>203.0.113.9</a>']);
        $this->assertSame(1, substr_count($html, '>ohne Host / direkte IP</span>'), 'the empty host names itself');
        $this->assertStringContainsString('data-host-type="unknown">unbekannter Name</span>', $html);
        // no Host header: no link to a host filter, but its numbers open the visitors
        $this->assertStringNotContainsString('?host=" wire:navigate', $html);
        $this->assertStringContainsString("wire:click=\"showVisitors('', '2026-11-14')\"", $html);
        // and it is no choice in the host search
        $this->assertStringNotContainsString('{&quot;value&quot;:&quot;&quot;', $html);

        $dialog = Livewire::test(Views::class, ['installedApp' => $this->installed])
            ->call('showVisitors', '', '2026-11-14')
            ->assertSet('visitHost', '')
            ->assertSee('ohne Host-Header · Sa 14.11.')
            ->assertSee('Kein eingerichteter Host.')
            ->assertSee('Gezeigt werden die 4 Besucher mit den meisten Aufrufen, insgesamt waren es 1.234.')
            ->html();
        // host and date are required by the panel: an empty host is sent as host=
        $this->assertStringStartsWith('host=&date=2026-11-14&hide_bots=1&pages_only=1', $this->visitorQueries()[0]);
        $this->assertStringContainsString('?ip=91.97.75.147&amp;day=2026-11-14', $dialog);
    }

    // ---- automatic bans: the repeat rule of PK95 Proxy 1.6.0 -------------------------------------

    /** @return list<array<string, mixed>> bodies of PUT /api/security/guard */
    private function guardPuts(): array
    {
        return array_values(array_map(fn ($c) => $c['body'], array_filter($this->calls, fn ($c) => $c['method'] === 'PUT' && $c['path'] === '/api/security/guard')));
    }

    public function test_an_older_proxy_gets_no_repeat_rule(): void
    {
        $this->get(route('apps.page', [$this->installed, 'security']))->assertOk()
            ->assertDontSee('id="g-repeat_hits"', false)
            // scans count on redirects to the login too (Proxy 1.6.0, text only)
            ->assertSee('Etwa /.env oder /.git/config, auch bei Weiterleitungen auf das Login.');

        // the panel keeps fields that are not sent: Core sends only what the panel knows
        Livewire::test(Security::class, ['installedApp' => $this->installed])->call('saveGuard')
            ->assertDispatched('notice', kind: 'success', text: 'Gespeichert.');
        $this->assertArrayNotHasKey('repeat_hits', $this->guardPuts()[0]);
        $this->assertSame(3, $this->guardPuts()[0]['scan_hits']);
    }

    public function test_the_repeat_rule_with_proxy_1_6_and_its_bans(): void
    {
        $this->guardExtra = ['repeat_hits' => 30];
        $this->extraBans = [['ip' => '198.51.100.4', 'country' => 'DE', 'reason' => 'repeat', 'strikes' => 1, 'detail' => '30× /accounts/login/ in 52 s',
            'host' => 'app.example.de', 'created_at' => 1_794_600_000_000, 'expires_at' => 0]];

        $this->get(route('apps.page', [$this->installed, 'security']))->assertOk()
            ->assertSee('id="g-repeat_hits"', false)
            ->assertSeeInOrder(['Wiederholungen', 'Dieselbe Seite oder Anmeldung immer wieder, bei mindestens zwei Dritteln der Seitenaufrufe.'])
            // the ban list names the new reason with the panel's detail
            ->assertSeeInOrder(['198.51.100.4', 'Wiederholung', '30× /accounts/login/ in 52 s']);

        Livewire::test(Security::class, ['installedApp' => $this->installed])
            ->assertSet('guard.repeat_hits', '30')
            ->set('guard.repeat_hits', '45')
            ->call('saveGuard')
            ->assertSet('guard.repeat_hits', '45');
        $this->assertSame(45, $this->guardPuts()[0]['repeat_hits']);
    }

    // ---- web firewall (Coraza + OWASP CRS, PK95 Proxy 1.8.0) ------------------------------------

    /** Bodies of the requests with this method and path, in order. */
    private function bodies(string $method, string $path): array
    {
        return array_values(array_map(fn ($c) => $c['body'], array_filter($this->calls, fn ($c) => $c['method'] === $method && $c['path'] === $path)));
    }

    /** Query string of the last request to this path. */
    private function lastQuery(string $path): string
    {
        return (string) (last(array_filter($this->calls, fn ($c) => $c['path'] === $path))['query'] ?? '');
    }

    public function test_an_older_proxy_without_waf_shows_nothing_of_it(): void
    {
        $this->get(route('apps.page', [$this->installed, 'hosts']))->assertOk()->assertDontSee('data-waf-badge', false);
        Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('edit', 1)
            ->assertSet('wafOn', false)->assertDontSee('data-waf-form', false)->assertDontSee('Web-Firewall')
            ->call('save')->assertSet('editing', null);
        $this->assertSame([], array_filter($this->calls, fn ($c) => str_contains($c['path'], '/waf') && $c['method'] !== 'GET'));

        Livewire::withoutLazyLoading()->test(WebFirewall::class, ['installedApp' => $this->installed])->assertSee('Keine Web-Firewall')->assertDontSee('data-waf-summary', false);
        $this->get(route('apps.page', [$this->installed, 'security']))->assertOk()->assertDontSee('id="g-waf_blocks"', false);

        Cache::flush();
        $f = app(AppFigures::class)->for($this->installed);
        $this->assertNull($f['waf']);
        $this->assertSame([], $f['warnings']);
        Livewire::withoutLazyLoading()->test(AppPanel::class, ['appId' => 'proxy'])->assertDontSee('Web-Firewall heute');
    }

    public function test_caddy_without_the_waf_module_hides_it_instead_of_a_red_problem(): void
    {
        $this->enableWaf();
        $hidden = function (): void {
            Livewire::withoutLazyLoading()->test(WebFirewall::class, ['installedApp' => $this->installed])->assertSee('Keine Web-Firewall')->assertDontSee('data-waf-rules-error', false);
            Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('edit', 1)->assertSet('wafOn', false);
            Cache::flush();
            $f = app(AppFigures::class)->for($this->installed);
            $this->assertNull($f['waf']);
            $this->assertSame([], $f['warnings']);
        };

        // "module": false is the sign, whatever the error says
        $this->waf['status'] = ['crs_version' => '', 'coraza_version' => '', 'module' => false, 'rules_loaded' => false, 'error' => 'Standalone-Build', 'hosts' => []];
        $hidden();

        // without the field: the error text as the fallback
        $this->waf['status'] = ['crs_version' => '', 'coraza_version' => '', 'rules_loaded' => false, 'error' => 'Caddy ohne WAF-Modul', 'hosts' => []];
        $hidden();

        // the module is there: rules that did not load are a red problem, even with that text
        $this->waf['status'] = ['crs_version' => '4.10.0', 'coraza_version' => '3.3.0', 'module' => true, 'rules_loaded' => false, 'error' => 'Caddy ohne WAF-Modul', 'hosts' => []];
        Cache::flush();
        $this->assertSame('bad', app(AppFigures::class)->for($this->installed)['warnings'][0]['tone']);
        Livewire::withoutLazyLoading()->test(WebFirewall::class, ['installedApp' => $this->installed])->assertSeeHtml('data-waf-rules-error');
    }

    public function test_the_host_form_edits_the_waf_and_deletes_an_exception(): void
    {
        $this->enableWaf();
        $page = Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('edit', 1)
            ->assertSet('wafOn', true)->assertSet('waf.mode', 'detect')->assertSet('waf.anomaly_threshold', '5')
            ->assertSee('Web-Firewall')->assertSee('WordPress')->assertSee('Nimmt Regeln für den Block-Editor zurück.')
            ->assertSee('Regel 920350')->assertSee('unter /api/')->assertSee('App sendet IP als Host');

        // the panel's 422 lands on the field, the dialog stays open
        $page->set('waf.anomaly_threshold', '1')->call('save')->assertHasErrors('waf.anomaly_threshold')->assertSet('editing', '1');

        // a partial update without the exceptions: one added elsewhere meanwhile stays
        $this->waf['hosts'][1]['exceptions'][] = ['id' => 9, 'rule_id' => 941100, 'path_prefix' => '/editor', 'note' => '', 'created_at' => 2];
        $page->set('waf.mode', 'block')->set('waf.paranoia', '2')->set('waf.anomaly_threshold', '7')->set('waf.body_limit_kb', '512')
            ->set('waf.profiles', ['wordpress'])->call('save')->assertHasNoErrors()->assertSet('editing', null)
            ->assertDispatched('notice', kind: 'success', text: 'Gespeichert und in Caddy übernommen.');
        $this->assertSame(['mode' => 'block', 'paranoia' => 2, 'anomaly_threshold' => 7, 'body_limit_kb' => 512, 'profiles' => ['wordpress']],
            last($this->bodies('PUT', '/api/hosts/1/waf')));
        $this->assertSame([1, 9], array_column($this->waf['hosts'][1]['exceptions'], 'id'));
        $this->assertArrayNotHasKey('waf', last($this->bodies('PUT', '/api/hosts/1')), 'the host PUT stays as it was');

        Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('edit', 1)->assertSet('waf.mode', 'block')
            ->call('deleteException', '1')->assertDispatched('notice', kind: 'success', text: 'Ausnahme gelöscht.')
            ->assertSet('wafExceptions', fn ($list) => array_column($list, 'id') === [9]);
        $this->assertCount(1, array_filter($this->calls, fn ($c) => $c['method'] === 'DELETE' && $c['path'] === '/api/hosts/1/waf/exceptions/1'));
    }

    public function test_a_new_reverse_proxy_gets_its_waf_settings_and_a_redirect_none(): void
    {
        $this->enableWaf();
        Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('create')
            ->assertSet('wafOn', true)->assertSet('waf.mode', 'detect')->assertDontSee('data-waf-exceptions', false)
            ->set('form.domains', 'neu.example.de')->set('form.upstream_host', '10.0.0.7')->set('form.upstream_port', '80')
            ->set('waf.mode', 'block')->call('save')->assertSet('editing', null);
        $this->assertSame('block', last($this->bodies('PUT', '/api/hosts/3/waf'))['mode']);

        // a redirect has no web firewall: no section, no PUT
        Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('edit', 2)->assertDontSee('data-waf-form', false)->call('save');
        $this->assertSame([], $this->bodies('PUT', '/api/hosts/2/waf'));
    }

    public function test_the_host_list_shows_the_waf_mode_and_load_errors(): void
    {
        $this->enableWaf();
        $this->hosts[4] = $this->host(4, ['shop.example.de'], ['enabled' => true]);
        $this->waf['hosts'][4] = ['mode' => 'block'] + $this->waf['hosts'][1];
        $this->hosts[5] = $this->host(5, ['wiki.example.de'], ['enabled' => true]);
        $this->waf['status']['hosts'][] = ['host_id' => 5, 'domain' => 'wiki.example.de', 'mode' => 'detect', 'error' => 'Profil nextcloud nicht gefunden'];

        $html = $this->get(route('apps.page', [$this->installed, 'hosts']))->assertOk()->getContent();
        $this->assertStringContainsString('data-waf-badge="detect"', $html);
        $this->assertStringContainsString('data-waf-badge="block"', $html);
        $this->assertSame(1, substr_count($html, 'data-waf-badge="error"'));
        $this->assertStringContainsString('Profil nextcloud nicht gefunden', $html);

        Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('edit', 5)->assertSee('Regeln nicht geladen')->assertSee('läuft gerade ohne Web-Firewall');
    }

    public function test_the_web_firewall_page_filters_hits_and_creates_an_exception(): void
    {
        $this->enableWaf();
        $this->waf['status']['hosts'][0]['error'] = 'Regeldatei fehlt';
        $page = Livewire::withoutLazyLoading()->test(WebFirewall::class, ['installedApp' => $this->installed])
            ->assertSee('CRS 4.10.0')->assertSee('app.example.de läuft ohne Web-Firewall:')->assertSee('Regeldatei fehlt')
            ->assertSeeHtml('data-waf-hits>37')->assertSeeHtml('data-waf-blocks>4');
        $this->assertMatchesRegularExpression('/from=\d+&to=\d+/', $this->lastQuery('/api/waf/stats'));
        $page->call('show', 'protokoll')
            ->assertSee('SQL Injection Attack Detected via libinjection')->assertSee('in ARGS:q')->assertSee('198.51.100.23')->assertSee('10 Punkte');
        // the score rule 949110 gets no „Ausnahme anlegen“: it would switch off blocking on the path
        $this->assertSame(1, substr_count($page->html(), 'data-waf-except'));

        // filters go to the panel with its names (host = id, action detect|block); the figures follow the host
        $page->call('filterBy', 'rule', '942100')->set('action', 'block')->call('filterBy', 'host', '1')->call('period', '7');
        foreach (['rule=942100', 'action=block', 'host=1', 'limit=100'] as $part) {
            $this->assertStringContainsString($part, $this->lastQuery('/api/waf/events'));
        }
        $page->call('show', 'uebersicht');
        $this->assertStringContainsString('host=1', $this->lastQuery('/api/waf/stats'));
        $page->call('clearFilters')->assertSet('rule', '')->assertSet('host', '');

        // „Ausnahme anlegen“ from a rule of the hit: host, rule and the path without query
        $page->call('newException', 1, 'app.example.de', '942100', '/search?q=1%27+or+1=1')
            ->assertSet('exception.path_prefix', '/search')->assertSet('exception.rule_id', '942100')->assertSee('Ausnahme für app.example.de')
            ->set('exception.note', 'Suche erlaubt Sonderzeichen')->call('saveException')->assertSet('exception', null)
            ->assertDispatched('notice', kind: 'success', text: 'Ausnahme angelegt: Regel 942100 unter /search (app.example.de).');
        $this->assertSame(['rule_id' => 942100, 'path_prefix' => '/search', 'note' => 'Suche erlaubt Sonderzeichen'], last($this->bodies('POST', '/api/hosts/1/waf/exceptions')));

        // the same again: the panel's 409 as a message, the dialog stays
        $page->call('newException', 1, 'app.example.de', '942100', '/search')->call('saveException')
            ->assertDispatched('notice', kind: 'error', text: 'Diese Ausnahme gibt es schon.')->assertNotSet('exception', null);

        // the score rule is refused before it reaches the panel
        $before = count($this->bodies('POST', '/api/hosts/1/waf/exceptions'));
        $page->call('newException', 1, 'app.example.de', '949110', '/search')->call('saveException')->assertHasErrors('exception.rule_id');
        $this->assertCount($before, $this->bodies('POST', '/api/hosts/1/waf/exceptions'));
    }

    public function test_tile_and_problems_show_the_waf(): void
    {
        $this->enableWaf();
        $this->waf['status']['hosts'][0]['error'] = 'Regeldatei fehlt';
        Cache::flush();
        $f = app(AppFigures::class)->for($this->installed);
        $this->assertSame([37, 4], [$f['waf']['hits'], $f['waf']['blocks']]);
        $this->assertSame(942100, $f['waf']['rules'][0]['rule_id']);
        // today: from local midnight (ms) to now
        $this->assertStringContainsString('from='.now(Format::timezone())->startOfDay()->getTimestampMs(), $this->lastQuery('/api/waf/stats'));

        Livewire::withoutLazyLoading()->test(AppPanel::class, ['appId' => 'proxy'])
            ->assertSee('Web-Firewall heute')->assertSee('37 Treffer')->assertSee('4 blockiert')->assertSeeHtml('data-waf-tile-rules');
        $this->assertContains(['tone' => 'bad', 'text' => 'Web-Firewall für app.example.de nicht geladen, der Host läuft ohne Schutz: Regeldatei fehlt',
            'href' => route('apps.page', [$this->installed, 'waf'])], app(Problems::class)->collect());

        // the rules did not load at all: red, for every host
        Cache::flush();
        $this->waf['status']['rules_loaded'] = false;
        $this->waf['status']['error'] = 'CRS-Regeln beschädigt';
        $this->assertContains(['tone' => 'bad', 'text' => 'Web-Firewall: Die Regeln sind nicht geladen, kein Host ist geschützt: CRS-Regeln beschädigt',
            'href' => route('apps.page', [$this->installed, 'waf'])], app(Problems::class)->collect());
        Livewire::withoutLazyLoading()->test(WebFirewall::class, ['installedApp' => $this->installed])->assertSee('Die Regeln sind nicht geladen, kein Host ist geschützt:');

        // stats not answering: the line stays, without figures
        Cache::flush();
        unset($this->waf['stats']['hits']);
        $this->assertNull(app(AppFigures::class)->for($this->installed)['waf']['hits']);
    }

    public function test_the_guard_bans_after_waf_blocks_when_the_proxy_knows_it(): void
    {
        $this->guardExtra = ['waf_blocks' => 0];
        $this->extraBans = [['ip' => '198.51.100.23', 'country' => 'NL', 'reason' => 'waf', 'strikes' => 1, 'detail' => '12 Blocks', 'host' => 'app.example.de',
            'created_at' => 1_794_600_000_000, 'expires_at' => 0]];
        $this->get(route('apps.page', [$this->installed, 'security']))->assertOk()
            ->assertSee('id="g-waf_blocks"', false)->assertSee('Von der Web-Firewall blockiert')
            ->assertSeeInOrder(['198.51.100.23', 'Web-Firewall', '12 Blocks']);
        Livewire::test(Security::class, ['installedApp' => $this->installed])->set('guard.waf_blocks', '10')->call('saveGuard')->assertSet('guard.waf_blocks', '10');
        $this->assertSame(10, $this->guardPuts()[0]['waf_blocks']);
    }

    // ---- certificates for other applications: on the LXC below /srv/pk95/certs ------------------

    /** The proxy container as Docker reports it, with or without the folder on the LXC. */
    private function proxyContainer(bool $withBind): void
    {
        $mounts = [['Type' => 'volume', 'Source' => 'pk95-proxy-data', 'Target' => '/var/lib/proxypanel']];
        if ($withBind) {
            $mounts[] = ['Type' => 'bind', 'Source' => '/srv/pk95/certs', 'Target' => '/var/lib/proxypanel/acme-export'];
        }
        $this->docker['containers']['cid-pk95-proxy'] = ['Id' => 'cid-pk95-proxy', 'Name' => 'pk95-proxy', 'running' => true,
            'spec' => ['Image' => 'ghcr.io/philippkuhlmann/pk95-proxy:latest', 'HostConfig' => ['Mounts' => $mounts]]];
    }

    public function test_certificates_can_be_fetched_from_the_lxc_with_scp(): void
    {
        Settings::put('automation_lan_ip', '192.168.10.50');
        $this->installed->update(['version' => '1.7.0']);
        $this->proxyContainer(withBind: true);

        $html = $this->get(route('apps.page', [$this->installed, 'certificates']))->assertOk()->getContent();

        // the path on the LXC instead of the one in the container, to copy
        $this->assertStringContainsString('data-host-path', $html);
        $this->assertStringContainsString('/srv/pk95/certs/mail.example.de/fullchain.pem', $html);
        $this->assertStringContainsString('/srv/pk95/certs/mail.example.de/privkey.pem', $html);
        $this->assertStringNotContainsString('/var/lib/proxypanel/acme-export/mail.example.de/fullchain.pem', $html);
        // scp once and as a cron line, with the user certsync that has to exist on the LXC
        $this->assertStringContainsString('scp certsync@192.168.10.50:/srv/pk95/certs/mail.example.de/fullchain.pem ./', $html);
        $this->assertStringContainsString('scp certsync@192.168.10.50:/srv/pk95/certs/mail.example.de/privkey.pem ./', $html);
        $this->assertStringContainsString('15 4 * * * scp -q certsync@192.168.10.50:/srv/pk95/certs/mail.example.de/fullchain.pem certsync@192.168.10.50:/srv/pk95/certs/mail.example.de/privkey.pem /etc/ssl/mail.example.de/', $html);
        $this->assertStringContainsString('der Benutzer certsync eingerichtet sein', $html);
        $this->assertStringNotContainsString('data-bind-missing', $html);
        $this->assertStringNotContainsString('data-group-too-old', $html);
    }

    public function test_the_scp_box_follows_the_chosen_domain(): void
    {
        Settings::put('automation_lan_ip', '192.168.10.50');
        $this->proxyContainer(withBind: true);
        $this->extraAcme = [['id' => 8, 'domain' => 'test1.example.de', 'note' => '', 'created_at' => 1,
            'path' => '/var/lib/proxypanel/acme-export/test1.example.de', 'status' => ['issuer' => "Let's Encrypt", 'not_after' => now()->getTimestamp() + 80 * 86400]]];

        $html = $this->get(route('apps.page', [$this->installed, 'certificates']))->assertOk()->getContent();

        // the dropdown (client state, x-model) with every exported domain, the first chosen
        $this->assertMatchesRegularExpression('/x-model="domain"[^>]*x-data="combobox\(/s', $html);
        $this->assertStringContainsString('data-scp-domain', $html);
        $this->assertStringContainsString('&quot;value&quot;:&quot;test1.example.de&quot;', $html);
        $this->assertStringContainsString("domain: 'mail.example.de'", $html);
        // @js writes JSON with \u0022 for the quotes
        $this->assertStringContainsString(str_replace('"', chr(92).'u0022', '"test1.example.de":"'), $html);
        // the commands follow it; the first domain is there before Alpine runs
        $this->assertStringContainsString('x-text="&#039;scp &#039; + src(&#039;fullchain.pem&#039;) + &#039; ./&#039;"', $html);
        $this->assertStringContainsString('>scp certsync@192.168.10.50:/srv/pk95/certs/mail.example.de/fullchain.pem ./</span>', $html);
        // all domains at once: scp -r over SFTP, each into /etc/ssl/<domain>/
        $this->assertStringContainsString('15 4 * * * scp -rq certsync@192.168.10.50:/srv/pk95/certs/mail.example.de certsync@192.168.10.50:/srv/pk95/certs/test1.example.de /etc/ssl/', $html);
        $this->assertStringContainsString('scp ab OpenSSH 9', $html);
        // "Abholen" in every row chooses the domain and scrolls to the box
        $this->assertSame(2, substr_count($html, 'data-scp-pick="data-scp-pick"'));
        $this->assertStringContainsString("x-on:click=\"\$dispatch('certsync-pick', 'test1.example.de')\"", $html);
        $this->assertStringContainsString('x-on:certsync-pick.window=', $html);
    }

    public function test_one_domain_needs_no_choice(): void
    {
        $this->proxyContainer(withBind: true);

        $html = $this->get(route('apps.page', [$this->installed, 'certificates']))->assertOk()->getContent();

        $this->assertStringContainsString('data-scp', $html);
        $this->assertStringNotContainsString('data-scp-domain', $html);
        $this->assertStringNotContainsString('data-scp-all', $html);
        $this->assertSame(1, substr_count($html, 'data-scp-pick="data-scp-pick"'));
    }

    public function test_a_proxy_container_without_the_folder_has_to_be_created_again(): void
    {
        $this->proxyContainer(withBind: false);

        $html = $this->get(route('apps.page', [$this->installed, 'certificates']))->assertOk()->getContent();

        $this->assertStringContainsString('data-bind-missing', $html);
        $this->assertStringContainsString('Erstelle PK95 Proxy einmal neu', $html);
        $this->assertStringContainsString(route('apps.show', $this->installed), $html);
        // until then the files are only in the container
        $this->assertStringContainsString('/var/lib/proxypanel/acme-export/mail.example.de/fullchain.pem', $html);
        $this->assertStringNotContainsString('data-scp', $html);
    }

    public function test_without_lan_ip_and_with_an_older_proxy_the_scp_box_says_what_is_missing(): void
    {
        // Docker gives no answer about the container: no warning, the paths of the catalog
        $html = $this->get(route('apps.page', [$this->installed, 'certificates']))->assertOk()->getContent();

        $this->assertStringContainsString('scp certsync@&lt;LAN-IP&gt;:/srv/pk95/certs/mail.example.de/fullchain.pem ./', $html);
        $this->assertStringContainsString('Statt &lt;LAN-IP&gt; die Adresse des LXC einsetzen.', $html);
        // PK95 Proxy 1.0.0 does not set the group yet
        $this->assertStringContainsString('data-group-too-old', $html);
        $this->assertStringNotContainsString('data-bind-missing', $html);
    }

    // ---- the SFTP user certsync: keys in /opt/pk95/ssh/authorized_keys/certsync ----------------

    /** A temporary data root with the files install/certsync.sh leaves; returns the key file. */
    private function certsyncData(?int $gid = 2001, array $lines = []): string
    {
        $dir = sys_get_temp_dir().'/pk95-certsync-'.bin2hex(random_bytes(4));
        mkdir($dir.'/ssh/authorized_keys', 0755, true);
        config(['pk95.data_dir' => $dir]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($dir));
        if ($gid !== null) {
            file_put_contents($dir.'/ssh/certsync.env', "CERTS_GID={$gid}\nCERTSYNC_UID=2001\n");
        }
        file_put_contents($dir.'/ssh/authorized_keys/certsync', implode("\n", $lines)."\n");

        return $dir.'/ssh/authorized_keys/certsync';
    }

    /** @return list<array<string, mixed>> helper runs that wrote the key file */
    private function keyWrites(): array
    {
        return array_values(array_filter($this->docker['helperRuns'], fn ($r) => ($r['mounts'][0]['Target'] ?? '') === '/keys'));
    }

    public function test_the_certificates_page_lists_the_keys_of_certsync(): void
    {
        $mac = CertsyncKeysTest::key('ssh-rsa', 4096, 'philipp@mac');
        $this->certsyncData(2001, [$mac, 'command="/bin/sh" '.CertsyncKeysTest::key('ssh-ed25519')]);

        $html = $this->get(route('apps.page', [$this->installed, 'certificates']))->assertOk()->getContent();

        $this->assertStringContainsString('Zugang für certsync (SSH-Schlüssel)', $html);
        $this->assertStringContainsString('philipp@mac', $html);
        $this->assertStringContainsString('RSA 4096 Bit', $html);
        $this->assertStringContainsString(Certsync::parse($mac)['fingerprint'], $html);
        // a line against the rules (options) is shown as such and dropped on the next change
        $this->assertStringContainsString('Eine Zeile der Schlüsseldatei entspricht nicht diesen Regeln', $html);
        $this->assertStringNotContainsString('data-certsync-missing', $html);
        $this->assertStringNotContainsString('data-gid-mismatch', $html);
    }

    public function test_certsync_not_set_up_or_with_another_gid_is_said(): void
    {
        $this->certsyncData(null);
        $this->get(route('apps.page', [$this->installed, 'certificates']))->assertOk()
            ->assertSee('data-certsync-missing', false)
            ->assertSee('install/certsync.sh', false)
            ->assertSee('Noch kein Schlüssel eingetragen.');

        $this->certsyncData(3000);
        $this->get(route('apps.page', [$this->installed, 'certificates']))->assertOk()
            ->assertSee('data-gid-mismatch', false)
            ->assertSee('Die Gruppe pk95-certs hat auf dem LXC die gid 3000, in Core ist 2001 eingestellt.');
    }

    public function test_keys_are_added_and_removed_by_a_root_helper(): void
    {
        $old = CertsyncKeysTest::key('ssh-ed25519', comment: 'alt');
        $file = $this->certsyncData(2001, [$old]);
        $new = CertsyncKeysTest::key('ecdsa-sha2-nistp256', comment: 'nas');

        $page = Livewire::test(Certificates::class, ['installedApp' => $this->installed])
            ->set('newKey', 'from="10.0.0.1" '.$new)->call('addKey')
            ->assertHasErrors(['newKey'])
            ->set('newKey', CertsyncKeysTest::key('ssh-rsa', 2048))->call('addKey')
            ->assertHasErrors(['newKey'])
            ->set('newKey', $old)->call('addKey')
            ->assertHasErrors(['newKey']);
        $this->assertSame([], $this->keyWrites(), 'nothing written for a rejected key');

        $page->set('newKey', $new)->call('addKey')
            ->assertHasNoErrors()
            ->assertSet('newKey', '')
            ->assertDispatched('notice', kind: 'success', text: 'Schlüssel für certsync hinzugefügt ('.Certsync::parse($new)['fingerprint'].').');

        // as root next to the file, 0644 and renamed into place (sshd StrictModes)
        $write = $this->keyWrites()[0];
        $this->assertSame([['Type' => 'bind', 'Source' => dirname($file), 'Target' => '/keys', 'BindOptions' => ['CreateMountpoint' => true]]], $write['mounts']);
        $this->assertSame('sh', $write['cmd'][0]);
        $this->assertSame("umask 022 && printf '%s\\n' '".Certsync::parse($old)['line']."' '".Certsync::parse($new)['line']."' > /keys/.certsync.new && chmod 0644 /keys/.certsync.new && mv -f /keys/.certsync.new /keys/certsync", $write['cmd'][2]);

        // removing writes the rest; the last one empties the file
        $page->call('removeKey', Certsync::parse($old)['fingerprint'])
            ->assertDispatched('notice', kind: 'success', text: 'Schlüssel entfernt. Dieser Rechner kann die Zertifikate nicht mehr abholen.');
        $this->assertSame('umask 022 && true > /keys/.certsync.new && chmod 0644 /keys/.certsync.new && mv -f /keys/.certsync.new /keys/certsync', $this->keyWrites()[1]['cmd'][2]);

        // the helper fails: a message, no change
        $this->docker['helperExit'] = 1;
        $this->docker['helperOutput'] = 'Read-only file system';
        $page->set('newKey', $new)->call('addKey')
            ->assertDispatched('notice', kind: 'error', text: 'Die Schlüsseldatei ließ sich nicht schreiben: Read-only file system');
    }

    public function test_visitor_helpers(): void
    {
        $this->assertTrue(Visitors::isLan('10.10.10.134'));
        $this->assertTrue(Visitors::isLan('192.168.1.2'));
        $this->assertTrue(Visitors::isLan('fd00::1'));
        $this->assertFalse(Visitors::isLan('91.97.75.147'));
        $this->assertFalse(Visitors::isLan('kein.ip'));

        $v = fn (int $pages, int $requests, array $paths) => ['pages' => $pages, 'requests' => $requests, 'is_bot' => false,
            'paths' => array_map(fn ($p) => ['path' => $p[0], 'status' => $p[1]], $paths)];
        $this->assertSame([], Visitors::hints($v(12, 340, [['/login', 200], ['/x', 404]])));
        $this->assertSame(['Viele Zugriffe, wenige Seiten'], Visitors::hints($v(2, 60, [['/', 200]])));
        $this->assertSame(['Nur Fehlerstatus', 'Typische Scanner-Pfade'], Visitors::hints($v(0, 3, [['/.env', 404], ['/wp-admin/setup.php', 403]])));
        $this->assertSame([], Visitors::hints($v(1, 1, [['/environment', 200]])), 'only the real scanner paths');
    }

    public function test_daily_helpers(): void
    {
        $data = Daily::normalize(['hosts' => [
            ['host' => 'a.example.de', 'series' => [['date' => '2026-11-14', 'pages' => 3, 'visitors' => 1, 'requests' => 9]]],
            ['host' => 'b.example.de', 'series' => [['date' => '2026-11-13', 'pages' => 2, 'visitors' => 2, 'requests' => 4, 'estimated' => true]]],
            ['host' => '', 'series' => []],
        ]]);

        // days come from the series when the answer has none; a total from the series too
        $this->assertSame(['2026-11-13', '2026-11-14'], $data['days']);
        $this->assertSame(['pages' => 3, 'visitors' => 1, 'requests' => 9, 'estimated' => false], $data['hosts']['a.example.de']['total']);
        // a host with two domains: added up per day
        $both = Daily::forDomains($data, ['a.example.de', 'b.example.de']);
        $this->assertSame(['pages' => 5, 'visitors' => 3, 'requests' => 13, 'estimated' => true], $both['total']);
        $this->assertSame(0, Daily::forDomains($data, ['c.example.de'])['total']['pages']);
        // heatmap steps
        $this->assertSame('', Daily::heat(0, 100));
        $this->assertSame(Daily::HEAT[5], Daily::heat(100, 100));
        $this->assertSame(Daily::HEAT[1], Daily::heat(1, 100));
    }

    public function test_overview_header_filters_data_tile_and_legend(): void
    {
        $html = $this->get(route('apps.page', [$this->installed, 'overview']))->assertOk()
            // title and range in the header, filters below it
            ->assertSeeInOrder(['Übersicht', 'aria-label="Zeitraum"', 'Alle Hosts', 'Bots ausblenden', 'Anfragen'], false)
            ->getContent();

        $this->assertStringContainsString('max-w-xs', $html);
        $this->assertStringNotContainsString('class="w-56', $html);
        // data volume: one line each, number and unit never split
        $this->assertMatchesRegularExpression('/whitespace-nowrap text-lg font-DINPro-bold">↓ [\d,.]+ \w+<\/span><span[^>]*>Download/u', $html);
        $this->assertMatchesRegularExpression('/whitespace-nowrap text-lg font-DINPro-bold">↑ [\d,.]+ \w+<\/span><span[^>]*>Upload/u', $html);
        // the legend may shrink and wrap
        $this->assertStringContainsString('flex min-w-0 flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500', $html);
    }

    public function test_overview_uses_the_real_end_of_a_bucket_on_the_day_the_clocks_go_back(): void
    {
        // 7 days in 3 h buckets on the local wall clock: 25.10. 00:00–03:00 lasts 4 h
        // (22:00 UTC to 02:00 UTC), the upload counter started inside the extra hour
        $ts = Carbon::parse('2026-10-24 22:00', 'UTC')->getTimestampMs();
        $end = Carbon::parse('2026-10-25 02:00', 'UTC')->getTimestampMs();
        $bucket = ['ts' => $ts, 'end' => $end, 'requests' => 40, 's2xx' => 40, 's3xx' => 0, 's4xx' => 0, 's5xx' => 0, 'bytes' => 8000, 'bytes_in' => 2048];
        $this->statsOverride = ['range' => '7d', 'from' => $ts, 'to' => $end, 'computed_at' => $end, 'bucket_ms' => 3 * 3600000,
            'requests' => 40, 'bytes' => 8000, 'bytes_in' => 2048, 'upload_since' => $ts + 3 * 3600000 + 1800000, 'series' => [$bucket]];

        $html = $this->get(route('apps.page', [$this->installed, 'overview']))->assertOk()->getContent();

        // label in tooltips and table: up to 03:00, not 02:00 (= ts + bucket_ms)
        $this->assertStringContainsString('25.10. 00:00 bis 03:00', $html);
        $this->assertStringNotContainsString('25.10. 00:00 bis 02:00', $html);
        // the bar tooltips are data-tip (own layer, one line each), no native SVG <title>
        $this->assertStringContainsString("<g class=\"group\" data-tip=\"25.10. 00:00 bis 03:00\n40 gesamt\n", $html);
        $this->assertStringContainsString('data-tip="25.10. 00:00 bis 03:00&#10;2 KB Upload"', $html);
        $this->assertStringNotContainsString('<title>25.10.', $html);
        // the bucket ends after upload_since: its upload counts (table and upload chart)
        $this->assertStringContainsString('<td>2 KB</td></tr>', $html);
        $this->assertStringNotContainsString('noch nicht erfasst', $html);
    }

    public function test_host_form_sends_the_panel_input(): void
    {
        $page = Livewire::test(Hosts::class, ['installedApp' => $this->installed])
            ->call('create')
            ->set('form.domains', "neu.example.de\n www.neu.example.de ,")
            ->set('form.upstream_host', ' 10.0.0.7 ')
            ->set('form.upstream_port', '3000')
            ->set('form.force_https', false)
            ->set('form.hsts', true)
            ->set('form.access_list_id', '2')
            ->call('save')
            ->assertSet('editing', null)
            ->assertDispatched('notice', kind: 'success', text: 'Gespeichert und in Caddy übernommen.');

        $posts = fn () => array_values(array_filter($this->calls, fn ($c) => $c['method'] === 'POST'));
        $body = $posts()[0]['body'];
        $this->assertSame(['neu.example.de', 'www.neu.example.de'], $body['domains']);
        $this->assertSame('10.0.0.7', $body['upstream_host']);
        $this->assertSame(3000, $body['upstream_port']);
        $this->assertTrue($body['allow_http']);
        $this->assertFalse($body['hsts'], 'HSTS only with forced HTTPS');
        $this->assertSame(2, $body['access_list_id']);
        $this->assertSame([], array_diff(array_keys($body), Hosts::FIELDS), 'no field the panel would reject');

        // a redirect never carries an access list; errors of the panel become a toast
        $page->call('create')->set('form.kind', 'redirect')->set('form.access_list_id', '2')->set('form.domains', '')->call('save')
            ->assertDispatched('notice', kind: 'error', text: 'Mindestens eine Domain angeben')
            ->assertNotSet('editing', null);
        $this->assertSame(0, $posts()[1]['body']['access_list_id']);
    }

    public function test_host_form_is_a_dialog_that_stays_open_on_errors(): void
    {
        $page = Livewire::test(Hosts::class, ['installedApp' => $this->installed])
            ->assertDontSee('data-modal', false);

        // "Bearbeiten" opens the form as a dialog over the list, wherever one scrolled to
        $page->call('edit', 1)
            ->assertSee('role="dialog" aria-modal="true"', false)
            ->assertSee('Host bearbeiten')
            ->assertSee('wire:submit="save"', false);
        $page->call('cancel')->assertDontSee('data-modal', false);

        // a rejected save keeps the dialog open with the panel's message; saving closes it
        $page->call('create')->set('form.domains', '')->call('save')
            ->assertDispatched('notice', kind: 'error', text: 'Mindestens eine Domain angeben')
            ->assertSee('Host hinzufügen')
            ->assertSee('data-modal', false);
        $page->set('form.domains', 'neu.example.de')->set('form.upstream_host', '10.0.0.7')->call('save')
            ->assertSet('editing', null)
            ->assertDispatched('notice', kind: 'success', text: 'Gespeichert und in Caddy übernommen.')
            ->assertDontSee('data-modal', false);
    }

    public function test_host_search_matches_every_field_case_insensitively(): void
    {
        $host = $this->host(3, ['cloud.example.de', 'nextcloud.example.de'], ['scheme' => 'https', 'upstream_host' => '10.0.0.9', 'upstream_port' => 443,
            'access_list_id' => 2, 'notes' => 'Dateiablage der Familie', 'status_title' => '']);
        $lists = [1 => 'Intern', 2 => 'Familie'];
        foreach (['cloud.example', 'NEXTCLOUD', '10.0.0.9:443', 'https://10.0.0.9', 'familie', 'dateiablage', 'next 443'] as $q) {
            $this->assertTrue(Hosts::matches($host, $q, $lists), $q);
        }
        foreach (['alt.example', '10.0.0.5', 'intern', 'cloud 8080'] as $q) {
            $this->assertFalse(Hosts::matches($host, $q, $lists), $q);
        }
        $this->assertTrue(Hosts::matches($this->hosts[2], 'NEU.example.de'), 'the redirect target');
        $this->assertTrue(Hosts::matches($this->host(4, ['status.example.de'], ['kind' => 'status', 'status_title' => 'Unsere Dienste']), 'dienste'), 'the name');
        $this->assertTrue(Hosts::matches($host, '  '), 'empty search');
        $this->assertSame('next<mark class="rounded-sm bg-amber-200 px-0.5 text-inherit dark:bg-amber-500/40">Cloud</mark>.example.de &lt;x&gt;',
            (string) Hosts::highlight('nextCloud.example.de <x>', 'cloud'));
    }

    public function test_host_search_filters_the_visible_kind_and_counts_the_hits(): void
    {
        $this->hosts[3] = $this->host(3, ['cloud.example.de', 'nextcloud.example.de'], ['upstream_host' => '10.0.0.9', 'upstream_port' => 443, 'access_list_id' => 2]);

        $page = Livewire::test(Hosts::class, ['installedApp' => $this->installed])
            ->assertSee('Hosts durchsuchen …')->assertDontSeeHtml('data-search-counts')->assertSee('Alle (3)')
            // alias, case-insensitive; the tabs count the hits, the hit is marked in the domain
            ->set('q', 'NEXTcloud')->assertSeeHtml('data-search-counts')->assertSee('Alle (1)')->assertSee('Reverse Proxy (1)')->assertSee('Weiterleitung (0)')
            ->assertSeeHtml('<mark')->assertSee('cloud.example.de')->assertDontSee('app.example.de')->assertDontSee('alt.example.de')
            // target
            ->set('q', '10.0.0.5:8080')->assertSee('app.example.de')->assertDontSee('cloud.example.de')
            // access list name
            ->set('q', 'familie')->assertSee('cloud.example.de')->assertDontSee('app.example.de')
            // the redirect target, but only in the visible kind
            ->set('q', 'neu.example')->assertSee('alt.example.de')->assertSee('Weiterleitung (1)')
            ->call('filter', 'proxy')->assertSeeHtml('data-search-empty')
            ->assertSee('Keine Hosts für „')->assertSee('“ gefunden (Art: Reverse Proxy).')->assertSee('Suche zurücksetzen')
            ->assertDontSee('Noch keine Reverse Proxys')
            ->call('resetSearch')->assertSet('q', '')->assertDontSeeHtml('data-search-empty')->assertSee('app.example.de')->assertSee('cloud.example.de');
        // the kind filter alone is unchanged
        $page->call('filter', 'redirect')->assertSee('alt.example.de')->assertDontSee('cloud.example.de')->assertSee('Reverse Proxy (2)');
    }

    public function test_host_search_comes_from_the_url(): void
    {
        $this->get(route('apps.page', [$this->installed, 'hosts']).'?q=alt')->assertOk()
            ->assertSee('alt.example.de')->assertDontSee('app.example.de')->assertSee('data-search-counts', false);

        Livewire::withQueryParams(['q' => 'APP', 'kind' => 'proxy'])->test(Hosts::class, ['installedApp' => $this->installed])
            ->assertSet('q', 'APP')->assertSet('kindFilter', 'proxy')->assertSee('app.example.de')->assertDontSee('alt.example.de');
    }

    public function test_switches_put_the_whole_host_back(): void
    {
        Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('toggle', 2)->call('maintenance', 1);

        $this->assertTrue($this->hosts[2]['enabled']);
        $this->assertSame('https://neu.example.de', $this->hosts[2]['redirect_url']);
        $this->assertTrue($this->hosts[1]['maintenance']);
        $put = collect($this->calls)->firstWhere('method', 'PUT');
        $this->assertSame([], array_diff(array_keys($put['body']), Hosts::FIELDS));
        $this->assertArrayNotHasKey('id', $put['body']);
    }

    public function test_security_actions(): void
    {
        Livewire::test(Security::class, ['installedApp' => $this->installed])
            ->set('blockList', ['CN', 'RU'])
            ->call('saveCountries')
            ->call('removeBan', '203.0.113.9', true)
            ->assertSet('guard.exempt', '203.0.113.9');

        $put = collect($this->calls)->firstWhere('path', '/api/security/countries');
        $this->assertSame(['mode' => 'block', 'countries' => ['CN', 'RU']], $put['body']);
        $del = collect($this->calls)->firstWhere('method', 'DELETE');
        $this->assertSame('ip=203.0.113.9&exempt=1', $del['query']);
    }

    public function test_certificate_download_and_error_page_preview_go_through_core(): void
    {
        Livewire::test(Certificates::class, ['installedApp' => $this->installed])
            ->call('download', 7, 'fullchain.pem')
            ->assertFileDownloaded('mail.example.de.fullchain.pem');
        Livewire::test(Certificates::class, ['installedApp' => $this->installed])->call('download', 7, '../../etc/passwd');
        $this->assertCount(1, array_filter($this->calls, fn ($c) => str_contains($c['path'], '/api/acme-certs/7/')));

        Livewire::test(ErrorPages::class, ['installedApp' => $this->installed])
            ->call('preview', 'unavailable')
            ->assertSet('previewHtml', '<h1>Dienst nicht erreichbar</h1>')
            ->call('save');
        $put = collect($this->calls)->firstWhere('method', 'PUT');
        $this->assertSame(array_keys(ErrorPages::GROUPS), array_keys($put['body']['pages']));
    }

    public function test_streams_lines_and_unreachable_panel(): void
    {
        $this->assertSame(['203.0.113.7', '10.0.0.0/8'], Streams::lines("203.0.113.7,\n 10.0.0.0/8\n"));

        Http::swap(new Factory(app('events')));
        Http::fake(['*' => Http::failedConnection()]);
        $this->get(route('apps.page', [$this->installed, 'hosts']))->assertOk()->assertSee('PK95 Proxy antwortet nicht');
    }

    // ---- web firewall 2 (PK95 Proxy 1.9.0) ---------------------------------------------------------

    public function test_proxy_1_8_keeps_two_levels_and_no_advanced_section(): void
    {
        $this->enableWaf();
        Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('edit', 1)
            ->assertSet('wafV19', false)->assertSee('Streng (Stufe 2)')->assertDontSee('Stufe 3: sehr streng')
            ->assertDontSeeHtml('data-waf-advanced')->call('save')->assertHasNoErrors();
        $put = last($this->bodies('PUT', '/api/hosts/1/waf'));
        $this->assertSame(['mode', 'paranoia', 'anomaly_threshold', 'body_limit_kb', 'profiles'], array_keys($put), 'nothing of 1.9.0 to a 1.8.0');
        Livewire::withoutLazyLoading()->test(WebFirewall::class, ['installedApp' => $this->installed])->assertDontSeeHtml('data-waf-settings')->assertDontSeeHtml('data-waf-rule-level');
    }

    public function test_the_advanced_section_saves_levels_groups_networks_and_limits(): void
    {
        $this->enableWaf19();
        $page = Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('edit', 1)
            ->assertSet('wafV19', true)->assertSee('Stufe 4: maximal')->assertSeeHtml('data-waf-advanced')
            ->assertDontSeeHtml('data-waf-adjusted')->assertSee('SQL-Injection')->assertSee('Vorschlag: 255')
            ->assertSet('waf.limits.allowed_methods', ['GET', 'HEAD', 'POST', 'OPTIONS'])
            // a higher block level takes the detection level along
            ->set('waf.paranoia', '3')->assertSet('waf.detection_paranoia', '3')
            ->set('waf.detection_paranoia', '4')->set('waf.disabled_groups', ['java'])
            ->set('waf.bypass_cidrs', "192.168.1.0/24\n 10.0.0.5 ")
            ->set('waf.limits.allowed_methods', ['GET', 'HEAD', 'POST', 'OPTIONS', 'PUT'])
            ->set('waf.limits.restricted_extensions', "PHP\n.bak")
            ->set('waf.limits.max_args', '255')
            ->call('save')->assertHasNoErrors()->assertSet('editing', null);

        $put = last($this->bodies('PUT', '/api/hosts/1/waf'));
        $this->assertSame([3, 4, ['java'], ['192.168.1.0/24', '10.0.0.5']], [$put['paranoia'], $put['detection_paranoia'], $put['disabled_groups'], $put['bypass_cidrs']]);
        $this->assertArrayNotHasKey('exceptions', $put);
        // complete limits, null = CRS default (also the content types that equal it)
        $this->assertSame(['allowed_methods' => ['GET', 'HEAD', 'POST', 'OPTIONS', 'PUT'], 'allowed_content_types' => null,
            'restricted_extensions' => ['.php', '.bak'], 'max_args' => 255, 'arg_name_length' => null, 'arg_length' => null,
            'total_arg_length' => null, 'max_file_size_kb' => null, 'combined_file_sizes_kb' => null], $put['limits']);

        // reopened: detection, group and three limits differ from the default (the networks are per host)
        Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('edit', 1)
            ->assertSeeHtml('data-waf-adjusted>· 5 angepasst')->assertSet('waf.limits.max_args', '255')
            ->assertSet('waf.limits.restricted_extensions', ".php\n.bak");
    }

    public function test_errors_of_the_advanced_section_land_on_their_field_with_the_line(): void
    {
        $this->enableWaf19();
        Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('edit', 1)
            ->set('waf.bypass_cidrs', "192.168.1.0/24\n0.0.0.0/0")->call('save')
            ->assertHasErrors(['waf.bypass_cidrs'])->assertSet('editing', '1')
            ->assertSee('Zeile 2: Zu groß.')->assertDontSeeHtml('wire:key="w-advanced-error"', 'the networks are outside „Erweitert“')
            ->set('waf.bypass_cidrs', '')->set('waf.limits.allowed_methods', [])->call('save')
            ->assertHasErrors(['waf.limits.allowed_methods'])->assertSee('Mindestens eine Methode.')->assertSeeHtml('wire:key="w-advanced-error"');
    }

    public function test_a_new_host_on_proxy_1_9_gets_levels_groups_and_networks_but_no_limits(): void
    {
        $this->enableWaf19();
        $this->waf['settings']['new_host_mode'] = 'block';
        Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('create')
            ->assertSet('wafV19', true)->assertSet('wafInherit', true)->assertSet('waf.mode', 'block', 'the central mode for new hosts')
            ->set('wafInherit', false)->assertSee('Grenzwerte stellst du nach dem Anlegen des Hosts ein.')
            ->set('form.domains', 'neu.example.de')->set('form.upstream_host', '10.0.0.7')->set('form.upstream_port', '80')
            ->set('waf.paranoia', '2')->set('waf.disabled_groups', ['php'])->call('save')->assertSet('editing', null);
        $put = last($this->bodies('PUT', '/api/hosts/3/waf'));
        $this->assertSame([2, 2, ['php']], [$put['paranoia'], $put['detection_paranoia'], $put['disabled_groups']]);
        $this->assertArrayNotHasKey('limits', $put);
    }

    public function test_the_page_shows_levels_takes_the_parameter_and_saves_the_settings(): void
    {
        $this->enableWaf19();
        $page = Livewire::withoutLazyLoading()->test(WebFirewall::class, ['installedApp' => $this->installed])
            ->assertSee('Treffer nach Stufe')->assertSee('vor 1.9: 3')->assertSee('Heute umgangen')->assertSeeHtml('data-waf-bypassed')
            ->call('show', 'protokoll')->assertSee('Stufe 1')->assertSee('Stufe 2 · nur erkannt')
            ->call('show', 'einstellungen')->assertSeeHtml('data-waf-settings');

        // the parameter of the rule, 1:1 (also a header name as the event has it)
        $page->call('newException', 1, 'app.example.de', '920320', '/search', 'REQUEST_HEADERS:User-Agent')
            ->assertSet('exception.target', 'REQUEST_HEADERS:User-Agent')->assertSet('exception.use_target', true)
            ->assertSee('Nur für den Parameter REQUEST_HEADERS:User-Agent')->call('saveException');
        $this->assertSame('REQUEST_HEADERS:User-Agent', last($this->bodies('POST', '/api/hosts/1/waf/exceptions'))['target']);

        // switched off: for the whole rule
        $page->call('newException', 1, 'app.example.de', '942100', '/search', 'ARGS:q')->set('exception.use_target', false)->call('saveException');
        $this->assertArrayNotHasKey('target', last($this->bodies('POST', '/api/hosts/1/waf/exceptions')));

        // a collection the Proxy cannot name (or a name that does not fit): without target, with a note
        $page->call('newException', 1, 'app.example.de', '930100', '/x', 'REQUEST_URI')->assertSet('exception.target', '')
            ->assertSee('Dafür gibt es keine Ausnahme je Parameter')
            ->call('newException', 1, 'app.example.de', '930100', '/x', 'ARGS:a b')->assertSet('exception.target', '');

        // global settings: loaded once, PUT replaces them, a wrong line on the field
        $page->assertSet('settings.use_internal_networks', false)
            ->set('settings.use_internal_networks', true)->set('settings.bypass_cidrs', "203.0.113.0/24\n::/0")->call('saveSettings')
            ->assertHasErrors(['settings.bypass_cidrs'])->assertSee('Zeile 2: Zu groß.')
            ->set('settings.bypass_cidrs', '203.0.113.0/24')->call('saveSettings')->assertHasNoErrors()
            ->assertDispatched('notice', kind: 'success', text: 'Einstellungen gespeichert.');
        $this->assertSame(['use_internal_networks' => true, 'bypass_cidrs' => ['203.0.113.0/24']],
            array_intersect_key(last($this->bodies('PUT', '/api/waf/settings')), ['use_internal_networks' => 1, 'bypass_cidrs' => 1]));
    }

    public function test_a_host_inherits_the_central_settings_or_keeps_its_own(): void
    {
        $this->enableWaf19();
        // host 1 has its own settings: badge in the list
        $this->assertStringContainsString('Eigene WAF-Einstellungen', $this->get(route('apps.page', [$this->installed, 'hosts']))->getContent());

        $this->waf['hosts'][1]['inherit'] = true;
        $page = Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('edit', 1)
            ->assertSet('wafInherit', true)->assertSeeHtml('data-waf-inherited')->assertSee('Zentral bearbeiten')
            // the effective (central) values, greyed out
            ->assertSet('wafEffective.paranoia', '2')->assertSet('wafEffective.detection_paranoia', '3')->assertSet('wafEffective.disabled_groups', ['java'])
            ->assertSeeHtml('data-waf-tuning="wafEffective"')->assertDontSeeHtml('data-waf-tuning="waf"')
            ->set('waf.bypass_cidrs', '192.168.1.0/24')->call('save')->assertHasNoErrors();
        // only what stays per host, plus inherit: the host's own values stay stored at the Proxy
        $this->assertSame(['mode', 'profiles', 'bypass_cidrs', 'inherit'], array_keys(last($this->bodies('PUT', '/api/hosts/1/waf'))));
        $this->assertTrue(last($this->bodies('PUT', '/api/hosts/1/waf'))['inherit']);
        $this->assertStringNotContainsString('Eigene WAF-Einstellungen', $this->get(route('apps.page', [$this->installed, 'hosts']))->getContent());

        // switched off: the own form again, its values are sent
        Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('edit', 1)->set('wafInherit', false)
            ->assertSeeHtml('data-waf-tuning="waf"')->set('waf.paranoia', '3')->call('save')->assertHasNoErrors();
        $put = last($this->bodies('PUT', '/api/hosts/1/waf'));
        $this->assertSame([false, 3, 3], [$put['inherit'], $put['paranoia'], $put['detection_paranoia']]);
        $this->assertArrayHasKey('limits', $put);
    }

    public function test_the_central_settings_and_central_exceptions(): void
    {
        $this->enableWaf19();
        $page = Livewire::withoutLazyLoading()->test(WebFirewall::class, ['installedApp' => $this->installed])->call('show', 'einstellungen')
            ->assertSet('hasCentral', true)->assertSee('Zentrale Einstellungen')->assertSeeHtml('data-waf-settings')
            ->assertSeeHtml('data-waf-tuning="central"')->assertSet('central.paranoia', '2')->assertSet('settings.new_host_mode', 'detect')
            // the same form as at the host: a higher level takes detection along
            ->set('central.paranoia', '4')->assertSet('central.detection_paranoia', '4')
            ->set('settings.new_host_mode', 'block')->set('central.disabled_groups', ['php'])->call('saveSettings')->assertHasNoErrors();
        $put = last($this->bodies('PUT', '/api/waf/settings'));
        $this->assertSame('block', $put['new_host_mode']);
        $this->assertSame([4, 4, ['php']], [$put['central']['paranoia'], $put['central']['detection_paranoia'], $put['central']['disabled_groups']]);
        $this->assertSame([null], array_values(array_unique($put['central']['limits'], SORT_REGULAR)), 'all limits at the CRS default');

        // a central error lands on the central field
        $page->set('central.paranoia', '3')->set('central.detection_paranoia', '2')->call('saveSettings')
            ->assertHasErrors(['central.detection_paranoia'])->assertSee('Nicht unter der Blockstufe.');

        // exceptions for all hosts: from a hit with „Für alle Hosts“, deleted in the list
        $page->call('newException', 1, 'app.example.de', '942100', '/search', 'ARGS:q')->assertSet('exception.scope', 'host')
            ->assertSee('Für alle Hosts')->set('exception.scope', 'all')->call('saveException')
            ->assertDispatched('notice', kind: 'success', text: 'Ausnahme angelegt: Regel 942100 unter /search, nur ARGS:q (alle Hosts).');
        $this->assertSame(['rule_id' => 942100, 'path_prefix' => '/search', 'note' => '', 'target' => 'ARGS:q'], last($this->bodies('POST', '/api/waf/settings/exceptions')));
        $this->assertSame([], $this->bodies('POST', '/api/hosts/1/waf/exceptions'));
        $page->call('show', 'ausnahmen')->assertSee('IP als Host')->assertSet('centralExceptions', fn ($l) => array_column($l, 'rule_id') === [920350, 942100])
            ->call('deleteCentralException', '7')->assertDispatched('notice', kind: 'success', text: 'Ausnahme gelöscht.')
            ->assertSet('centralExceptions', fn ($l) => array_column($l, 'rule_id') === [942100]);
        $this->assertCount(1, array_filter($this->calls, fn ($c) => $c['method'] === 'DELETE' && $c['path'] === '/api/waf/settings/exceptions/7'));
    }

    // ---- web firewall page in tabs ---------------------------------------------------------------

    public function test_the_tab_comes_from_the_url_and_only_the_open_tab_loads_its_data(): void
    {
        $this->enableWaf19();
        $paths = fn () => array_values(array_unique(array_column($this->calls, 'path')));

        // default: the overview, no log and no settings loaded
        Livewire::withoutLazyLoading()->test(WebFirewall::class, ['installedApp' => $this->installed])
            ->assertSet('tab', 'uebersicht')->assertSeeHtml('data-tab-panel="uebersicht"')->assertSeeHtml('aria-selected="true" data-tab="uebersicht"');
        $this->assertContains('/api/waf/stats', $paths());
        $this->assertNotContains('/api/waf/events', $paths());
        $this->assertNotContains('/api/waf/settings', $paths());

        // ?tab=einstellungen opens the settings straight away: no figures, no log
        $this->calls = [];
        Livewire::withoutLazyLoading()->withQueryParams(['tab' => 'einstellungen'])->test(WebFirewall::class, ['installedApp' => $this->installed])
            ->assertSet('tab', 'einstellungen')->assertSeeHtml('data-waf-settings')->assertDontSeeHtml('data-waf-summary');
        $this->assertContains('/api/waf/settings', $paths());
        $this->assertNotContains('/api/waf/stats', $paths());
        $this->assertNotContains('/api/waf/events', $paths());

        // an unknown tab is the overview; switching loads the tab's data then
        $this->calls = [];
        $page = Livewire::withoutLazyLoading()->withQueryParams(['tab' => 'quatsch'])->test(WebFirewall::class, ['installedApp' => $this->installed])
            ->assertSet('tab', 'uebersicht')->call('show', 'protokoll')->assertSet('tab', 'protokoll')->assertSeeHtml('data-waf-events');
        $this->assertContains('/api/waf/events', $paths());
        // polling only where the figures change by themselves, not under a form
        $this->assertStringContainsString('wire:poll', $page->html());
        $this->assertStringNotContainsString('wire:poll', $page->call('show', 'einstellungen')->html());

        // the page opens with the skeleton, before any call to the panel
        SupportLazyLoading::$disableWhileTesting = false;
        $this->calls = [];
        $this->get(route('apps.page', [$this->installed, 'waf']))->assertOk()->assertSee('Lade Web-Firewall …')->assertSee('data-page-placeholder', false);
        $this->assertSame([], $this->calls);
    }

    public function test_a_click_in_the_overview_filters_the_log_and_the_host_dialog_links_the_settings_tab(): void
    {
        $this->enableWaf19();
        Livewire::withoutLazyLoading()->test(WebFirewall::class, ['installedApp' => $this->installed])
            ->assertSeeHtml('data-waf-top="rules"')->call('filterBy', 'rule', '942100')
            ->assertSet('tab', 'protokoll')->assertSet('rule', '942100')->assertSeeHtml('data-waf-events');
        $this->assertStringContainsString('rule=942100', $this->lastQuery('/api/waf/events'));

        // „Zentral bearbeiten“ of an inheriting host leads to the settings tab
        $this->waf['hosts'][1]['inherit'] = true;
        $html = Livewire::test(Hosts::class, ['installedApp' => $this->installed])->call('edit', 1)->html();
        $this->assertStringContainsString(e(route('apps.page', [$this->installed, 'waf']).'?tab=einstellungen'), $html);
    }

    public function test_the_exceptions_tab_lists_central_and_host_exceptions_and_deletes(): void
    {
        $this->enableWaf19();
        $this->hosts[4] = $this->host(4, ['shop.example.de'], ['enabled' => true]);
        $this->waf['hosts'][4] = ['mode' => 'block', 'exceptions' => [['id' => 41, 'rule_id' => 932160, 'path_prefix' => '/wp-login.php', 'note' => 'Passwortfeld',
            'target' => 'ARGS:pwd', 'created_at' => 1]]] + $this->waf['hosts'][1];
        $this->hosts[5] = $this->host(5, ['aus.example.de'], ['enabled' => true]);
        $this->waf['hosts'][5] = ['mode' => 'off'] + $this->waf['hosts'][1];

        $page = Livewire::withoutLazyLoading()->withQueryParams(['tab' => 'ausnahmen'])->test(WebFirewall::class, ['installedApp' => $this->installed])
            ->assertSee('Für alle Hosts')->assertSee('IP als Host')
            ->assertSeeHtml('data-waf-host-exception-rows')->assertSee('shop.example.de')->assertSee('/wp-login.php')->assertSee('ARGS:pwd')->assertSee('Passwortfeld')
            ->assertSee('App sendet IP als Host');
        // only hosts with WAF are asked (host 5 has it off)
        $this->assertSame(['/api/hosts/1/waf', '/api/hosts/4/waf'], array_values(array_unique(array_filter(array_column($this->calls, 'path'), fn ($p) => preg_match('#^/api/hosts/\d+/waf$#', $p)))));

        $page->call('deleteHostException', 4, '41')->assertDispatched('notice', kind: 'success', text: 'Ausnahme gelöscht.')->assertDontSee('Passwortfeld');
        $this->assertCount(1, array_filter($this->calls, fn ($c) => $c['method'] === 'DELETE' && $c['path'] === '/api/hosts/4/waf/exceptions/41'));
    }

    public function test_with_many_hosts_the_exceptions_tab_links_the_hosts_instead_of_loading_each(): void
    {
        $this->enableWaf19();
        foreach (range(10, 10 + WebFirewall::HOST_EXCEPTIONS_MAX) as $id) {
            $this->hosts[$id] = $this->host($id, ["h{$id}.example.de"], ['enabled' => true]);
        }
        Livewire::withoutLazyLoading()->withQueryParams(['tab' => 'ausnahmen'])->test(WebFirewall::class, ['installedApp' => $this->installed])
            ->assertSee('Zu viele Hosts')->assertSee('h10.example.de')->assertDontSeeHtml('data-waf-host-exception-rows');
        $this->assertSame([], array_filter(array_column($this->calls, 'path'), fn ($p) => preg_match('#^/api/hosts/\d+/waf$#', $p)));
    }

    // ---- the web firewall of today on the Proxy overview -----------------------------------------

    public function test_the_overview_shows_what_the_web_firewall_blocked_today(): void
    {
        $this->enableWaf19();
        $now = (int) (microtime(true) * 1000);
        $this->waf['events'][] = ['id' => 600, 'ts' => $now - 30000, 'host_id' => 1, 'domain' => 'app.example.de', 'method' => 'POST', 'path' => '/wp-login.php?x=1',
            'ip' => '203.0.113.50', 'country' => 'US', 'anomaly_score' => 15, 'action' => 'block', 'block_paranoia' => 1,
            'rules' => [['id' => 949110, 'msg' => 'Inbound Anomaly Score Exceeded', 'severity' => 'critical', 'target' => '', 'paranoia' => 1],
                ['id' => 932160, 'msg' => 'Remote Command Execution: Unix Shell Code Found', 'severity' => 'critical', 'target' => 'ARGS:pwd', 'paranoia' => 1]]];
        Cache::flush();
        $html = Livewire::test(Overview::class, ['installedApp' => $this->installed])
            ->assertSee('Web-Firewall heute')->assertSeeHtml('data-waf-blocked>4')->assertSeeHtml('data-waf-detected>33')
            // the ban of the faked /api/security is from today
            ->assertSeeHtml('data-waf-bans>1')
            // the rule that hit, not the score rule; the path in short
            ->assertSee('Remote Command Execution: Unix Shell Code Found')->assertSee('/wp-login.php?x=1')
            ->assertSee('SQL Injection Attack Detected via libinjection')
            // host 1 only detects and had hits today
            ->assertSee('1 Host erkennt nur, 37 Treffer heute.')->html();
        $waf = route('apps.page', [$this->installed, 'waf']);
        $this->assertStringContainsString(e($waf.'?tab=protokoll&action=block&host=1&rule=932160'), $html);
        $this->assertStringContainsString(e($waf.'?tab=protokoll&action=detect'), $html);
        $this->assertStringContainsString(e($waf.'?tab=protokoll&rule=942100'), $html);
        $this->assertStringContainsString('action=block&limit=5', $this->lastQuery('/api/waf/events'));

        // cached like the dashboard tile: a second render asks the panel again only for its status
        $count = count(array_filter($this->calls, fn ($c) => $c['path'] === '/api/waf/stats'));
        Livewire::test(Overview::class, ['installedApp' => $this->installed]);
        $this->assertCount($count, array_filter($this->calls, fn ($c) => $c['path'] === '/api/waf/stats'));
    }

    public function test_the_overview_card_without_active_waf_without_figures_and_without_waf(): void
    {
        // nowhere switched on: a note with the way to the settings instead of empty figures
        $this->enableWaf19();
        $this->waf['status']['hosts'] = [['host_id' => 1, 'domain' => 'app.example.de', 'mode' => 'off', 'error' => '']];
        Cache::flush();
        $html = Livewire::test(Overview::class, ['installedApp' => $this->installed])->assertSee('Die Web-Firewall ist auf keinem Host eingeschaltet.')
            ->assertDontSeeHtml('data-waf-blocked')->html();
        $this->assertStringContainsString(e(route('apps.page', [$this->installed, 'waf']).'?tab=einstellungen'), $html);

        // the figures do not answer: the card stays, empty, the page whole
        $this->waf['status']['hosts'][0]['mode'] = 'block';
        $this->waf['stats'] = null;
        Cache::flush();
        Livewire::test(Overview::class, ['installedApp' => $this->installed])->assertSeeHtml('data-waf-blocked>–')->assertSee('Anfragen');

        // an older Proxy: no card
        $this->waf = null;
        Cache::flush();
        Livewire::test(Overview::class, ['installedApp' => $this->installed])->assertDontSee('Web-Firewall heute')->assertSee('Anfragen');
    }

    // ---- slow pages open at once (lazy) -----------------------------------------------------------

    public function test_security_and_hosts_open_with_a_placeholder_before_any_panel_call(): void
    {
        SupportLazyLoading::$disableWhileTesting = false;
        foreach (['security' => 'Lade Sicherheitseinstellungen …', 'hosts' => 'Lade Hosts …'] as $page => $text) {
            $this->calls = [];
            $this->get(route('apps.page', [$this->installed, $page]))->assertOk()
                ->assertSee('data-page-placeholder', false)->assertSee($text)->assertSee('aria-busy="true"', false)
                ->assertSee('x-init="$wire.__lazyLoad', false);
            $this->assertSame([], $this->calls, "{$page}: the placeholder waits on nothing");
        }
        // a fast page stays as it is
        $this->get(route('apps.page', [$this->installed, 'certificates']))->assertOk()->assertDontSee('data-page-placeholder', false);
    }

    public function test_the_security_page_loads_its_data_once_and_draws_the_country_picker_from_json(): void
    {
        Cache::flush();
        $count = fn (string $path) => count(array_filter($this->calls, fn ($c) => $c['path'] === $path));
        $page = Livewire::withoutLazyLoading()->test(Security::class, ['installedApp' => $this->installed])
            ->assertSee('Länder')->assertSee('Blocklisten')->assertSeeHtml('data-country-picker');
        $this->assertSame(1, $count('/api/security'), 'mount and render share one answer');
        $this->assertSame(1, $count('/api/stats'));

        // the picker: one template and the rows as JSON instead of 250 rows of markup
        $html = $page->html();
        $this->assertStringNotContainsString('wire:key="cty-', $html);
        $this->assertStringContainsString('x-for="r in rows"', $html);
        $this->assertStringContainsString("entangle('blockList').live", $html);
        $this->assertStringContainsString('Deutschland', $html);
        $this->assertLessThan(150 * 1024, strlen($html));

        // a round trip and a second visit: the 7-day counts come from Core's cache
        $page->set('blockList', ['CN']);
        // (Livewire switches lazy loading back on after a test request)
        Livewire::withoutLazyLoading()->test(Security::class, ['installedApp' => $this->installed]);
        $this->assertSame(1, $count('/api/stats'), 'countries of 7 days only sort the list: cached');
        $this->assertSame(3, $count('/api/security'), 'one per request');
    }
}
