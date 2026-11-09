<?php

namespace Tests\Feature;

use App\Jobs\LinkDeployment;
use App\Livewire\Automation\Index as AutomationIndex;
use App\Models\AppEvent;
use App\Models\AppLink;
use App\Models\InstalledApp;
use App\Modules\Proxy\Hosts;
use App\Services\Automation\LanIp;
use App\Services\Automation\Linker;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Deployments of ProxApps are linked with PK95 Proxy (host) and PK95 Uptime (monitors).
 */
class AutomationTest extends FeatureTestCase
{
    /** @var list<array{method: string, url: string, body: mixed}> */
    private array $calls = [];

    /** @var list<string> A records the fake DNS answers with */
    private array $dnsAnswer = [];

    /** @var array<int, array<string, mixed>> */
    private array $hosts = [];

    private int $monitorSeq = 0;

    /** @var array<int, array<string, mixed>> local DNS records in PK95 DNS */
    private array $rewrites = [];

    /** PK95 DNS answers rewrite requests with 500 (unreachable app is Http::failedConnection). */
    private bool $dnsBroken = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pk95.catalog_dir' => base_path('catalog')]);
        foreach (['proxapps', 'proxy', 'uptime', 'dns'] as $id) {
            InstalledApp::factory()->create(['app_id' => $id, 'status' => InstalledApp::RUNNING, 'version' => '1.0.0',
                'manifest' => ['id' => $id, 'name' => $id, 'version' => '1.0.0', 'protocol' => 1, 'menu' => [], 'capabilities' => [], 'events' => []]]);
        }
        Settings::put('automation_base_domain', 'example.de');
        Settings::put('automation_public_ip', '203.0.113.10');
        Settings::put('automation_lan_ip', '10.10.250.50');

        Http::fake([
            'cloudflare-dns.com/*' => fn () => Http::response(['Status' => 0, 'Answer' => array_map(fn ($ip) => ['type' => 1, 'data' => $ip], $this->dnsAnswer)]),
            'host.docker.internal:8090/*' => fn (Request $r) => $this->proxy($r),
            'pk95-uptime:3001/*' => fn (Request $r) => $this->uptime($r),
            'host.docker.internal:8053/*' => fn (Request $r) => $this->dnsApp($r),
        ]);
    }

    /** PK95 DNS: /api/rewrites like docs/api.md of pk95dns. */
    private function dnsApp(Request $r)
    {
        $this->calls[] = ['method' => $r->method(), 'url' => $r->url(), 'body' => $r->data()];
        if ($this->dnsBroken) {
            return Http::response(['error' => 'Datenbank gesperrt'], 500);
        }
        $path = parse_url($r->url(), PHP_URL_PATH);
        if ($path === '/api/rewrites') {
            if ($r->method() === 'GET') {
                return Http::response(array_values($this->rewrites));
            }
            $id = ($this->rewrites ? max(array_keys($this->rewrites)) : 0) + 1;
            $this->rewrites[$id] = ['id' => $id] + $r->data();

            return Http::response($this->rewrites[$id], 201);
        }
        if (preg_match('#^/api/rewrites/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            if (! isset($this->rewrites[$id])) {
                return Http::response(['error' => 'nicht gefunden'], 404);
            }
            if ($r->method() === 'DELETE') {
                unset($this->rewrites[$id]);

                return Http::response('', 204);
            }
            $this->rewrites[$id] = ['id' => $id] + $r->data();

            return Http::response($this->rewrites[$id]);
        }

        return Http::response(['error' => 'unbekannt'], 404);
    }

    private function proxy(Request $r)
    {
        $this->calls[] = ['method' => $r->method(), 'url' => $r->url(), 'body' => $r->data()];
        $path = parse_url($r->url(), PHP_URL_PATH);
        if ($r->method() === 'GET' && $path === '/api/hosts') {
            return Http::response(array_values($this->hosts));
        }
        if ($r->method() === 'POST' && $path === '/api/hosts') {
            $id = count($this->hosts) + 1;
            $this->hosts[$id] = ['id' => $id] + $r->data();

            return Http::response($this->hosts[$id], 201);
        }
        if (preg_match('#^/api/hosts/(\d+)$#', $path, $m)) {
            if ($r->method() === 'DELETE') {
                unset($this->hosts[(int) $m[1]]);

                return Http::response(['ok' => true]);
            }
            $this->hosts[(int) $m[1]] = ['id' => (int) $m[1]] + $r->data();

            return Http::response($this->hosts[(int) $m[1]]);
        }

        return Http::response(['error' => 'unbekannt'], 404);
    }

    private function uptime(Request $r)
    {
        $this->calls[] = ['method' => $r->method(), 'url' => $r->url(), 'body' => $r->data()];

        // like PK95 Uptime: every writing request must be application/json (CSRF guard)
        if (! $r->hasHeader('Content-Type', 'application/json')) {
            return Http::response(['error' => 'Die Anfrage muss als application/json gesendet werden'], 415);
        }

        return $r->method() === 'POST' ? Http::response(['id' => 'm'.(++$this->monitorSeq)] + $r->data(), 201) : Http::response(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $domain = 'cloud.example.de'): array
    {
        return ['deploymentId' => 7, 'appId' => 'nextcloud-aio', 'appName' => 'Nextcloud AIO', 'hostname' => 'nextcloud', 'vmid' => 250060,
            'ip' => '10.0.0.60', 'domain' => $domain, 'proxyUrl' => 'http://10.0.0.60:11000'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sent(string $method, string $needle): array
    {
        return array_values(array_map(fn ($c) => $c['body'], array_filter($this->calls, fn ($c) => $c['method'] === $method && str_contains($c['url'], $needle))));
    }

    public function test_deployment_events_of_proxapps_start_the_linking(): void
    {
        Queue::fake();
        $token = InstalledApp::query()->where('app_id', 'proxapps')->value('token');
        $this->withToken($token)->postJson('/api/v1/events', ['type' => 'deployment.succeeded', 'severity' => 'info', 'title' => 'x', 'data' => $this->event()])
            ->assertStatus(202);
        Queue::assertPushed(LinkDeployment::class, fn ($job) => $job->type === 'deployment.succeeded' && $job->data['domain'] === 'cloud.example.de');

        // other apps cannot trigger it
        $uptime = InstalledApp::query()->where('app_id', 'uptime')->value('token');
        $this->withToken($uptime)->postJson('/api/v1/events', ['type' => 'deployment.succeeded', 'severity' => 'info', 'title' => 'x'])->assertStatus(202);
        Queue::assertPushed(LinkDeployment::class, 1);
    }

    public function test_domain_pointing_here_gives_an_active_public_host_and_two_monitors(): void
    {
        $this->dnsAnswer = ['203.0.113.10'];
        $link = app(Linker::class)->link('proxapps', $this->event());

        $this->assertSame(AppLink::ACTIVE, $link->state);
        $host = $this->sent('POST', '/api/hosts')[0];
        $this->assertSame(['cloud.example.de'], $host['domains']);
        $this->assertSame(['http', '10.0.0.60', 11000], [$host['scheme'], $host['upstream_host'], $host['upstream_port']]);
        $this->assertTrue($host['enabled']);
        $this->assertSame(0, $host['access_list_id'], 'public');
        $this->assertTrue($host['block_exploits']);

        $monitors = $this->sent('POST', 'pk95-uptime');
        $this->assertCount(2, $monitors);
        $this->assertSame('http://10.0.0.60:11000', $monitors[0]['config']['url']);
        $this->assertSame(0, $monitors[0]['config']['certWarnDays'], 'Uptime needs a number, 0 = no certificate warning');
        $this->assertSame('https://cloud.example.de/', $monitors[1]['config']['url']);
        $this->assertSame(14, $monitors[1]['config']['certWarnDays']);
        $this->assertSame(['m1', 'm2'], $link->monitor_ids);
        $this->assertDatabaseHas('app_events', ['app_id' => AppEvent::CORE, 'type' => 'core.link.active']);
    }

    public function test_without_dns_the_host_waits_disabled_and_is_switched_on_later(): void
    {
        $this->dnsAnswer = [];
        $link = app(Linker::class)->link('proxapps', $this->event());

        $this->assertSame(AppLink::PENDING_DNS, $link->state);
        $this->assertFalse($this->sent('POST', '/api/hosts')[0]['enabled']);
        $this->assertCount(1, $this->sent('POST', 'pk95-uptime'), 'only the internal monitor while the domain is not reachable');
        $this->assertStringContainsString('A-Eintrag auf 203.0.113.10', $link->message);

        app(Linker::class)->recheck($link);
        $this->assertSame(AppLink::PENDING_DNS, $link->fresh()->state);

        $this->dnsAnswer = ['203.0.113.10'];
        $this->artisan('pk95:links')->assertSuccessful();
        $link->refresh();
        $this->assertSame(AppLink::ACTIVE, $link->state);
        $this->assertTrue($this->hosts[$link->proxy_host_id]['enabled']);
        $put = $this->sent('PUT', '/api/hosts/')[0];
        $this->assertTrue($put['enabled']);
        $this->assertSame([], array_diff(array_keys($put), Hosts::FIELDS), 'no field the panel would reject');
        $this->assertCount(2, $link->monitor_ids);
    }

    public function test_proxy_entry_switched_off_gives_only_the_internal_monitor(): void
    {
        $this->dnsAnswer = ['203.0.113.10'];
        $link = app(Linker::class)->link('proxapps', ['proxy' => false, 'domain' => ''] + $this->event());

        $this->assertCount(0, $this->sent('POST', '/api/hosts'));
        $this->assertCount(1, $this->sent('POST', 'pk95-uptime'));
        $this->assertSame(AppLink::ACTIVE, $link->state);
        $this->assertSame('Proxy-Eintrag beim Ausrollen abgewählt.', $link->message);
        $this->assertNull($link->shownDomain());
    }

    public function test_automation_page_warns_without_base_domain(): void
    {
        $this->signIn();
        $this->get(route('automation'))->assertDontSee('Keine Basisdomain festgelegt');
        Settings::put('automation_base_domain', '');
        $this->get(route('automation'))->assertSee('Keine Basisdomain festgelegt');
    }

    public function test_existing_host_is_left_alone_and_removal_cleans_up(): void
    {
        $this->hosts[1] = ['id' => 1, 'domains' => ['cloud.example.de']];
        $this->dnsAnswer = ['203.0.113.10'];
        $link = app(Linker::class)->link('proxapps', $this->event());
        $this->assertNull($link->proxy_host_id);
        $this->assertStringContainsString('gibt es schon einen Host', $link->message);
        $this->assertCount(0, $this->sent('POST', '/api/hosts'));

        unset($this->hosts[1]);
        $link = app(Linker::class)->link('proxapps', ['deploymentId' => 8] + $this->event('vault.example.de'));
        app(Linker::class)->unlink('proxapps', 8);

        $this->assertSame(AppLink::REMOVED, $link->fresh()->state);
        $this->assertNull($link->fresh()->message, 'everything deleted without errors');
        $this->assertSame([], $this->hosts);
        $this->assertSame([], $this->rewrites);
        $deleted = array_values(array_filter($this->calls, fn ($c) => $c['method'] === 'DELETE'));
        $this->assertCount(4, $deleted, 'host, local DNS record and two monitors');
    }

    public function test_context_api_and_settings_page(): void
    {
        $token = InstalledApp::query()->where('app_id', 'proxapps')->value('token');
        $this->getJson('/api/v1/context')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/v1/context')->assertOk()
            ->assertExactJson(['base_domain' => 'example.de', 'proxy' => true, 'uptime' => true]);

        $this->signIn();
        $this->get(route('automation'))->assertOk()->assertSee('Basisdomain');
        Livewire::test(AutomationIndex::class)
            ->set('baseDomain', ' https://kaputt ')->call('save')->assertHasErrors('baseDomain')
            ->set('baseDomain', 'Andere.de.')->set('uptime', false)->call('save')->assertHasNoErrors();
        $this->assertSame('andere.de', Settings::get('automation_base_domain'));
        $this->assertFalse(Settings::get('automation_uptime'));
    }

    public function test_deploy_creates_a_local_dns_record_for_the_proxy_domain_and_removal_deletes_it(): void
    {
        $this->dnsAnswer = []; // public DNS not there yet: host waits disabled, the local record is created anyway
        $link = app(Linker::class)->link('proxapps', $this->event());

        $this->assertSame(AppLink::PENDING_DNS, $link->state);
        $this->assertSame([['domain' => 'cloud.example.de', 'answer' => '10.10.250.50', 'enabled' => true]], $this->sent('POST', '/api/rewrites'));
        $this->assertSame(1, $link->dns_record_id);
        $this->assertSame('10.10.250.50', $link->dns_answer);
        $this->assertNull($link->dns_message);

        app(Linker::class)->unlink('proxapps', 7);
        $this->assertSame([], $this->rewrites);
        $this->assertNull($link->fresh()->dns_record_id);
    }

    public function test_foreign_dns_record_with_the_same_name_is_left_alone(): void
    {
        $this->rewrites[5] = ['id' => 5, 'domain' => 'Cloud.example.de', 'answer' => '192.168.1.5', 'enabled' => true];
        $this->dnsAnswer = ['203.0.113.10'];
        $link = app(Linker::class)->link('proxapps', $this->event());

        $this->assertSame(AppLink::ACTIVE, $link->state, 'the deploy itself is fine');
        $this->assertCount(0, $this->sent('POST', '/api/rewrites'));
        $this->assertNull($link->dns_record_id);
        $this->assertStringContainsString('schon einen lokalen Eintrag (→ 192.168.1.5)', $link->dns_message);
        $this->assertDatabaseHas('app_events', ['type' => 'core.link.active', 'severity' => 'warning']);

        app(Linker::class)->unlink('proxapps', 7);
        $this->assertArrayHasKey(5, $this->rewrites, 'not ours: not deleted');
    }

    public function test_without_dns_app_the_deploy_works_and_the_record_is_caught_up_later(): void
    {
        $this->dnsAnswer = ['203.0.113.10'];
        InstalledApp::query()->where('app_id', 'dns')->delete();
        $link = app(Linker::class)->link('proxapps', $this->event());
        $this->assertSame(AppLink::ACTIVE, $link->state);
        $this->assertNotNull($link->proxy_host_id);
        $this->assertStringContainsString('nicht installiert', $link->dns_message);

        // installed, but stopped
        $dns = InstalledApp::factory()->create(['app_id' => 'dns', 'status' => InstalledApp::STOPPED, 'version' => '1.0.0',
            'manifest' => ['id' => 'dns', 'name' => 'dns', 'version' => '1.0.0', 'protocol' => 1, 'menu' => [], 'capabilities' => [], 'events' => []]]);
        $this->assertSame(['changed' => 0, 'open' => ['cloud.example.de']], app(Linker::class)->syncDns());
        $this->assertStringContainsString('läuft gerade nicht', $link->fresh()->dns_message);

        // running, but failing
        $dns->update(['status' => InstalledApp::RUNNING]);
        $this->dnsBroken = true;
        app(Linker::class)->syncDns();
        $this->assertStringContainsString('nicht angelegt: Datenbank gesperrt', $link->fresh()->dns_message);

        // works again: the button on the Automatik page catches it up
        $this->dnsBroken = false;
        $this->signIn();
        Livewire::test(AutomationIndex::class)->call('syncDns')->assertDispatched('notice', kind: 'success', text: '1 DNS-Eintrag angelegt oder angepasst.');
        $link->refresh();
        $this->assertNull($link->dns_message);
        $this->assertSame('10.10.250.50', $this->rewrites[$link->dns_record_id]['answer']);
        $this->assertDatabaseHas('app_events', ['type' => 'core.link.dns']);
        $this->assertSame(['changed' => 0, 'open' => []], app(Linker::class)->syncDns(), 'nothing to do twice');

        // a new LAN IP moves Core's record, nothing else
        Settings::put('automation_lan_ip', '10.10.250.60');
        $this->artisan('pk95:links')->assertSuccessful();
        $this->assertSame('10.10.250.60', $this->rewrites[$link->dns_record_id]['answer']);
        $this->assertSame('10.10.250.60', $link->fresh()->dns_answer);
    }

    public function test_dns_records_switched_off_or_without_proxy_host(): void
    {
        $this->dnsAnswer = ['203.0.113.10'];
        Settings::put('automation_dns', false);
        $link = app(Linker::class)->link('proxapps', $this->event());
        $this->assertNotNull($link->proxy_host_id);
        $this->assertNull($link->dns_record_id);
        $this->assertNull($link->dns_message);

        Settings::put('automation_dns', true);
        $other = app(Linker::class)->link('proxapps', ['deploymentId' => 9, 'proxy' => false] + $this->event('mail.example.de'));
        $this->assertNull($other->dns_record_id, 'no proxy host, no DNS record');
        $this->assertSame([], $this->sent('POST', '/api/rewrites'));
    }

    public function test_lan_ip_is_detected_in_the_host_network_and_the_setting_wins(): void
    {
        Settings::put('automation_lan_ip', '');
        $this->docker['helperOutput'] = '1.1.1.1 via 10.10.250.1 dev eth0  src 10.10.250.50 ';
        $this->assertSame('10.10.250.50', app(LanIp::class)->current());
        $run = $this->docker['helperRuns'][0];
        $this->assertSame(['ip', '-4', 'route', 'get', '1.1.1.1'], $run['cmd']);
        $this->assertSame('host', $run['network']);

        app(LanIp::class)->current();
        $this->assertCount(1, $this->docker['helperRuns'], 'cached');

        Settings::put('automation_lan_ip', '192.168.1.9');
        $this->assertSame('192.168.1.9', app(LanIp::class)->current());

        // the page: detection again, failure is shown, a manual value is validated
        Settings::put('automation_lan_ip', '');
        $this->docker['helperOutput'] = 'RTNETLINK answers: Network unreachable';
        $this->signIn();
        $this->get(route('automation'))->assertSee('LAN-IP des Proxy')->assertSee('10.10.250.50');
        Livewire::test(AutomationIndex::class)
            ->call('detectLanIp')->assertDispatched('notice', kind: 'error')
            ->assertSee('Nicht erkannt: Keine Route ins Internet gefunden: RTNETLINK answers: Network unreachable. Trag')
            ->set('lanIp', '10.10.250')->call('save')->assertHasErrors('lanIp')
            ->set('lanIp', '10.10.250.70')->set('dns', false)->call('save')->assertHasNoErrors();
        $this->assertSame('10.10.250.70', Settings::get('automation_lan_ip'));
        $this->assertFalse(Settings::get('automation_dns'));
        Cache::forget(LanIp::CACHE);
        $this->assertSame('10.10.250.70', app(LanIp::class)->current());
    }
}
