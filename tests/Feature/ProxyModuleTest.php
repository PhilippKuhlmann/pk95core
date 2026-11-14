<?php

namespace Tests\Feature;

use App\Models\InstalledApp;
use App\Modules\Proxy\AccessLog;
use App\Modules\Proxy\Certificates;
use App\Modules\Proxy\Daily;
use App\Modules\Proxy\ErrorPages;
use App\Modules\Proxy\Hosts;
use App\Modules\Proxy\ProxyModule;
use App\Modules\Proxy\Security;
use App\Modules\Proxy\Streams;
use App\Modules\Proxy\Views;
use App\Support\Settings;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

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
            'GET /api/hosts' => Http::response(array_values($this->hosts)),
            'GET /api/hosts/daily' => $this->daily === null ? Http::response(['error' => 'nicht gefunden'], 404) : Http::response($this->daily),
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
                'path' => '/var/lib/proxypanel/acme-export/mail.example.de', 'status' => ['issuer' => "Let's Encrypt", 'not_after' => now()->getTimestamp() + 80 * 86400]]]),
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
                    'ban_hours' => 24, 'exempt' => [], 'notify' => false],
                'bans' => [['ip' => '203.0.113.9', 'country' => 'NL', 'reason' => 'scan', 'strikes' => 2, 'detail' => '/.env', 'host' => 'app.example.de',
                    'created_at' => $now, 'expires_at' => 0]], 'bans_total' => 1, 'ban_status' => ['applied_at' => $now, 'dropped' => 9],
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
        $this->assertMatchesRegularExpression('/class="[^"]*bg-cerulean-500 text-white[^"]*italic"\s+data-tip="11\.11\.: 130 Seitenaufrufe/', $html);
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
}
