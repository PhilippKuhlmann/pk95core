<?php

namespace Tests\Feature;

use App\Models\InstalledApp;
use App\Modules\Uptime\Monitors;
use App\Modules\Uptime\StatusPages;
use App\Modules\Uptime\UptimeModule;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * The Uptime module against a fake of the Uptime API (http://pk95-uptime:3001).
 */
class UptimeModuleTest extends FeatureTestCase
{
    private InstalledApp $installed;

    /** @var array<string, array<string, mixed>> */
    private array $monitors = [];

    /** @var array<string, array<string, mixed>> */
    private array $pages = [];

    /** @var list<array{method: string, path: string, body: array<string, mixed>, auth: ?string}> */
    private array $calls = [];

    /** @var array{status: int, body: array<string, mixed>}|null */
    private ?array $nextError = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pk95.catalog_dir' => base_path('catalog'), 'pk95.modules' => ['uptime' => UptimeModule::class]]);
        $this->signIn();

        $this->installed = InstalledApp::factory()->create([
            'app_id' => 'uptime',
            'manifest' => [
                'id' => 'uptime', 'name' => 'PK95 Uptime', 'version' => '0.1.0', 'protocol' => 1, 'icon' => 'signal',
                'menu' => [
                    ['key' => 'monitors', 'label' => 'Monitore', 'icon' => 'signal'],
                    ['key' => 'status-pages', 'label' => 'Statusseiten', 'icon' => 'globe'],
                ],
                'capabilities' => ['events', 'backup'], 'events' => ['monitor.down'],
            ],
            'version' => '0.1.0',
        ]);
        $this->monitors['m1'] = $this->monitor('m1', 'Webseite', 'up', 'Extern');
        $this->monitors['m2'] = $this->monitor('m2', 'NAS', 'down', '');

        Http::fake(['pk95-uptime:3001/*' => fn (Request $request) => $this->uptime($request)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function monitor(string $id, string $name, string $status, string $group): array
    {
        return [
            'id' => $id, 'name' => $name, 'type' => 'http', 'target' => 'https://'.strtolower($name).'.example', 'group' => $group,
            'status' => $status, 'statusSince' => 1_762_600_000_000, 'retrying' => 0, 'lastCheckAt' => 1_762_600_000_000,
            'lastLatencyMs' => 42, 'lastMessage' => $status === 'down' ? 'Verbindung abgelehnt' : 'OK', 'certExpiresAt' => null,
            'uptime24h' => $status === 'down' ? 87.5 : 100.0,
            'beats' => [['at' => 1, 'status' => $status, 'latencyMs' => 42, 'message' => null]],
            'intervalSeconds' => 60, 'timeoutSeconds' => 10, 'retries' => 1, 'retryIntervalSeconds' => 30,
            'config' => ['type' => 'http', 'url' => 'https://'.strtolower($name).'.example', 'method' => 'GET', 'acceptedStatus' => '200-299',
                'followRedirects' => true, 'ignoreTlsErrors' => false, 'certWarnDays' => 14],
            'channelIds' => ['c1'], 'pushUrl' => null, 'uptime7d' => 99.9, 'uptime30d' => 99.5, 'uptime90d' => null,
            'avgLatency24hMs' => 40, 'createdAt' => 1_762_000_000_000,
        ];
    }

    private function uptime(Request $request)
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $this->calls[] = ['method' => $request->method(), 'path' => $path, 'body' => $request->data(), 'auth' => $request->header('Authorization')[0] ?? null];
        if ($this->nextError !== null) {
            [$error, $this->nextError] = [$this->nextError, null];

            return Http::response($error['body'], $error['status']);
        }

        return match (true) {
            $request->method() === 'GET' && $path === '/api/monitors' => Http::response(array_values($this->monitors)),
            $request->method() === 'POST' && $path === '/api/monitors' => Http::response($this->monitors['new'] = ['id' => 'new'] + $request->data() + $this->monitor('new', 'x', 'pending', ''), 201),
            $request->method() === 'GET' && preg_match('#^/api/monitors/(\w+)$#', $path, $m) === 1 => Http::response($this->monitors[$m[1]] ?? ['error' => 'nicht gefunden'], isset($this->monitors[$m[1]]) ? 200 : 404),
            $request->method() === 'GET' && str_ends_with($path, '/incidents') => Http::response([['id' => 'i1', 'monitorId' => 'm2', 'startedAt' => 1_762_600_000_000, 'resolvedAt' => null, 'cause' => 'Verbindung abgelehnt']]),
            $request->method() === 'PUT' && str_starts_with($path, '/api/monitors/') => Http::response($this->monitors['m1']),
            $request->method() === 'POST' && str_ends_with($path, '/pause') => Http::response($this->monitors['m1']),
            $request->method() === 'DELETE' && str_starts_with($path, '/api/monitors/') => Http::response(['ok' => true]),
            $request->method() === 'GET' && $path === '/api/status-pages' => Http::response(array_values($this->pages)),
            $request->method() === 'POST' && $path === '/api/status-pages' => Http::response($this->pages['p1'] = ['id' => 'p1', 'createdAt' => 1] + $request->data(), 201),
            default => Http::response(['error' => 'unbekannt'], 404),
        };
    }

    public function test_sidebar_offers_the_uptime_pages(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('data-app-menu="uptime"', false)
            ->assertSee(route('apps.page', [$this->installed, 'monitors']), false)
            ->assertSee(route('apps.page', [$this->installed, 'status-pages']), false);
    }

    public function test_monitor_list_shows_status_from_the_api(): void
    {
        $this->get(route('apps.page', [$this->installed, 'monitors']))->assertOk()
            ->assertSee('Webseite')->assertSee('NAS')
            ->assertSee('Online')->assertSee('Offline')
            ->assertSee('87,50 %')
            ->assertSee('Extern');

        $this->assertSame('Bearer '.$this->installed->token, $this->calls[0]['auth']);
    }

    public function test_details_show_incidents(): void
    {
        Livewire::test(Monitors::class, ['installedApp' => $this->installed])
            ->call('toggle', 'm2')
            ->assertSee('Letzte Vorfälle')
            ->assertSee('läuft noch')
            ->assertSee('99,90 %');
    }

    public function test_create_monitor_sends_a_monitor_input(): void
    {
        Livewire::test(Monitors::class, ['installedApp' => $this->installed])
            ->call('create')
            ->set('form.name', 'Shop')
            ->set('form.group', 'Extern')
            ->set('form.url', 'https://shop.example')
            ->set('form.intervalSeconds', '120')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('notice', kind: 'success')
            ->assertSet('editing', null);

        $post = collect($this->calls)->firstWhere('method', 'POST');
        $this->assertSame('/api/monitors', $post['path']);
        $this->assertSame('Shop', $post['body']['name']);
        $this->assertSame(120, $post['body']['intervalSeconds']);
        $this->assertSame(['type' => 'http', 'url' => 'https://shop.example', 'method' => 'GET', 'acceptedStatus' => '200-299', 'keyword' => '',
            'keywordInverted' => false, 'followRedirects' => true, 'ignoreTlsErrors' => false, 'certWarnDays' => 14], $post['body']['config']);
        $this->assertSame([], $post['body']['channelIds']);
    }

    public function test_tcp_monitor_payload(): void
    {
        $component = Livewire::test(Monitors::class, ['installedApp' => $this->installed])
            ->call('create')
            ->set('form.type', 'tcp')
            ->set('form.name', 'SSH')
            ->set('form.host', 'nas.lan')
            ->set('form.port', '22');

        $this->assertSame(['type' => 'tcp', 'host' => 'nas.lan', 'port' => 22], $component->instance()->payload()['config']);
    }

    public function test_validation_errors_of_uptime_land_at_the_fields(): void
    {
        $component = Livewire::test(Monitors::class, ['installedApp' => $this->installed])
            ->call('create')
            ->set('form.url', 'kaputt');

        // armed only now: the next request is the POST of save()
        $this->nextError = ['status' => 400, 'body' => ['error' => 'Bitte die markierten Felder prüfen', 'fields' => ['config.url' => 'Bitte eine gültige http- oder https-URL angeben', 'name' => 'Bitte einen Namen angeben']]];

        $component->call('save')
            ->assertHasErrors(['form.url', 'form.name'])
            ->assertDispatched('notice', kind: 'error')
            ->assertSet('editing', 'new');
    }

    public function test_edit_keeps_channels_and_puts_the_monitor(): void
    {
        Livewire::test(Monitors::class, ['installedApp' => $this->installed])
            ->call('edit', 'm1')
            ->assertSet('form.name', 'Webseite')
            ->assertSet('form.url', 'https://webseite.example')
            ->set('form.name', 'Webseite neu')
            ->call('save')
            ->assertHasNoErrors();

        $put = collect($this->calls)->firstWhere('method', 'PUT');
        $this->assertSame('/api/monitors/m1', $put['path']);
        $this->assertSame('Webseite neu', $put['body']['name']);
        $this->assertSame(['c1'], $put['body']['channelIds']);
    }

    public function test_pause_and_delete(): void
    {
        Livewire::test(Monitors::class, ['installedApp' => $this->installed])
            ->call('pause', 'm1')->assertDispatched('notice', kind: 'success')
            ->call('delete', 'm2')->assertDispatched('notice', kind: 'success');

        $paths = collect($this->calls)->map(fn ($c) => $c['method'].' '.$c['path']);
        $this->assertContains('POST /api/monitors/m1/pause', $paths);
        $this->assertContains('DELETE /api/monitors/m2', $paths);
    }

    public function test_unreachable_app_is_shown_in_the_page(): void
    {
        Http::swap(new Factory(app('events')));
        Http::fake(['*' => Http::failedConnection()]);

        Livewire::test(Monitors::class, ['installedApp' => $this->installed])
            ->assertSee('PK95 Uptime antwortet nicht');
    }

    public function test_status_page_with_selected_monitors(): void
    {
        Livewire::test(StatusPages::class, ['installedApp' => $this->installed])
            ->assertSee('Noch keine Statusseite.')
            ->call('create')
            ->set('form.title', 'Homelab')
            ->set('form.slug', 'homelab')
            ->set('form.monitors.m1.selected', true)
            ->set('form.monitors.m1.publicName', 'Website')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('notice', kind: 'success')
            ->assertSee('/status/homelab');

        $post = collect($this->calls)->first(fn ($c) => $c['method'] === 'POST' && $c['path'] === '/api/status-pages');
        $this->assertSame([['monitorId' => 'm1', 'publicName' => 'Website', 'group' => 'Extern']], $post['body']['monitors']);
        $this->assertTrue($post['body']['enabled']);
        $this->assertSame('90d', $post['body']['range']);
    }

    public function test_pages_are_locked_for_an_unsupported_uptime_version(): void
    {
        $this->installed->update(['manifest' => ['version' => '2.0.0'] + $this->installed->manifest, 'version' => '2.0.0']);

        $this->get(route('apps.page', [$this->installed, 'monitors']))->assertNotFound();
    }
}
