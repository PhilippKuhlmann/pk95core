<?php

namespace Tests\Feature;

use App\Models\AppLink;
use App\Models\InstalledApp;
use App\Modules\ProxApps\Bridged;
use App\Modules\ProxApps\BridgeData;
use App\Modules\ProxApps\Pages\Deployments;
use App\Modules\ProxApps\ProxAppsModule;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Livewire\Component;
use Livewire\Livewire;

/**
 * The ProxApps pages in Core against a fake of ProxApps' /api/bridge.
 */
class ProxAppsBridgeTest extends FeatureTestCase
{
    private InstalledApp $installed;

    /** @var list<array{component: string, body: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, mixed> next answer of the fake */
    private array $answer = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['pk95.catalog_dir' => base_path('catalog'), 'pk95.modules' => ['proxapps' => ProxAppsModule::class]]);
        $this->signIn();
        $this->installed = InstalledApp::factory()->create([
            'app_id' => 'proxapps', 'version' => '1.0.0',
            'manifest' => ['id' => 'proxapps', 'name' => 'ProxApps', 'version' => '1.0.0', 'protocol' => 1, 'icon' => 'server',
                'menu' => [['key' => 'dashboard', 'label' => 'Start', 'icon' => 'home'], ['key' => 'deployments', 'label' => 'Deployments', 'icon' => 'rocket']],
                'capabilities' => [], 'events' => []],
        ]);
        Http::fake(['pk95-proxapps:8080/api/bridge/*' => function (Request $r) {
            $this->calls[] = ['component' => basename(parse_url($r->url(), PHP_URL_PATH)), 'body' => $r->data()];

            return Http::response($this->answer + ['state' => [], 'errors' => [], 'view' => [], 'redirect' => null, 'notices' => [], 'events' => []]);
        }]);
    }

    public function test_view_data_reads_like_the_original(): void
    {
        $data = BridgeData::wrap(['app' => ['name' => 'AdGuard', 'resources' => ['cores' => 1]], 'list' => [['id' => 1]]]);
        $this->assertSame('AdGuard', $data->app->name);
        $this->assertSame(1, $data->app->resources['cores']);
        $this->assertInstanceOf(Collection::class, $data->list);
        $this->assertSame(1, $data->list->first()->id);
        $this->assertCount(2, $data);
    }

    public function test_sub_pages_are_routable_but_not_in_the_menu(): void
    {
        $this->answer = ['view' => ['deployments' => ['__paginator' => true, 'data' => [], 'current_page' => 1, 'last_page' => 1, 'total' => 0], 'hasActive' => false]];

        $this->get(route('apps.page', [$this->installed, 'deployments']))->assertOk()->assertSee('Noch nichts ausgerollt.');
        $this->get('/')->assertDontSee(route('apps.page', [$this->installed, 'deploy']).'"', false);
        $this->assertSame('deployments', $this->calls[0]['component']);
        $this->assertArrayHasKey('mount', $this->calls[0]['body']);
    }

    public function test_state_errors_notices_events_and_redirects_travel_through_the_bridge(): void
    {
        $this->answer = ['state' => ['appId' => 'adguard-home', 'v' => ['hostname' => 'dns']]];
        $page = Livewire::test(BridgedProbe::class, ['installedApp' => $this->installed]);
        $this->assertSame(['app' => 'adguard-home'], $this->calls[0]['body']['mount']);
        $this->assertSame(['from' => 7], $this->calls[0]['body']['query']);
        $page->assertSet('v', ['hostname' => 'dns']);

        // a field change runs the original's updated hooks, errors come back to the fields
        $this->answer = ['state' => ['v' => ['hostname' => 'x y']], 'errors' => ['hostname' => ['Ungültiger Hostname']],
            'notices' => [['kind' => 'error', 'text' => 'Bitte prüfen']], 'events' => [['name' => 'log-lines', 'params' => ['lines' => [1]]]]];
        $page->set('v.hostname', 'x y')
            ->assertHasErrors('hostname')
            ->assertDispatched('notice', kind: 'error', text: 'Bitte prüfen')
            ->assertDispatched('log-lines', lines: [1]);
        $this->assertSame('v.hostname', end($this->calls)['body']['updated']);

        // a redirect of the original maps to the matching Core page
        $this->answer = ['redirect' => '/deployments/12'];
        $page->call('submit')->assertRedirect(route('apps.page', [$this->installed, 'deployment']).'?id=12');
        $this->assertSame(['method' => 'submit', 'params' => []], end($this->calls)['body']['call']);
    }

    /**
     * View data of the detail page as ProxApps sends it (BridgeSerializer).
     *
     * @param  array<string, mixed>  $deployment
     * @param  array<string, string>  $removeStates
     * @return array<string, mixed>
     */
    private function detailView(array $deployment, array $result, array $removeStates): array
    {
        $steps = [['validate', 'Prüfungen'], ['verify', 'Funktionsprüfung']];

        return ['view' => [
            'd' => $deployment + ['id' => 6, 'app_id' => 'adguard-home', 'app_name' => 'AdGuard Home', 'hostname' => 'dns', 'vmid' => 250053,
                'ip' => '10.0.0.53', 'node' => 'pve1', 'connection' => null, 'created_at' => '2026-11-08T10:00:00+00:00', 'finished_at' => null,
                'is_active' => $deployment['status'] === 'removing', 'error' => null],
            'spec' => ['net' => ['mode' => 'static', 'prefix' => 24]], 'result' => $result, 'app' => null,
            'steps' => $steps, 'stepStates' => ['validate' => 'done', 'verify' => 'done'],
            'removeSteps' => [['r_check', 'Prüfungen'], ['r_stop', 'Container stoppen'], ['r_destroy', 'Container löschen'], ['r_cleanup', 'Aufräumen']],
            'removeStepStates' => $removeStates, 'initialLines' => [], 'hasRootPassword' => false, 'revealed' => null,
            'canManage' => $deployment['status'] === 'success', 'managed' => false, 'updateRuns' => [], 'updateActive' => false,
        ]];
    }

    public function test_detail_page_shows_the_proxy_domain_and_the_removal_steps(): void
    {
        $link = AppLink::query()->create(['source_app' => 'proxapps', 'source_id' => 6, 'name' => 'AdGuard Home', 'domain' => 'dns.example.de',
            'target' => 'http://10.0.0.53:80', 'proxy_host_id' => 3, 'monitor_ids' => ['m1'], 'state' => AppLink::ACTIVE]);
        $pending = ['r_check' => 'pending', 'r_stop' => 'pending', 'r_destroy' => 'pending', 'r_cleanup' => 'pending'];

        $this->answer = $this->detailView(['status' => 'success', 'step' => 'done'], ['url' => 'http://10.0.0.53/'], $pending);
        $this->get(route('apps.page', [$this->installed, 'deployment']).'?id=6')->assertOk()
            ->assertSee('AdGuard Home ist bereit')->assertSee('https://dns.example.de/')->assertSee('Intern')->assertDontSee('Fortschritt beim Entfernen');

        // container removed, Core still has to take down proxy host and monitors
        $this->answer = $this->detailView(['status' => 'removed', 'step' => 'removed'], ['container_removed' => true],
            array_fill_keys(array_keys($pending), 'done'));
        $html = $this->get(route('apps.page', [$this->installed, 'deployment']).'?id=6')->assertOk()
            ->assertSee('Container löschen')->assertSee('Proxy &amp; Monitore entfernen', false)->getContent();
        $this->assertSame(1, substr_count($html, 'aria-current="step"'), 'the Core step is running');

        $link->update(['state' => AppLink::REMOVED, 'proxy_host_id' => null, 'monitor_ids' => []]);
        $html = $this->get(route('apps.page', [$this->installed, 'deployment']).'?id=6')->assertOk()->getContent();
        $this->assertSame(0, substr_count($html, 'aria-current="step"'), 'everything done');

        // removal failed at the stop: back to success, error box and red step
        $link->update(['state' => AppLink::ACTIVE, 'proxy_host_id' => 3]);
        $this->answer = $this->detailView(['status' => 'success', 'step' => 'done'], ['removal_error' => 'Stoppen fehlgeschlagen: timeout', 'removal_step' => 'r_stop'],
            ['r_check' => 'done', 'r_stop' => 'error', 'r_destroy' => 'pending', 'r_cleanup' => 'pending']);
        $this->get(route('apps.page', [$this->installed, 'deployment']).'?id=6')->assertOk()
            ->assertSee('Entfernen fehlgeschlagen')->assertSee('Stoppen fehlgeschlagen: timeout');
    }

    public function test_deployments_overview_separates_running_active_and_history(): void
    {
        $row = fn (int $id, string $status, string $step, string $host) => ['id' => $id, 'status' => $status, 'step' => $step, 'app_id' => 'adguard-home',
            'app_name' => 'AdGuard Home', 'hostname' => $host, 'vmid' => 250000 + $id, 'ip' => '10.0.0.'.$id, 'spec' => [], 'node' => 'pve1',
            'connection' => null, 'created_at' => '2026-11-08T10:00:00+00:00', 'finished_at' => null];
        $page = fn (array $rows) => ['__paginator' => true, 'data' => $rows, 'current_page' => 1, 'last_page' => 1, 'total' => count($rows)];
        AppLink::query()->create(['source_app' => 'proxapps', 'source_id' => 2, 'name' => 'AdGuard Home', 'domain' => 'dns.example.de',
            'target' => 'http://10.0.0.2:80', 'proxy_host_id' => 3, 'monitor_ids' => [], 'state' => AppLink::ACTIVE]);
        $this->answer = ['view' => [
            'hasActive' => true, 'deployments' => $page([]),
            'running' => [$row(1, 'removing', 'r_destroy', 'alt')],
            'live' => [$row(2, 'success', 'done', 'dns')],
            'history' => $page([$row(3, 'removed', 'removed', 'weg')]),
            'stepLabels' => ['r_check' => 'Prüfungen', 'r_stop' => 'Container stoppen', 'r_destroy' => 'Container löschen', 'r_cleanup' => 'Aufräumen'],
            'stepOrder' => ['deploy' => ['validate', 'verify'], 'remove' => ['r_check', 'r_stop', 'r_destroy', 'r_cleanup']],
            'icons' => ['adguard-home' => false],
        ]];

        $this->get(route('apps.page', [$this->installed, 'deployments']))->assertOk()
            ->assertSeeInOrder(['Läuft gerade', 'alt', 'Container löschen', 'Schritt 3 von 4', 'Aktive Apps', 'dns', 'dns.example.de', 'Verlauf', 'weg'])
            ->assertSee('1 App läuft');
    }

    public function test_pagination_and_unreachable_app(): void
    {
        $row = ['id' => 5, 'status' => 'success', 'app_name' => 'AdGuard Home', 'hostname' => 'dns', 'vmid' => 250053, 'ip' => '10.0.0.53',
            'spec' => [], 'node' => 'pve1', 'connection' => null, 'created_at' => '2026-11-08T10:00:00+00:00'];
        $this->answer = ['view' => ['deployments' => ['__paginator' => true, 'data' => [$row], 'current_page' => 2, 'last_page' => 3, 'total' => 120], 'hasActive' => false]];
        // the proxy domain Core created for the deployment stands next to the IP
        AppLink::query()->create(['source_app' => 'proxapps', 'source_id' => 5, 'name' => 'AdGuard Home', 'domain' => 'dns.example.de',
            'target' => 'http://10.0.0.53:80', 'proxy_host_id' => 3, 'monitor_ids' => [], 'state' => AppLink::PENDING_DNS]);
        Livewire::test(Deployments::class, ['installedApp' => $this->installed])->call('goToPage', 2)->assertSee('Seite 2 von 3')
            ->assertSee('dns.example.de')->assertSee('Wartet auf DNS');
        $this->assertSame(['page' => 2], end($this->calls)['body']['query']);

        Http::swap(new Factory(app('events')));
        Http::fake(['*' => Http::failedConnection()]);
        $this->get(route('apps.page', [$this->installed, 'dashboard']))->assertOk()->assertSee('ProxApps antwortet nicht');
        // pages that need their view data show the same hint instead of failing
        foreach (['deployments', 'deploy'] as $page) {
            $this->get(route('apps.page', [$this->installed, $page]))->assertOk()->assertSee('ProxApps antwortet nicht')->assertSee('Erneut versuchen');
        }
    }
}

/**
 * A page like Deploy with a trivial view: tests the bridge mechanics without the view data
 * of the real deploy form (that one is exercised end to end against ProxApps).
 */
class BridgedProbe extends Component
{
    use Bridged;

    public $appId = '';

    public $v = [];

    protected function component(): string
    {
        return 'deploy';
    }

    protected function viewName(): string
    {
        return '';
    }

    protected function mountArgs(): array
    {
        return ['app' => 'adguard-home'];
    }

    protected function query(): array
    {
        return ['from' => 7];
    }

    public function submit(): void
    {
        $this->remote('submit');
    }

    public function render()
    {
        return '<div>probe</div>';
    }
}
