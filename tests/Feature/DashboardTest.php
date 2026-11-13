<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\Dashboard\AppPanel;
use App\Livewire\Dashboard\ProblemList;
use App\Models\AppEvent;
use App\Models\Backup;
use App\Models\InstalledApp;
use App\Modules\Proxy\AccessLog;
use App\Services\Dashboard\AppFigures;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * The master dashboard: status line, problems, figures per app (lazy tiles, cached).
 */
class DashboardTest extends FeatureTestCase
{
    /** @var list<string> URLs the apps were asked for */
    private array $asked = [];

    /** Proxy host 1 is down, its certificate runs out in 5 days, one vulnerability. */
    private bool $proxyTrouble = false;

    /** Uptime monitor "NAS" is down. */
    private bool $nasDown = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pk95.catalog_dir' => base_path('catalog')]);
        $this->signIn();
        foreach (['dns' => 'PK95 DNS', 'proxy' => 'PK95 Proxy', 'uptime' => 'PK95 Uptime', 'proxapps' => 'ProxApps'] as $id => $name) {
            InstalledApp::factory()->create(['app_id' => $id, 'status' => InstalledApp::RUNNING, 'version' => $id === 'proxy' ? '1.3.0' : '1.0.0',
                'manifest' => ['id' => $id, 'name' => $name, 'version' => '1.0.0', 'protocol' => 1, 'icon' => 'globe', 'menu' => [], 'capabilities' => [], 'events' => []]]);
        }
        $now = (int) (microtime(true) * 1000);
        $this->asked = [];
        Http::fake([
            'host.docker.internal:8053/*' => function (Request $r) use ($now) {
                $this->asked[] = $r->url();

                return Http::response(['hours' => 24, 'granularity' => 'hour',
                    'totals' => ['queries' => 10000, 'blocked' => 1500, 'blocked_pct' => 15.0, 'cached' => 6000, 'avg_ms' => 6.5],
                    'series' => [['t' => $now - 3600000, 'queries' => 400, 'blocked' => 60, 'cached' => 200]]]);
            },
            'host.docker.internal:8090/*' => function (Request $r) use ($now) {
                $this->asked[] = $r->url();
                $path = parse_url($r->url(), PHP_URL_PATH);

                return match ($path) {
                    '/api/stats' => Http::response(['range' => '24h', 'requests' => 2000, 'errors_5xx' => 20, 'bytes' => 3000000, 'bytes_in' => 1000000,
                        'bucket_ms' => 3600000, 'series' => [['ts' => $now - 3600000, 'requests' => 80, 's2xx' => 70, 's3xx' => 5, 's4xx' => 4, 's5xx' => 1, 'bytes' => 1, 'bytes_in' => 1]]]),
                    '/api/security' => Http::response(['bans_total' => 7]),
                    '/api/hosts' => Http::response([['id' => 1, 'domains' => ['cloud.example.de'], 'enabled' => true]]),
                    '/api/hosts/checks' => Http::response([['host_id' => 1, 'upstream_known' => true, 'upstream_up' => ! $this->proxyTrouble, 'upstream_error' => 'Verbindung abgelehnt', 'domains' => []]]),
                    '/api/certificates' => Http::response(['hosts' => [['domain' => 'cloud.example.de', 'enabled' => true, 'status' => 'ok',
                        'not_after' => now()->getTimestamp() + ($this->proxyTrouble ? 5 : 60) * 86400 + 3600]], 'custom' => []]),
                    '/api/updates' => Http::response(['vulns' => ['vulns' => $this->proxyTrouble ? [['id' => 'GO-2026-1']] : []]]),
                    default => Http::response(['error' => 'unbekannt'], 404),
                };
            },
            'pk95-uptime:3001/*' => function (Request $r) {
                $this->asked[] = $r->url();
                $bars = array_map(fn ($i) => ['at' => $i * 1800000, 'end' => ($i + 1) * 1800000, 'up' => 30, 'warn' => 0, 'down' => in_array($i, [40, 41], true) ? 5 : 0], range(0, 47));

                return Http::response([
                    ['id' => 'm1', 'name' => 'Webseite', 'status' => 'up', 'uptime24h' => 100.0, 'bars24h' => $bars],
                    ['id' => 'm2', 'name' => 'NAS', 'status' => $this->nasDown ? 'down' : 'up', 'uptime24h' => 90.0, 'bars24h' => $bars],
                    ['id' => 'm3', 'name' => 'Alt', 'status' => 'paused', 'uptime24h' => null],
                ]);
            },
            'pk95-proxapps:8080/*' => function (Request $r) {
                $this->asked[] = $r->url();

                return Http::response(['state' => [], 'errors' => [], 'view' => ['counts' => ['success' => 4, 'running' => 1, 'failed' => 1], 'connections' => [['id' => 1]]]]);
            },
        ]);
    }

    public function test_dashboard_has_a_status_line_lazy_tiles_and_app_cards(): void
    {
        InstalledApp::query()->where('app_id', 'dns')->update(['available_version' => '1.1.0']);

        $this->get('/')->assertOk()
            ->assertSee('4 von 4 Apps laufen')
            ->assertSee('1 Update verfügbar')
            ->assertSee('data-status-line', false)
            ->assertDontSee('Apps im Katalog')                 // the former stat tiles
            ->assertSee('Probleme &amp; Warnungen', false)      // placeholder of the lazy list
            ->assertSee('data-core-panel', false)
            ->assertSee('Nächstes Wartungsfenster')
            ->assertSee('→ 1.1.0');
        $this->assertSame([], $this->asked, 'the page itself does not ask the apps, the lazy tiles do');
    }

    public function test_figures_per_app_are_read_once_and_cached(): void
    {
        $figures = app(AppFigures::class);
        $dns = $figures->for(InstalledApp::query()->firstWhere('app_id', 'dns'));
        $this->assertTrue($dns['ok']);
        $this->assertSame(10000, $dns['queries']);
        $this->assertEqualsWithDelta(60.0, $dns['cachedPct'], 0.01);

        $uptime = $figures->for(InstalledApp::query()->firstWhere('app_id', 'uptime'));
        $this->assertSame([3, 1, 1, 1], [$uptime['total'], $uptime['up'], $uptime['down'], $uptime['paused']]);
        $this->assertEqualsWithDelta(95.0, $uptime['availability'], 0.01, 'average of the active monitors');
        $this->assertSame(2, $uptime['outages'], 'one outage per monitor (two failed sections in a row)');
        $this->assertSame('NAS', $uptime['strips'][0]['name'], 'failing monitors first');

        $proxapps = $figures->for(InstalledApp::query()->firstWhere('app_id', 'proxapps'));
        $this->assertSame([4, 1, 1, 1], [$proxapps['deployed'], $proxapps['active'], $proxapps['failed'], $proxapps['connections']]);

        $count = count($this->asked);
        $figures->for(InstalledApp::query()->firstWhere('app_id', 'dns'));
        $this->assertCount($count, $this->asked, 'cached for '.AppFigures::TTL.' s');
    }

    public function test_proxy_figures_leave_out_the_hidden_clients_from_1_3_0_on(): void
    {
        Settings::put(AccessLog::HIDDEN_SETTING, ['Uptime-Monitor', 'curl']);
        $proxy = InstalledApp::query()->firstWhere('app_id', 'proxy');

        $f = app(AppFigures::class)->for($proxy);
        $this->assertSame(['Uptime-Monitor', 'curl'], $f['hidden']);
        $stats = collect($this->asked)->first(fn ($u) => str_contains($u, '/api/stats'));
        $this->assertStringEndsWith('/api/stats?range=24h&exclude_client=Uptime-Monitor&exclude_client=curl', $stats);
        Livewire::withoutLazyLoading()->test(AppPanel::class, ['appId' => 'proxy'])
            ->assertSee('Ohne 2 ausgeblendete Clients')->assertSee('4 MB');

        // an older Proxy would ignore the parameter: not sent, not claimed
        $proxy->update(['version' => '1.2.0']);
        $this->asked = [];
        $f = app(AppFigures::class)->for($proxy->refresh());
        $this->assertSame([], $f['hidden']);
        $this->assertStringEndsWith('/api/stats?range=24h', collect($this->asked)->first(fn ($u) => str_contains($u, '/api/stats')));
    }

    public function test_tiles_show_figures_and_charts_and_a_stopped_app_only_its_own_hint(): void
    {
        Livewire::withoutLazyLoading()->test(AppPanel::class, ['appId' => 'dns'])
            ->assertSee('PK95 DNS')->assertSee('Aus dem Cache')->assertSee('<svg', false);
        Livewire::withoutLazyLoading()->test(AppPanel::class, ['appId' => 'uptime'])
            ->assertSee('Monitore auf')->assertSee('aria-label="NAS: 24 h:', false);
        Livewire::withoutLazyLoading()->test(AppPanel::class, ['appId' => 'proxapps'])
            ->assertSee('Apps (LXC)')->assertSee('Verbindungen');

        InstalledApp::query()->where('app_id', 'dns')->update(['status' => InstalledApp::STOPPED]);
        Livewire::withoutLazyLoading()->test(AppPanel::class, ['appId' => 'dns'])
            ->assertSee('Die App läuft gerade nicht.')->assertDontSee('aria-label="DNS-Anfragen', false);
    }

    public function test_problems_list_everything_that_needs_a_look_with_links(): void
    {
        $this->proxyTrouble = true;
        InstalledApp::query()->where('app_id', 'dns')->update(['status' => InstalledApp::FAILED, 'error' => 'Container beendet']);
        Backup::query()->create(['app_id' => 'uptime', 'reason' => 'scheduled', 'status' => 'failed']);
        AppEvent::query()->create(['app_id' => 'proxapps', 'type' => 'core.update.failed', 'severity' => 'critical', 'title' => 'x', 'occurred_at' => now()]);
        Settings::put('core_self_update_result', ['result' => 'rolled_back', 'at' => now()->toIso8601String(), 'message' => '']);

        $html = Livewire::withoutLazyLoading()->test(ProblemList::class)
            ->assertSee('PK95 DNS läuft nicht: Container beendet')
            ->assertSee('Proxy-Host cloud.example.de ist nicht erreichbar: Verbindung abgelehnt')
            ->assertSee('Monitor „NAS“ ist ausgefallen.')
            ->assertSee('Zertifikat für cloud.example.de läuft in 5 Tagen ab.')
            ->assertSee('PK95 Proxy: 1 bekannte Schwachstelle.')
            ->assertSee('Letzte Sicherung von PK95 Uptime ist fehlgeschlagen.')
            ->assertSee('Letztes Update von ProxApps ist fehlgeschlagen.')
            ->assertSee('Letztes Update von PK95 Core ist fehlgeschlagen, die vorige Version läuft wieder.')
            ->html();
        foreach (['/apps/dns', '/apps/proxy/hosts', '/apps/uptime/monitors', '/apps/proxy/certificates', '/apps/proxy/updates'] as $path) {
            $this->assertStringContainsString('href="'.url($path), $html, $path);
        }
        // worst first: the warnings (certificate, backup, updates) come after the red entries
        $this->assertLessThan(strpos($html, 'Zertifikat für'), strpos($html, 'Monitor „NAS“'));
    }

    public function test_all_fine_is_one_calm_line(): void
    {
        $this->nasDown = false;

        Livewire::withoutLazyLoading()->test(ProblemList::class)
            ->assertSee('Alles in Ordnung')->assertDontSee('Probleme &amp; Warnungen', false);
    }

    public function test_next_maintenance_window_is_local(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-13 01:00', 'UTC')); // 02:00 in Berlin
        try {
            $this->assertSame('heute 03:30', Dashboard::nextMaintenance('03:30'));
            $this->assertSame('morgen 01:30', Dashboard::nextMaintenance('01:30'));
            $this->assertNull(Dashboard::nextMaintenance('kaputt'));
        } finally {
            Carbon::setTestNow();
        }
    }
}
