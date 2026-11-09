<?php

namespace Tests\Feature;

use App\Models\InstalledApp;
use App\Modules\Dns\Clients;
use App\Modules\Dns\DnsModule;
use App\Modules\Dns\Fmt;
use App\Modules\Dns\Lists;
use App\Modules\Dns\Overview;
use App\Modules\Dns\QueryLog;
use App\Modules\Dns\Records;
use App\Modules\Dns\Rules;
use App\Modules\Dns\Services;
use App\Modules\Dns\Settings;
use App\Services\Catalog\Catalog;
use App\Services\Docker\ContainerSpec;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * The PK95 DNS module against an in-memory fake of the app API (host network:
 * http://host.docker.internal:8053, see pk95dns docs/api.md).
 */
class DnsModuleTest extends FeatureTestCase
{
    private InstalledApp $installed;

    /** @var list<array{method: string, path: string, query: array<string, mixed>, body: mixed}> */
    private array $calls = [];

    /** @var array<string, mixed> state of the fake app */
    private array $state = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['pk95.catalog_dir' => base_path('catalog'), 'pk95.modules' => ['dns' => DnsModule::class]]);
        $this->signIn();
        $menu = array_map(fn ($k) => ['key' => $k, 'label' => $k, 'icon' => 'shield'], array_keys((new DnsModule)->pages()));
        $this->installed = InstalledApp::factory()->create([
            'app_id' => 'dns', 'version' => '1.1.0',
            'manifest' => ['id' => 'dns', 'name' => 'PK95 DNS', 'version' => '1.1.0', 'protocol' => 1, 'icon' => 'shield',
                'menu' => $menu, 'capabilities' => ['events', 'backup'], 'events' => ['upstream.down']],
        ]);
        $now = (int) (microtime(true) * 1000);
        $this->state = [
            'protection' => ['enabled' => true, 'paused_until' => 0],
            'lists' => [
                1 => ['id' => 1, 'name' => 'AdGuard DNS filter', 'url' => 'https://adguardteam.github.io/HostlistsRegistry/assets/filter_1.txt', 'kind' => 'block',
                    'enabled' => true, 'rule_count' => 61234, 'updated_at' => $now - 3600000, 'last_error' => '', 'updating' => false],
                2 => ['id' => 2, 'name' => 'OISD Small', 'url' => 'https://small.oisd.nl/', 'kind' => 'block',
                    'enabled' => false, 'rule_count' => 0, 'updated_at' => 0, 'last_error' => 'HTTP 503', 'updating' => false],
            ],
            'rules' => "||ads.example.com^\n@@||good.example.com^",
            'zones' => [1 => ['id' => 1, 'name' => 'home.lan', 'ttl' => 300, 'primary_ns' => 'ns.home.lan', 'enabled' => true, 'serial' => 2026110901, 'created_at' => $now]],
            'records' => [
                3 => $this->record(3, 'nas.home.lan', 'A', '192.168.1.10', ['ptr' => true]),
                4 => $this->record(4, 'home.lan', 'MX', 'mail.home.lan', ['priority' => 10]),
                5 => $this->record(5, '_ldap._tcp.home.lan', 'SRV', 'ldap.home.lan', ['priority' => 10, 'weight' => 5, 'port' => 389]),
                6 => $this->record(6, 'printer.example.org', 'AAAA', 'fd00::20'),
                8 => $this->record(8, 'cloud.example.de', 'A', '10.10.250.50', ['managed_by' => 'core']),
            ],
            'blocked' => ['tiktok'],
            'clients' => [2 => $this->client(2, 'Kinder-Tablet', ['192.168.1.40'])],
            'settings' => [
                'upstreams' => ['https://dns.quad9.net/dns-query', '9.9.9.9'], 'fallback' => ['1.1.1.1'], 'bootstrap' => ['9.9.9.9'], 'encrypted_only' => false,
                'upstream_mode' => 'load_balance', 'upstream_timeout_ms' => 4000, 'conditional' => [['domain' => 'fritz.box', 'upstream' => '192.168.178.1']],
                'private_ptr' => true, 'private_upstreams' => ['192.168.178.1'],
                'cache_enabled' => true, 'cache_size' => 10000, 'cache_min_ttl' => 0, 'cache_max_ttl' => 86400,
                'blocking_mode' => 'null_ip', 'blocking_ipv4' => '', 'blocking_ipv6' => '', 'blocked_ttl' => 10,
                'ratelimit' => 50, 'refuse_any' => true, 'dnssec' => false, 'disable_ipv6' => false,
                'allowed_clients' => ['192.168.0.0/16'], 'disallowed_clients' => [],
                'querylog_enabled' => true, 'querylog_hours' => 168, 'anonymize_ip' => false, 'stats_days' => 30, 'lists_update_hours' => 24,
                'safesearch' => ['enabled' => false, 'google' => true, 'bing' => true, 'duckduckgo' => true, 'youtube' => true, 'yandex' => true, 'pixabay' => true],
            ],
            'log' => array_map(fn ($i) => [
                'id' => 1000 - $i, 'ts' => $now - $i * 1000, 'client_ip' => $i % 10 === 5 ? '192.168.1.40' : '192.168.1.20', 'client_name' => $i === 0 ? 'Laptop' : '',
                'name' => $i === 0 ? 'ads.example.net' : "host{$i}.example.org", 'qtype' => 'A',
                'status' => $i === 0 ? 'blocked' : 'ok', 'reason' => $i === 0 ? 'list' : '', 'rule' => $i === 0 ? '||ads.example.net^' : '',
                'list_id' => $i === 0 ? 1 : 0, 'list_name' => $i === 0 ? 'AdGuard DNS filter' : '', 'service' => '',
                'upstream' => $i === 0 ? '' : '9.9.9.9', 'rcode' => 'NOERROR', 'answer' => $i === 0 ? '0.0.0.0' : '93.184.216.34',
                'elapsed_ms' => 0.12, 'cached' => $i === 2,
            ], range(0, 79)),
        ];
        Http::fake(['host.docker.internal:8053/*' => fn (Request $r) => $this->dns($r)]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function record(int $id, string $name, string $type, string $value, array $extra = []): array
    {
        return array_merge(['id' => $id, 'zone_id' => 0, 'name' => $name, 'type' => $type, 'value' => $value, 'ttl' => 300,
            'priority' => 0, 'weight' => 0, 'port' => 0, 'ptr' => false, 'enabled' => true, 'managed_by' => '', 'created_at' => 1], $extra);
    }

    /** Longest enabled-or-not zone containing the name, like the app (0 = single record). */
    private function zoneOf(string $name): int
    {
        $best = 0;
        $len = -1;
        foreach ($this->state['zones'] as $z) {
            if (($name === $z['name'] || str_ends_with($name, '.'.$z['name'])) && strlen($z['name']) > $len) {
                [$best, $len] = [$z['id'], strlen($z['name'])];
            }
        }

        return $best;
    }

    /** @return list<array<string, mixed>> */
    private function zoneList(): array
    {
        return array_values(array_map(fn ($z) => $z + ['record_count' => count(array_filter($this->state['records'], fn ($r) => $this->zoneOf($r['name']) === $z['id']))],
            $this->state['zones']));
    }

    /**
     * Validation of the fake, a few rules of docs/api.md → Records.
     *
     * @param  array<string, mixed>  $b
     */
    private function recordError(array $b): ?array
    {
        return match (true) {
            ! str_contains((string) $b['name'], '.') => ['name', 'Ungültiger Name'],
            $b['value'] === '' => ['value', 'Bitte einen Wert angeben.'],
            in_array($b['type'], ['CNAME', 'MX', 'SRV', 'PTR'], true) && filter_var($b['value'], FILTER_VALIDATE_IP) !== false => ['value', 'Hier gehört ein Hostname hin, keine IP-Adresse.'],
            $b['type'] === 'SRV' && $b['port'] < 1 => ['port', 'Port 1 bis 65535.'],
            $b['type'] === 'PTR' && ! str_ends_with($b['name'], '.arpa') => ['name', 'Ein PTR-Eintrag braucht einen Rückwärts-Namen.'],
            default => null,
        };
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, mixed>
     */
    private function client(int $id, string $name, array $ids): array
    {
        return ['id' => $id, 'name' => $name, 'ids' => $ids, 'use_global_settings' => false, 'filtering_enabled' => true,
            'safesearch' => ['enabled' => true, 'google' => true, 'bing' => true, 'duckduckgo' => true, 'youtube' => true, 'yandex' => true, 'pixabay' => true],
            'use_global_services' => false, 'blocked_services' => ['tiktok'], 'created_at' => 1];
    }

    private function dns(Request $r)
    {
        $url = parse_url($r->url());
        $path = $url['path'];
        parse_str($url['query'] ?? '', $query);
        $body = $r->data();
        $this->calls[] = ['method' => $r->method(), 'path' => $path, 'query' => $query, 'body' => $body];
        $now = (int) (microtime(true) * 1000);
        $invalid = fn (string $field, string $msg) => Http::response(['error' => 'Bitte Eingaben prüfen.', 'fields' => [$field => $msg]], 422);
        $rules = function () {
            $lines = preg_split('/\R/', $this->state['rules']);
            $invalid = [];
            foreach ($lines as $i => $line) {
                if (str_contains($line, '$foo')) {
                    $invalid[] = ['line' => $i + 1, 'text' => $line, 'error' => 'Unbekannte Option: foo'];
                }
            }

            return ['text' => $this->state['rules'], 'rule_count' => count(array_filter($lines)) - count($invalid), 'invalid' => $invalid];
        };

        if (preg_match('#^/api/(zones|records)/(\d+)$#', $path, $m)) {
            $store = $m[1];
            $id = (int) $m[2];
            if (! isset($this->state[$store][$id])) {
                return Http::response(['error' => 'Nicht gefunden.'], 404);
            }
            if ($r->method() === 'DELETE') {
                if ($store === 'zones') {
                    $this->state['records'] = array_filter($this->state['records'], fn ($rec) => $this->zoneOf($rec['name']) !== $id);
                }
                unset($this->state[$store][$id]);

                return Http::response(null, 204);
            }
            if ($store === 'zones') {
                $count = collect($this->zoneList())->firstWhere('id', $id)['record_count'];
                if ($body['name'] !== $this->state['zones'][$id]['name'] && $count > 0) {
                    return $invalid('name', 'Eine Zone mit Einträgen kann nicht umbenannt werden.');
                }
                $this->state['zones'][$id] = ['id' => $id] + $body + ['serial' => $this->state['zones'][$id]['serial'] + 1, 'created_at' => 1];

                return Http::response(collect($this->zoneList())->firstWhere('id', $id));
            }
            if ($e = $this->recordError($body)) {
                return $invalid(...$e);
            }
            $this->state['records'][$id] = ['id' => $id, 'zone_id' => $this->zoneOf($body['name'])] + $body + ['created_at' => 1];

            return Http::response($this->state['records'][$id]);
        }

        // writing routes with ids
        if (preg_match('#^/api/(lists|clients)/(\d+)(/refresh)?$#', $path, $m)) {
            $store = $m[1];
            $id = (int) $m[2];
            if (! isset($this->state[$store][$id])) {
                return Http::response(['error' => 'Nicht gefunden'], 404);
            }

            return match (true) {
                ($m[3] ?? '') !== '' => Http::response(['ok' => true], 202),
                $r->method() === 'PUT' => Http::response($this->state[$store][$id] = ['id' => $id] + $body + $this->state[$store][$id]),
                $r->method() === 'DELETE' => (function () use ($store, $id) {
                    unset($this->state[$store][$id]);

                    return Http::response(null, 204);
                })(),
                default => Http::response(['error' => 'Methode'], 405),
            };
        }

        switch ($r->method().' '.$path) {
            case 'POST /api/protection':
                $this->state['protection'] = ! empty($body['enabled']) ? ['enabled' => true, 'paused_until' => 0]
                    : ['enabled' => false, 'paused_until' => ($body['minutes'] ?? 0) > 0 ? $now + $body['minutes'] * 60000 : 0];

                return Http::response($this->state['protection']);
            case 'POST /api/lists':
                if (! str_starts_with((string) ($body['url'] ?? ''), 'http')) {
                    return $invalid('url', 'Bitte eine http(s)-Adresse angeben.');
                }
                $this->state['lists'][7] = ['id' => 7] + $body + ['rule_count' => 0, 'updated_at' => 0, 'last_error' => '', 'updating' => true];

                return Http::response($this->state['lists'][7], 201);
            case 'PUT /api/rules':
                $this->state['rules'] = (string) $body['text'];

                return Http::response($rules());
            case 'POST /api/rules/quick':
                if (! str_contains((string) $body['domain'], '.')) {
                    return $invalid('domain', 'Ungültige Domain');
                }
                $lines = array_filter(preg_split('/\R/', $this->state['rules']), fn ($l) => ! in_array($l, ['||'.$body['domain'].'^', '@@||'.$body['domain'].'^'], true));
                if ($body['action'] !== 'remove') {
                    $lines[] = ($body['action'] === 'allow' ? '@@' : '').'||'.$body['domain'].'^';
                }
                $this->state['rules'] = implode("\n", $lines);

                return Http::response($rules());
            case 'POST /api/records':
                if ($e = $this->recordError($body)) {
                    return $invalid(...$e);
                }
                $id = max(array_keys($this->state['records']) ?: [0]) + 1;
                $this->state['records'][$id] = ['id' => $id, 'zone_id' => $this->zoneOf($body['name'])] + $body + ['created_at' => $now];

                return Http::response($this->state['records'][$id], 201);
            case 'GET /api/records':
                $rows = array_map(fn ($rec) => ['zone_id' => $this->zoneOf($rec['name'])] + $rec, $this->state['records']);
                $rows = array_filter($rows, fn ($rec) => (! isset($query['zone_id']) || $rec['zone_id'] === (int) $query['zone_id'])
                    && (! isset($query['type']) || $rec['type'] === $query['type'])
                    && (! isset($query['name']) || $rec['name'] === $query['name'])
                    && (! isset($query['q']) || str_contains($rec['name'], $query['q']) || str_contains($rec['value'], $query['q'])));
                usort($rows, fn ($a, $b) => [$a['name'], $a['type'], $a['priority'], $a['value']] <=> [$b['name'], $b['type'], $b['priority'], $b['value']]);

                return Http::response(array_values($rows));
            case 'POST /api/zones':
                if (! str_contains((string) $body['name'], '.') && $body['name'] !== 'lan') {
                    return $invalid('name', 'Ungültiger Zonenname');
                }
                $id = max(array_keys($this->state['zones']) ?: [0]) + 1;
                $this->state['zones'][$id] = ['id' => $id] + $body + ['serial' => 2026110901, 'created_at' => $now];
                if ($this->state['zones'][$id]['primary_ns'] === '') {
                    $this->state['zones'][$id]['primary_ns'] = 'ns.'.$body['name'];
                }

                return Http::response(collect($this->zoneList())->firstWhere('id', $id), 201);
            case 'GET /api/zones':
                return Http::response(collect($this->zoneList())->sortBy('name')->values()->all());
            case 'PUT /api/services':
                if (array_diff($body['blocked'], ['tiktok', 'steam', 'youtube'])) {
                    return $invalid('blocked', 'Unbekannter Dienst');
                }
                $this->state['blocked'] = $body['blocked'];
                // no break
            case 'GET /api/services':
                return Http::response(['services' => [
                    ['id' => 'tiktok', 'name' => 'TikTok', 'group' => 'social'], ['id' => 'youtube', 'name' => 'YouTube', 'group' => 'video'],
                    ['id' => 'steam', 'name' => 'Steam', 'group' => 'gaming'],
                ], 'blocked' => $this->state['blocked']]);
            case 'POST /api/clients':
                if (in_array('192.168.1.40', $body['ids'], true)) {
                    return $invalid('ids.0', '192.168.1.40 gehört schon zu Kinder-Tablet.');
                }
                $this->state['clients'][5] = ['id' => 5] + $body + ['created_at' => $now];

                return Http::response($this->state['clients'][5], 201);
            case 'PUT /api/settings':
                if (isset($body['upstreams']) && in_array('quatsch', $body['upstreams'], true)) {
                    return $invalid('upstreams.'.array_search('quatsch', $body['upstreams'], true), 'Ungültige Adresse: quatsch');
                }
                foreach ($body['conditional'] ?? [] as $i => $c) {
                    if (str_contains($c['domain'], ' ')) {
                        return $invalid("conditional.{$i}", "Ungültige Domain: {$c['domain']}");
                    }
                    if ($c['upstream'] === '8.8.8.8') {
                        return $invalid("conditional.{$i}.upstream", 'Klartext-Upstream nicht erlaubt');
                    }
                }
                $this->state['settings'] = array_merge($this->state['settings'], $body);
                // no break
            case 'GET /api/settings':
                return Http::response($this->state['settings']);
            case 'POST /api/upstreams/test':
                return Http::response(['results' => array_map(fn ($u) => ['upstream' => $u, 'ok' => $u !== '1.1.1.1', 'ms' => 8.5,
                    'error' => $u === '1.1.1.1' ? 'Zeitüberschreitung' : ''], $body['upstreams'])]);
            case 'GET /api/querylog':
                $before = (int) ($query['before'] ?? 0);
                $limit = (int) ($query['limit'] ?? 50);
                $items = array_values(array_filter($this->state['log'], fn ($e) => ($before === 0 || $e['id'] < $before)
                    && (! isset($query['search']) || str_contains($e['name'], $query['search']))
                    && (! isset($query['client']) || in_array($query['client'], [$e['client_ip'], $e['client_name']], true))
                    && (($query['status'] ?? 'all') !== 'blocked' || $e['status'] === 'blocked')));
                $page = array_slice($items, 0, $limit);

                return Http::response(['items' => $page, 'next_before' => count($items) > $limit ? end($page)['id'] : 0]);
            case 'DELETE /api/querylog':
                $this->state['log'] = [];
                // no break
            case 'DELETE /api/stats':
            case 'DELETE /api/cache':
                return Http::response(null, 204);
        }

        return match ($r->method().' '.$path) {
            'GET /api/status' => Http::response(['version' => '1.0.0', 'core_mode' => true, 'started_at' => $now - 86400000,
                'dns' => ['listen' => '0.0.0.0:53', 'udp' => false, 'tcp' => false, 'error' => 'Port 53 ist belegt: address already in use'],
                'protection' => $this->state['protection'],
                'rules' => ['lists' => 1, 'list_rules' => 61234, 'custom_rules' => 2, 'rewrites' => 3, 'zones' => 1, 'records' => 5, 'services' => 1],
                'cache' => ['enabled' => true, 'entries' => 1840, 'hits' => 9120, 'misses' => 4410],
                'upstreams' => [
                    ['address' => 'https://dns.quad9.net/dns-query', 'ok' => true, 'avg_ms' => 18.4, 'queries' => 3010, 'failures' => 2, 'last_error' => ''],
                    ['address' => '9.9.9.9', 'ok' => false, 'avg_ms' => 0, 'queries' => 10, 'failures' => 10, 'last_error' => 'i/o timeout'],
                    // added a moment ago, never asked yet: no warning, neutral dot
                    ['address' => 'quic://dns.adguard-dns.com', 'ok' => false, 'avg_ms' => 0, 'queries' => 0, 'failures' => 0, 'last_error' => ''],
                ]]),
            'GET /api/stats' => Http::response(['hours' => (int) ($query['hours'] ?? 24), 'granularity' => ($query['hours'] ?? 24) == 720 ? 'day' : 'hour',
                'totals' => ['queries' => 13530, 'blocked' => 2104, 'blocked_pct' => 15.6, 'cached' => 9120, 'safesearch' => 12, 'services' => 40, 'avg_ms' => 6.2],
                'series' => array_map(fn ($h) => ['t' => $now - (24 - $h) * 3600000, 'queries' => 500 + $h * 10, 'blocked' => 80], range(0, 23)),
                'top_domains' => [['name' => 'example.org', 'count' => 812]],
                'top_blocked' => [['name' => 'ads.example.net', 'count' => 330]],
                'top_clients' => [['ip' => '192.168.1.20', 'name' => 'Laptop', 'count' => 4210, 'blocked' => 600]],
                'top_upstreams' => [['address' => 'https://dns.quad9.net/dns-query', 'count' => 3010, 'avg_ms' => 18.4]]]),
            'GET /api/lists' => Http::response(array_values($this->state['lists'])),
            'GET /api/lists/catalog' => Http::response([
                ['name' => 'AdGuard DNS filter', 'url' => 'https://adguardteam.github.io/HostlistsRegistry/assets/filter_1.txt', 'kind' => 'block', 'description' => 'Werbung und Tracker'],
                ['name' => 'HaGeZi Pro', 'url' => 'https://example.org/hagezi-pro.txt', 'kind' => 'block', 'description' => 'Breite Liste gegen Werbung, Tracking und Malware'],
            ]),
            'POST /api/lists/refresh' => Http::response(['ok' => true], 202),
            'GET /api/rules' => Http::response($rules()),
            'GET /api/check' => ($query['name'] ?? '') === 'kaputt'
                ? $invalid('name', 'Ungültige Domain')
                : Http::response(['name' => $query['name'], 'type' => $query['type'] ?? 'A', 'client_ip' => '', 'client_name' => '', 'status' => 'blocked',
                    'reason' => 'custom', 'rule' => '||ads.example.com^', 'list_id' => 0, 'list_name' => '', 'service' => '', 'answer' => '0.0.0.0']),
            'GET /api/clients' => Http::response(['clients' => array_values($this->state['clients']), 'recent' => [
                ['ip' => '192.168.1.20', 'hostname' => 'laptop.fritz.box', 'client_id' => 0, 'name' => '', 'queries' => 4210, 'blocked' => 600, 'last_seen' => $now],
                ['ip' => '192.168.1.40', 'hostname' => '', 'client_id' => 2, 'name' => 'Kinder-Tablet', 'queries' => 300, 'blocked' => 90, 'last_seen' => $now],
            ]]),
            default => Http::response(['error' => 'unbekannt: '.$r->method().' '.$path], 404),
        };
    }

    /** @return list<array<string, mixed>> */
    private function sent(string $method, string $path): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === $method && $c['path'] === $path));
    }

    public function test_catalog_entry_runs_on_the_host_network(): void
    {
        $app = (new Catalog(base_path('catalog')))->get('dns');

        $this->assertSame('http://host.docker.internal:8053', $app->baseUrl());
        $this->assertSame('dns', $app->module);
        $spec = ContainerSpec::for($app, 'latest', str_repeat('t', 48));
        $this->assertSame('host', $spec['HostConfig']['NetworkMode']);
        $this->assertArrayNotHasKey('CapAdd', $spec['HostConfig']);
        $this->assertArrayNotHasKey('NetworkingConfig', $spec);
        $this->assertSame('/var/lib/pk95dns', $spec['HostConfig']['Mounts'][0]['Target']);
        $this->assertContains('PK95DNS_LISTEN=0.0.0.0:8053', $spec['Env']);
        $this->assertContains('PK95_CORE_URL=http://127.0.0.1:8080', $spec['Env']);
    }

    public function test_upstream_without_queries_is_not_reported_as_failing(): void
    {
        $this->get(route('apps.page', [$this->installed, 'overview']))->assertOk()
            ->assertSee('Upstream nicht erreichbar')->assertSee('9.9.9.9')
            ->assertDontSee('quic://dns.adguard-dns.com');
        $this->get(route('apps.page', [$this->installed, 'settings']))->assertOk()
            ->assertSeeInOrder(['bg-gray-400', 'quic://dns.adguard-dns.com'], false);
    }

    public function test_host_names_wrap_only_at_dots(): void
    {
        $this->assertSame('_ldap.<wbr>_tcp.<wbr>home.<wbr>lan', (string) Fmt::breakable('_ldap._tcp.home.lan'));
        $this->assertSame('fd00:<wbr>:<wbr>5', (string) Fmt::breakable('fd00::5'));
        $this->assertSame('a&lt;b.<wbr>lan', (string) Fmt::breakable('a<b.lan'));

        $html = $this->get(route('apps.page', [$this->installed, 'records']))->assertOk()->getContent();
        $this->assertStringNotContainsString('break-all', $html);
    }

    public function test_every_page_renders_with_app_data(): void
    {
        $expect = [
            'overview' => ['Schutz aktiv', 'Der DNS-Server läuft nicht', 'Port 53 ist belegt', 'Upstream nicht erreichbar', 'i/o timeout',
                'Anfragen im Zeitverlauf', '15,6 %', 'ads.example.net', 'Laptop', '18 ms'],
            'log' => ['ads.example.net', 'Blockiert', 'AdGuard DNS filter', 'aus dem Cache', 'Mehr laden', 'Erlauben', 'Blockieren'],
            'lists' => ['AdGuard DNS filter', '61.234', 'HTTP 503', 'Erlaubnislisten'],
            'rules' => ['||ads.example.com^', 'Domain prüfen', 'Schreibweise', '2 Regeln aktiv'],
            'records' => ['home.lan', '2026110901', 'nas.home.lan', '192.168.1.10', '+PTR', 'mail.home.lan', '_ldap._tcp.home.lan', 'Priorität 10', 'Gewicht 5', 'Port 389',
                'printer.example.org', 'Einzeleintrag', 'von Core', 'Einzeleinträge'],
            'services' => ['Soziale Netzwerke', 'TikTok', 'Spiele', '1 Dienst ausgewählt'],
            'clients' => ['Kinder-Tablet', '192.168.1.40', 'laptop.fritz.box', 'Als Client anlegen', 'TikTok'],
            'settings' => ['https://dns.quad9.net/dns-query', 'fritz.box', 'Antwort bei Blockierung', '1.840 Einträge', 'SafeSearch'],
        ];
        foreach ($expect as $page => $texts) {
            $response = $this->get(route('apps.page', [$this->installed, $page]))->assertOk();
            foreach ($texts as $text) {
                $response->assertSee($text, false);
            }
            // @js() inside <x-…> attributes is not compiled and would end up as broken JS
            $response->assertDontSee('@js(', false);
        }
        // a 30 day range asks for daily buckets
        $this->get(route('apps.page', [$this->installed, 'overview']).'?hours=720')->assertOk();
        $this->assertSame('720', last($this->sent('GET', '/api/stats'))['query']['hours']);
    }

    public function test_protection_pause_and_enable(): void
    {
        $page = Livewire::test(Overview::class, ['installedApp' => $this->installed])
            ->call('pause', 10)
            ->assertDispatched('notice', kind: 'success', text: 'Schutz pausiert für 10 Minuten.')
            ->assertSee('Schutz pausiert bis')
            ->assertSee('Jetzt einschalten');
        $this->assertSame(['enabled' => false, 'minutes' => 10], $this->sent('POST', '/api/protection')[0]['body']);

        $page->call('pause', 7)->call('pause', 0)->assertSee('Schutz ausgeschaltet');
        $this->assertCount(2, $this->sent('POST', '/api/protection'), 'unknown durations are not sent');
        $this->assertSame(['enabled' => false, 'minutes' => 0], $this->sent('POST', '/api/protection')[1]['body']);

        $page->call('enable')->assertSee('Schutz aktiv');
        $this->assertSame(['enabled' => true], $this->sent('POST', '/api/protection')[2]['body']);
    }

    public function test_query_log_filters_pages_and_quick_rules(): void
    {
        $page = Livewire::test(QueryLog::class, ['installedApp' => $this->installed]);
        $this->assertCount(50, $page->get('rows'));

        $page->call('toggleAuto')->assertSet('auto', true)
            ->call('more')->assertSet('auto', false)->assertSet('nextBefore', 0);
        $this->assertCount(80, $page->get('rows'));
        $this->assertSame('951', last($this->sent('GET', '/api/querylog'))['query']['before']);

        $page->set('status', 'blocked');
        $this->assertSame('blocked', last($this->sent('GET', '/api/querylog'))['query']['status']);
        $this->assertCount(1, $page->get('rows'));

        $page->call('quick', 'ads.example.net', 'allow')
            ->assertDispatched('notice', kind: 'success', text: 'ads.example.net ist erlaubt.')
            ->assertSee('Regel entfernen');
        $this->assertStringContainsString('@@||ads.example.net^', $this->state['rules']);
        $page->call('quick', 'kaputt', 'block')->assertDispatched('notice', kind: 'error', text: 'Ungültige Domain');
        $page->call('quick', 'ads.example.net', 'remove');
        $this->assertStringNotContainsString('ads.example.net', $this->state['rules']);

        $page->call('clear')->assertSet('rows', [])->assertSee('Keine passenden Abfragen');
        $this->assertCount(1, $this->sent('DELETE', '/api/querylog'));
    }

    public function test_query_log_client_filter_and_rows_without_quick_rule(): void
    {
        $page = Livewire::test(QueryLog::class, ['installedApp' => $this->installed])
            ->call('filterClient', '192.168.1.40')
            ->assertSet('client', '192.168.1.40');
        $this->assertSame('192.168.1.40', last($this->sent('GET', '/api/querylog'))['query']['client']);
        $this->assertCount(8, $page->get('rows'));
        $this->assertSame(['192.168.1.40'], array_values(array_unique(array_column($page->get('rows'), 'client_ip'))));

        $page->call('resetFilters');
        $this->assertArrayNotHasKey('client', last($this->sent('GET', '/api/querylog'))['query']);
        $this->assertCount(50, $page->get('rows'));

        // a root query ("." is logged as "") cannot become a rule: no button that could only fail
        $this->state['log'] = [['name' => ''] + $this->state['log'][1]];
        $page->call('reload')->assertDontSee('Blockieren')->assertDontSee('Erlauben');
        $this->state['log'] = [['name' => 'a.example.org'] + $this->state['log'][0]];
        $page->call('reload')->assertSee('Blockieren');
    }

    public function test_quickable_follows_the_domain_check_of_the_app(): void
    {
        foreach (['ads.example.net', 'host_1.lan', 'localhost', 'Ads.Example.NET.', str_repeat('a', 63).'.de'] as $name) {
            $this->assertTrue(QueryLog::quickable($name), $name);
        }
        foreach (['', '.', 'a b.example.com', 'a\\032b.example.com', '-a.example', 'a-.example', 'a..b', '*.example.org', str_repeat('a', 64).'.de', str_repeat('a.', 127).'ab', "a.example\n"] as $name) {
            $this->assertFalse(QueryLog::quickable($name), $name);
        }
    }

    public function test_quick_rules_are_read_from_the_rule_text(): void
    {
        $this->assertSame(['a.example' => 'block', 'b.example' => 'allow'],
            QueryLog::quickRules("||a.example^\n@@||b.example^\n||c.example^\$important\n! Kommentar"));
    }

    public function test_lists_add_refresh_toggle_and_remove(): void
    {
        $page = Livewire::test(Lists::class, ['installedApp' => $this->installed])
            ->call('create')
            ->assertSee('HaGeZi Pro')
            ->assertDontSee('Werbung und Tracker') // already added lists are not offered
            ->call('pick', 'https://example.org/hagezi-pro.txt')
            ->assertSet('form.name', 'HaGeZi Pro')
            ->call('save')
            ->assertSet('editing', null)
            ->assertDispatched('notice', kind: 'success', text: 'Gespeichert. Die Liste wird geladen.')
            ->assertSee('Wird geladen');
        $this->assertSame(['name' => 'HaGeZi Pro', 'url' => 'https://example.org/hagezi-pro.txt', 'kind' => 'block', 'enabled' => true],
            $this->sent('POST', '/api/lists')[0]['body']);

        $page->call('create', 'allow')->assertSet('form.kind', 'allow')->set('form.name', 'X')->set('form.url', 'ftp://x')->call('save')
            ->assertHasErrors('form.url')->assertNotSet('editing', null)->call('cancel');

        $page->call('refresh', 1)->call('refresh');
        $this->assertCount(1, $this->sent('POST', '/api/lists/1/refresh'));
        $this->assertCount(1, $this->sent('POST', '/api/lists/refresh'));

        $page->call('toggle', 2);
        $this->assertTrue($this->state['lists'][2]['enabled']);
        $this->assertSame('https://small.oisd.nl/', $this->sent('PUT', '/api/lists/2')[0]['body']['url']);

        $page->call('delete', 2)->assertDispatched('notice', kind: 'success', text: 'Liste entfernt.');
        $this->assertArrayNotHasKey(2, $this->state['lists']);
    }

    public function test_rules_save_reports_invalid_lines_and_check_runs(): void
    {
        Livewire::test(Rules::class, ['installedApp' => $this->installed])
            ->assertSet('ruleCount', 2)
            ->set('text', "||ads.example.com^\n||bad.example^\$foo")
            ->call('save')
            ->assertDispatched('notice', kind: 'success', text: 'Gespeichert: 1 Regel aktiv. 1 Zeile wird ignoriert.')
            ->assertSee('Unbekannte Option: foo')
            ->assertSee('Zeile 2:')
            ->set('check.name', 'ads.example.com')
            ->call('runCheck')
            ->assertSet('result.status', 'blocked')
            ->assertSee('Eigene Regel')
            ->set('check.name', 'kaputt')
            ->call('runCheck')
            ->assertHasErrors('check.name')
            ->assertSet('result', null);

        $this->assertSame(['name' => 'ads.example.com', 'type' => 'A'], $this->sent('GET', '/api/check')[0]['query']);
    }

    public function test_zone_crud_with_public_domain_warning(): void
    {
        $page = Livewire::test(Records::class, ['installedApp' => $this->installed])
            ->assertSeeHtml('Die Zone home.lan und ihre 3 Eintr')
            ->assertDontSee('Öffentliche Domain')
            ->call('createZone')->assertSet('zoneForm.ttl', '300')
            ->set('zoneForm.name', 'kaputt')->call('saveZone')
            ->assertHasErrors('zoneForm.name')->assertDispatched('notice', kind: 'error', text: 'Bitte Eingaben prüfen.')
            ->set('zoneForm.name', 'Example.org.')->assertSee('Öffentliche Domain')
            ->call('saveZone')->assertSet('zoneEditing', null)
            ->assertDispatched('notice', kind: 'success', text: 'Zone angelegt. 1 vorhandener Eintrag gehört jetzt zu ihr.')
            ->assertSee('Öffentliche Domain: Namen, die hier fehlen');
        $this->assertSame(['name' => 'example.org', 'ttl' => 300, 'primary_ns' => '', 'enabled' => true], last($this->sent('POST', '/api/zones'))['body']);
        $this->assertSame('ns.example.org', $this->state['zones'][2]['primary_ns']);

        // TTL is checked before anything is sent; renaming a zone with records is refused by the app
        $page->call('editZone', 1)->assertSet('zoneForm.name', 'home.lan')->assertSet('zoneForm.primary_ns', 'ns.home.lan')
            ->set('zoneForm.ttl', 'eine Stunde')->call('saveZone')->assertHasErrors('zoneForm.ttl')
            ->set('zoneForm.ttl', '3600')->set('zoneForm.name', 'haus.lan')->call('saveZone')->assertHasErrors('zoneForm.name')
            ->set('zoneForm.name', 'home.lan')->call('saveZone')->assertHasNoErrors()->assertSet('zoneEditing', null);
        $this->assertCount(2, $this->sent('PUT', '/api/zones/1'), 'the refused rename and the save');
        $this->assertSame(3600, $this->state['zones'][1]['ttl']);
        $this->assertFalse(Records::isPublicZone('home.lan'));

        $page->call('toggleZone', 1);
        $this->assertFalse($this->state['zones'][1]['enabled']);
        $this->assertSame(['name' => 'home.lan', 'ttl' => 3600, 'primary_ns' => 'ns.home.lan', 'enabled' => false], last($this->sent('PUT', '/api/zones/1'))['body']);

        $page->call('showZone', '2')->assertSet('zone', '2')
            ->call('deleteZone', 2)->assertDispatched('notice', kind: 'success', text: 'Zone entfernt.')->assertSet('zone', '');
        $this->assertArrayNotHasKey(2, $this->state['zones']);
        $this->assertArrayNotHasKey(6, $this->state['records'], 'the records of a zone go with it');
    }

    public function test_public_zone_rule(): void
    {
        foreach (['home.lan', 'lan', 'fritz.home', 'nas.internal', 'x.local', 'localdomain', 'a.home.arpa', 'test', 'b.example',
            'x.invalid', 'intranet', 'firma.corp', 'my.private', '1.168.192.in-addr.arpa', '8.b.d.0.1.0.0.2.ip6.arpa', ''] as $name) {
            $this->assertFalse(Records::isPublicZone($name), $name);
        }
        foreach (['example.org', 'meine-firma.de', 'home.lan.de', 'arpa', 'other.arpa', 'lan.example.com'] as $name) {
            $this->assertTrue(Records::isPublicZone($name), $name);
        }
    }

    public function test_record_crud_per_type(): void
    {
        $page = Livewire::test(Records::class, ['installedApp' => $this->installed])
            ->call('create')->assertSet('form.type', 'A')->assertSet('form.ttl', '300')->assertSee('Rückwärtsauflösung (PTR)')
            ->set('form.name', ' NAS2.home.lan ')->set('form.value', '192.168.1.11')->set('form.ptr', true)->call('save')
            ->assertSet('editing', null)->assertDispatched('notice', kind: 'success', text: 'Gespeichert.');
        $this->assertSame(['name' => 'nas2.home.lan', 'type' => 'A', 'value' => '192.168.1.11', 'ttl' => 300, 'priority' => 0, 'weight' => 0, 'port' => 0,
            'ptr' => true, 'enabled' => true, 'managed_by' => ''], last($this->sent('POST', '/api/records'))['body']);

        // MX: host name only (422 from the app on the field), priority sent, ptr never
        $page->call('create')->set('form.type', 'MX')->assertSee('Priorität')->assertDontSee('Rückwärtsauflösung (PTR)')
            ->set('form.name', 'home.lan')->set('form.value', '192.168.1.1')->set('form.ptr', true)->call('save')
            ->assertHasErrors('form.value')->assertNotSet('editing', null)
            ->set('form.value', 'mail2.home.lan')->set('form.priority', '20')->call('save')->assertSet('editing', null);
        $this->assertSame(['name' => 'home.lan', 'type' => 'MX', 'value' => 'mail2.home.lan', 'ttl' => 300, 'priority' => 20, 'weight' => 0, 'port' => 0,
            'ptr' => false, 'enabled' => true, 'managed_by' => ''], last($this->sent('POST', '/api/records'))['body']);

        // SRV: weight and port; an empty port is caught here, port 0 by the app
        $posts = count($this->sent('POST', '/api/records'));
        $page->call('create')->set('form.type', 'SRV')->assertSee('Gewicht')->assertSee('Port')
            ->set('form.name', '_sip._udp.home.lan')->set('form.value', 'pbx.home.lan')->call('save')
            ->assertHasErrors('form.port');
        $this->assertCount($posts, $this->sent('POST', '/api/records'), 'nothing sent with a field that is not a number');
        $page->set('form.port', '0')->call('save')->assertHasErrors('form.port')
            ->set('form.port', '5060')->set('form.weight', '5')->call('save')->assertHasNoErrors()->assertSet('editing', null);
        $this->assertSame(['priority' => 10, 'weight' => 5, 'port' => 5060],
            array_intersect_key(last($this->sent('POST', '/api/records'))['body'], ['priority' => 0, 'weight' => 0, 'port' => 0]));

        // TXT: textarea, surrounding line breaks dropped; MX priority from the default form is not sent
        $page->call('create')->set('form.type', 'TXT')->assertSeeHtml('<textarea')
            ->set('form.name', 'home.lan')->set('form.value', "v=spf1 mx -all\n")->call('save')->assertSet('editing', null);
        $this->assertSame(['type' => 'TXT', 'value' => 'v=spf1 mx -all', 'priority' => 0],
            array_intersect_key(last($this->sent('POST', '/api/records'))['body'], ['type' => 0, 'value' => 0, 'priority' => 0]));

        // CNAME with an invalid TTL, PTR with a forward name
        $page->call('create')->set('form.type', 'CNAME')->set('form.name', 'files.home.lan')->set('form.value', 'nas.home.lan')
            ->set('form.ttl', '5 min')->call('save')->assertHasErrors('form.ttl')
            ->set('form.ttl', '')->call('save')->assertSet('editing', null);
        $this->assertSame(300, last($this->sent('POST', '/api/records'))['body']['ttl'], 'empty TTL = 300');
        $page->call('create')->set('form.type', 'PTR')->set('form.name', 'nas.home.lan')->set('form.value', 'nas.home.lan')->call('save')
            ->assertHasErrors('form.name')
            ->set('form.name', '10.1.168.192.in-addr.arpa')->call('save')->assertSet('editing', null);

        // edit, toggle and delete keep the type fields
        $page->call('edit', 4)->assertSet('form.type', 'MX')->assertSet('form.priority', '10')
            ->set('form.value', 'mx.home.lan')->call('save')->assertSet('editing', null);
        $this->assertSame('mx.home.lan', $this->state['records'][4]['value']);
        $this->assertSame(10, $this->state['records'][4]['priority']);

        $page->call('toggle', 3);
        $this->assertSame(['name' => 'nas.home.lan', 'type' => 'A', 'value' => '192.168.1.10', 'ttl' => 300, 'priority' => 0, 'weight' => 0, 'port' => 0,
            'ptr' => true, 'enabled' => false, 'managed_by' => ''], last($this->sent('PUT', '/api/records/3'))['body']);

        $page->call('delete', 6)->assertDispatched('notice', kind: 'success', text: 'Eintrag gelöscht.');
        $this->assertArrayNotHasKey(6, $this->state['records']);
        $page->call('edit', 6)->assertDispatched('notice', kind: 'error', text: 'Den Eintrag gibt es nicht mehr.')->assertSet('editing', null);
    }

    public function test_record_filters(): void
    {
        $page = Livewire::test(Records::class, ['installedApp' => $this->installed])
            ->assertSee('printer.example.org')->assertSee('nas.home.lan');
        $this->assertSame([], last($this->sent('GET', '/api/records'))['query']);

        $page->set('zone', '1')->assertSee('nas.home.lan')->assertDontSee('printer.example.org');
        $this->assertSame(['zone_id' => '1'], last($this->sent('GET', '/api/records'))['query']);

        $page->set('zone', '0')->assertSee('printer.example.org')->assertDontSee('nas.home.lan');
        $this->assertSame(['zone_id' => '0'], last($this->sent('GET', '/api/records'))['query']);

        $page->set('zone', '')->set('type', 'AAAA')->set('q', ' printer ')->assertSee('printer.example.org')->assertDontSee('cloud.example.de');
        $this->assertSame(['type' => 'AAAA', 'q' => 'printer'], last($this->sent('GET', '/api/records'))['query']);

        // a new record starts with the filtered type; edit finds records outside the filter too
        $page->call('create')->assertSet('form.type', 'AAAA')->call('cancel')
            ->call('edit', 3)->assertSet('form.name', 'nas.home.lan')->assertSet('form.ptr', true)->call('cancel');

        $page->set('q', 'nichts')->assertSee('Keine passenden Einträge')
            ->call('resetFilters')->assertSet('type', '')->assertSee('nas.home.lan');
        $this->assertSame([], last($this->sent('GET', '/api/records'))['query']);

        // unknown filter values from the address bar are dropped
        Livewire::withQueryParams(['type' => 'NS', 'zone' => 'x'])->test(Records::class, ['installedApp' => $this->installed])
            ->assertSet('type', '')->assertSet('zone', '');
    }

    public function test_records_of_core_are_marked_and_keep_their_owner(): void
    {
        $page = Livewire::test(Records::class, ['installedApp' => $this->installed])
            ->assertSee('von Core')
            ->assertSeeHtml('Eintrag von Core l')
            ->assertSeeHtml('Core kann ihn wieder anlegen');
        $page->call('edit', 8)->assertSet('form.managed_by', 'core')->assertSee('für eine ausgerollte App angelegt')
            ->set('form.ttl', '60')->call('save');
        $this->assertSame(['ttl' => 60, 'managed_by' => 'core'], array_intersect_key(last($this->sent('PUT', '/api/records/8'))['body'], ['ttl' => 0, 'managed_by' => 0]));
        $page->call('toggle', 8);
        $this->assertSame('core', $this->state['records'][8]['managed_by']);
    }

    public function test_pages_are_locked_for_pk95_dns_before_records(): void
    {
        $this->installed->update(['version' => '1.0.3', 'manifest' => ['version' => '1.0.3'] + $this->installed->manifest]);

        $this->get(route('apps.page', [$this->installed, 'records']))->assertNotFound();
        $this->get(route('apps.page', [$this->installed, 'overview']))->assertNotFound();
        $this->get(route('apps.show', $this->installed))->assertOk()
            ->assertSee('Seiten der App nicht verfügbar')
            ->assertSee('Das Core-Modul braucht PK95 DNS ab Version 1.1.0, installiert ist 1.0.3. PK95 DNS aktualisieren.');
    }

    public function test_services_save(): void
    {
        Livewire::test(Services::class, ['installedApp' => $this->installed])
            ->assertSet('blocked', ['tiktok'])
            ->call('group', 'gaming', true)
            ->assertSet('blocked', ['tiktok', 'steam'])
            ->assertSee('nicht gespeichert')
            ->call('save')
            ->assertDispatched('notice', kind: 'success', text: '2 Dienste blockiert.')
            ->assertSet('saved', ['tiktok', 'steam'])
            ->call('group', 'social', false)
            ->assertSet('blocked', ['steam']);

        $this->assertSame(['blocked' => ['tiktok', 'steam']], $this->sent('PUT', '/api/services')[0]['body']);
    }

    public function test_client_crud(): void
    {
        $page = Livewire::test(Clients::class, ['installedApp' => $this->installed])
            ->call('create', '192.168.1.20', 'laptop')
            ->assertSet('form.ids', '192.168.1.20')
            ->set('form.ids', "192.168.1.20\n192.168.1.40")
            ->set('form.use_global_services', false)
            ->set('form.blocked_services', ['youtube'])
            ->call('save')
            ->assertHasErrors('form.ids') // "ids.0" of the app lands on the textarea
            ->set('form.ids', '192.168.1.20, 192.168.60.0/24')
            ->call('save')
            ->assertSet('editing', null);

        $body = last($this->sent('POST', '/api/clients'))['body'];
        $this->assertSame(['192.168.1.20', '192.168.60.0/24'], $body['ids']);
        $this->assertSame('laptop', $body['name']);
        $this->assertTrue($body['use_global_settings']);
        $this->assertSame(['youtube'], $body['blocked_services']);
        $this->assertSame(['enabled', 'google', 'bing', 'duckduckgo', 'youtube', 'yandex', 'pixabay'], array_keys($body['safesearch']));

        $page->call('edit', 2)->assertSet('form.ids', '192.168.1.40')->assertSet('form.safesearch.enabled', true)
            ->set('form.filtering_enabled', false)->call('save');
        $this->assertFalse($this->state['clients'][2]['filtering_enabled']);

        $page->call('delete', 2)->assertDispatched('notice', kind: 'success', text: 'Client gelöscht.');
        $this->assertArrayNotHasKey(2, $this->state['clients']);
    }

    public function test_settings_sections_put_only_their_keys(): void
    {
        $page = Livewire::test(Settings::class, ['installedApp' => $this->installed])
            ->assertSet('s.upstreams', "https://dns.quad9.net/dns-query\n9.9.9.9")
            ->set('s.ratelimit', '20') // unsaved input of another section
            ->set('s.upstreams', "9.9.9.9\nquatsch")
            ->call('save', 'upstreams')
            ->assertHasErrors('s.upstreams')->assertSee('Ungültige Adresse: quatsch')
            ->set('s.upstreams', "9.9.9.9\n tls://dns.quad9.net ")
            ->set('s.upstream_timeout_ms', '2500')
            ->call('save', 'upstreams')
            ->assertHasNoErrors()
            ->assertDispatched('notice', kind: 'success', text: 'Upstreams gespeichert.')
            ->assertSet('s.ratelimit', '20');

        $body = last($this->sent('PUT', '/api/settings'))['body'];
        $this->assertSame(['upstreams', 'fallback', 'bootstrap', 'encrypted_only', 'upstream_mode', 'upstream_timeout_ms', 'dnssec'], array_keys($body));
        $this->assertFalse($body['encrypted_only']);
        $this->assertSame(['9.9.9.9', 'tls://dns.quad9.net'], $body['upstreams']);
        $this->assertSame(2500, $body['upstream_timeout_ms']);
        $this->assertSame(50, $this->state['settings']['ratelimit']);

        $page->call('addConditional')->set('s.conditional.1', ['domain' => 'lan', 'upstream' => '10.0.0.1'])->call('addConditional')
            ->call('save', 'routing');
        $this->assertSame([['domain' => 'fritz.box', 'upstream' => '192.168.178.1'], ['domain' => 'lan', 'upstream' => '10.0.0.1']],
            $this->state['settings']['conditional'], 'empty rows are dropped');
        $this->assertCount(2, $page->get('s.conditional'), 'and leave the form');

        $page->set('s.allowed_clients', '')->assertSee('Offener Resolver')->call('save', 'access');
        $this->assertSame([], $this->state['settings']['allowed_clients']);
        $this->assertSame(20, $this->state['settings']['ratelimit']);

        $page->set('s.safesearch.enabled', true)->call('save', 'safesearch');
        $this->assertTrue($this->state['settings']['safesearch']['enabled']);
        $this->assertSame(['safesearch'], array_keys(last($this->sent('PUT', '/api/settings'))['body']));

        $page->call('clearCache')->call('clearLog')->call('resetStats');
        $this->assertCount(1, $this->sent('DELETE', '/api/cache'));
        $this->assertCount(1, $this->sent('DELETE', '/api/stats'));
    }

    public function test_conditional_errors_land_on_the_row_that_caused_them(): void
    {
        $page = Livewire::test(Settings::class, ['installedApp' => $this->installed])
            ->set('s.conditional', [['domain' => '', 'upstream' => ''], ['domain' => 'x.lan', 'upstream' => '8.8.8.8']])
            ->call('save', 'routing')
            ->assertHasErrors('s.conditional.0.upstream')
            ->assertSet('s.conditional', [['domain' => 'x.lan', 'upstream' => '8.8.8.8']]);
        $this->assertSame([['domain' => 'x.lan', 'upstream' => '8.8.8.8']], last($this->sent('PUT', '/api/settings'))['body']['conditional']);

        // removing a row drops the index-keyed errors instead of moving them to the next row
        $page->set('s.conditional', [['domain' => 'bad domain', 'upstream' => '10.0.0.1'], ['domain' => 'fritz.box', 'upstream' => '192.168.178.1']])
            ->call('save', 'routing')
            ->assertHasErrors('s.conditional.0.domain')->assertSee('Ungültige Domain: bad domain')
            ->call('removeConditional', 0)
            ->assertSet('s.conditional.0.domain', 'fritz.box')
            ->assertHasNoErrors('s.conditional.0.domain')->assertDontSee('Ungültige Domain: bad domain');
    }

    public function test_overview_range_set_from_the_browser_is_checked(): void
    {
        Livewire::test(Overview::class, ['installedApp' => $this->installed])
            ->set('hours', 5)
            ->assertSet('hours', 24)
            ->assertSee('24 Std.')
            ->set('hours', 168)
            ->assertSet('hours', 168);
    }

    public function test_upstream_test_uses_the_entered_addresses(): void
    {
        Livewire::test(Settings::class, ['installedApp' => $this->installed])
            ->set('s.upstreams', "9.9.9.9\n9.9.9.9")
            ->call('test')
            ->assertSee('Zeitüberschreitung')
            ->assertSee('8,5 ms')
            ->set('s.upstreams', '')->set('s.fallback', '')
            ->call('test')
            ->assertHasErrors('s.upstreams');

        $this->assertSame(['upstreams' => ['9.9.9.9', '1.1.1.1'], 'bootstrap' => ['9.9.9.9']], $this->sent('POST', '/api/upstreams/test')[0]['body']);
        $this->assertCount(1, $this->sent('POST', '/api/upstreams/test'));
    }

    private function appDown(): void
    {
        Http::swap(new Factory(app('events')));
        Http::fake(['*' => Http::failedConnection()]);
    }

    private function appUp(): void
    {
        Http::swap(new Factory(app('events')));
        Http::fake(['host.docker.internal:8053/*' => fn (Request $r) => $this->dns($r)]);
    }

    public function test_unreachable_app(): void
    {
        $this->appDown();
        foreach (['lists', 'overview', 'rules', 'records', 'services', 'settings'] as $page) {
            $this->get(route('apps.page', [$this->installed, $page]))->assertOk()->assertSee('PK95 DNS antwortet nicht');
        }
    }

    /**
     * PUT /api/rules, /api/services and /api/settings replace what is stored: a page whose
     * first load failed offers no form and saves nothing, even once the app answers again.
     */
    public function test_pages_that_failed_to_load_do_not_save(): void
    {
        $this->appDown();
        $rules = Livewire::test(Rules::class, ['installedApp' => $this->installed])
            ->assertSee('PK95 DNS antwortet nicht')->assertSee('Erneut versuchen')
            ->assertDontSee('Speichern')->assertDontSee('id="r-text"', false);
        $services = Livewire::test(Services::class, ['installedApp' => $this->installed])
            ->assertSee('PK95 DNS antwortet nicht')->assertDontSee('Speichern');
        $settings = Livewire::test(Settings::class, ['installedApp' => $this->installed])
            ->assertSee('PK95 DNS antwortet nicht')->assertDontSee('Speichern');

        $this->appUp();
        $rules->set('text', '||neu.example^')->call('save');
        $services->call('group', 'gaming', true)->call('save')->assertSee('PK95 DNS antwortet nicht')->assertDontSee('Speichern');
        $settings->set('s.conditional', [])->call('save', 'routing')->call('save', 'safesearch')->call('test');
        $this->assertSame([], $this->sent('PUT', '/api/rules'));
        $this->assertSame([], $this->sent('PUT', '/api/services'));
        $this->assertSame([], $this->sent('PUT', '/api/settings'));
        $this->assertSame([], $this->sent('POST', '/api/upstreams/test'));
        $this->assertStringContainsString('@@||good.example.com^', $this->state['rules']);
        $this->assertSame(['tiktok'], $this->state['blocked']);

        // "Erneut versuchen" loads the stored data and opens the forms again
        $rules->call('retry')->assertSet('loaded', true)->assertSet('loadError', null)
            ->assertSet('text', "||ads.example.com^\n@@||good.example.com^")->assertSee('Speichern');
        $services->call('retry')->assertSet('blocked', ['tiktok'])->assertSee('Speichern')->assertDontSee('PK95 DNS antwortet nicht');
        $settings->call('retry')->assertSet('s.upstreams', "https://dns.quad9.net/dns-query\n9.9.9.9")->assertSee('Speichern');
        $settings->call('save', 'routing');
        $this->assertSame([['domain' => 'fritz.box', 'upstream' => '192.168.178.1']], $this->state['settings']['conditional']);
    }
}
