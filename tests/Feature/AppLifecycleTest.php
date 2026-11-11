<?php

namespace Tests\Feature;

use App\Jobs\InstallApp;
use App\Models\InstalledApp;
use App\Services\Apps\AppException;
use App\Services\Apps\AppManager;
use App\Services\Automation\Linker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

class AppLifecycleTest extends FeatureTestCase
{
    private function manager(): AppManager
    {
        return app(AppManager::class);
    }

    public function test_activation_pulls_creates_starts_and_reads_the_manifest(): void
    {
        $app = $this->manager()->activate('demo');
        $app->refresh();

        $this->assertSame(InstalledApp::RUNNING, $app->status, (string) $app->error);
        $this->assertSame('1.2.0', $app->version);
        $this->assertSame('Demo-App', $app->manifest()->name);
        $this->assertSame('cid-pk95-demo', $app->container_id);
        $this->assertArrayHasKey('pk95', $this->docker['networks']);
        $this->assertArrayHasKey('pk95-demo-data', $this->docker['volumes']);

        $container = $this->container('pk95-demo');
        $this->assertTrue($container['running']);
        $this->assertSame('example/demo:1.0.0', $container['spec']['Image']);
        $this->assertContains('PK95_CORE_TOKEN='.$app->token, $container['spec']['Env']);
        $this->assertContains('LOG_LEVEL=info', $container['spec']['Env']);

        // every call to the app carries the per-app token
        $this->assertNotEmpty($this->appRequests);
        foreach ($this->appRequests as $request) {
            $this->assertSame('Bearer '.$app->token, $request['auth']);
        }
        $this->assertTrue($app->logs()->where('level', 'success')->exists());
    }

    public function test_activating_uptime_publishes_the_status_port_and_sets_up_its_public_address(): void
    {
        // the real catalog entry of Uptime, its API answered like the demo app
        config(['pk95.catalog_dir' => base_path('catalog')]);
        $this->appManifest['id'] = 'uptime';
        Http::fake(['pk95-uptime:3001/*' => fn (Request $r) => match (parse_url($r->url(), PHP_URL_PATH)) {
            '/core/health' => Http::response(['ok' => true]),
            '/core/manifest' => Http::response($this->appManifest),
            default => Http::response(['error' => 'not found'], 404),
        }]);
        $this->mock(Linker::class, fn (MockInterface $m) => $m->shouldReceive('linkStatusPages')->once()->andReturnNull());

        $app = $this->manager()->activate('uptime')->refresh();

        $this->assertSame(InstalledApp::RUNNING, $app->status);
        $spec = $this->container('pk95-uptime')['spec'];
        $this->assertContains('PUBLIC_PORT=3002', $spec['Env']);
        $this->assertContains('PUBLIC_URL=https://status.kuhlmannit.de', $spec['Env']);
        $this->assertContains('TRUST_PROXY=1', $spec['Env']);
        $this->assertSame([['HostPort' => '3002']], $spec['HostConfig']['PortBindings']['3002/tcp']);
        $this->assertArrayNotHasKey('3001/tcp', $spec['HostConfig']['PortBindings'], 'the admin API stays internal');
    }

    public function test_other_apps_do_not_touch_the_status_pages(): void
    {
        $this->mock(Linker::class, fn (MockInterface $m) => $m->shouldNotReceive('linkStatusPages'));

        $this->assertSame(InstalledApp::RUNNING, $this->manager()->activate('demo')->refresh()->status);
    }

    public function test_activation_is_queued(): void
    {
        Queue::fake();

        $app = $this->manager()->activate('demo', '1.1.0');

        $this->assertSame(InstalledApp::INSTALLING, $app->status);
        $this->assertSame('1.1.0', $app->tag);
        Queue::assertPushed(InstallApp::class, fn (InstallApp $job) => $job->installedAppId === $app->id);
        $this->expectException(AppException::class);
        $this->manager()->activate('demo');
    }

    public function test_failed_pull_ends_in_failed_status(): void
    {
        $this->docker['pullError'] = 'manifest unknown';

        $app = $this->manager()->activate('demo')->refresh();

        $this->assertSame(InstalledApp::FAILED, $app->status);
        $this->assertStringContainsString('Image example/demo:1.0.0 konnte nicht geladen werden', $app->error);
        $this->assertStringContainsString('nicht öffentlich', $app->error);
        $this->assertStringContainsString('manifest unknown', $app->error);
        $this->assertNull($this->container('pk95-demo'));
    }

    public function test_failed_pull_falls_back_to_a_local_image(): void
    {
        $this->docker['pullError'] = 'pull access denied for example/demo';
        $this->docker['images']['example/demo:1.0.0'] = true;

        $app = $this->manager()->activate('demo')->refresh();

        $this->assertSame(InstalledApp::RUNNING, $app->status, (string) $app->error);
        $this->assertTrue($app->logs()->where('level', 'warning')->where('message', 'like', '%lokale Kopie%')->exists());
    }

    public function test_app_that_never_gets_healthy_fails_after_the_timeout(): void
    {
        $this->appHealthy = false;

        $app = $this->manager()->activate('demo')->refresh();

        $this->assertSame(InstalledApp::FAILED, $app->status);
        $this->assertStringContainsString('Zeitlimit', $app->error);
    }

    public function test_manifest_of_another_app_is_rejected(): void
    {
        $this->appManifest['id'] = 'other';

        $app = $this->manager()->activate('demo')->refresh();

        $this->assertSame(InstalledApp::FAILED, $app->status);
        $this->assertStringContainsString('erwartet „demo“', $app->error);
    }

    public function test_reactivation_replaces_the_container_and_keeps_the_volume(): void
    {
        $first = $this->manager()->activate('demo')->refresh();
        $oldToken = $first->token;

        $second = $this->manager()->activate('demo')->refresh();

        $this->assertSame(InstalledApp::RUNNING, $second->status);
        $this->assertNotSame($oldToken, $second->token);
        $this->assertCount(1, $this->docker['containers']);
        $this->assertContains('PK95_CORE_TOKEN='.$second->token, $this->container('pk95-demo')['spec']['Env']);
        $this->assertArrayHasKey('pk95-demo-data', $this->docker['volumes']);
    }

    public function test_stop_start_and_refresh_follow_the_container(): void
    {
        $app = $this->manager()->activate('demo')->refresh();

        $this->manager()->stop($app);
        $this->assertFalse($this->container('pk95-demo')['running']);
        $this->assertSame(InstalledApp::STOPPED, $app->refresh()->status);

        $this->manager()->start($app);
        $this->assertSame(InstalledApp::STARTING, $app->refresh()->status);
        $this->manager()->refresh($app);
        $this->assertSame(InstalledApp::RUNNING, $app->refresh()->status);

        // container stopped behind Core's back
        $this->docker['containers']['cid-pk95-demo']['running'] = false;
        $this->manager()->refresh($app);
        $this->assertSame(InstalledApp::STOPPED, $app->refresh()->status);
    }

    public function test_refresh_detects_unhealthy_and_missing_containers(): void
    {
        $app = $this->manager()->activate('demo')->refresh();

        $this->appHealthy = false;
        $this->manager()->refresh($app);
        $this->assertSame(InstalledApp::UNHEALTHY, $app->refresh()->status);

        $this->appHealthy = true;
        $this->appManifest['version'] = '1.3.0';
        $this->manager()->refresh($app);
        $this->assertSame(InstalledApp::RUNNING, $app->refresh()->status);
        $this->assertSame('1.3.0', $app->version);
        $this->assertTrue($app->logs()->where('message', 'Version 1.2.0 → 1.3.0')->exists());

        $this->docker['containers'] = [];
        $this->manager()->refresh($app);
        $this->assertSame(InstalledApp::FAILED, $app->refresh()->status);
    }

    public function test_remove_keeps_or_deletes_data(): void
    {
        $app = $this->manager()->activate('demo')->refresh();
        $this->manager()->remove($app, keepData: true);

        $this->assertSame(0, InstalledApp::query()->count());
        $this->assertSame([], $this->docker['containers']);
        $this->assertArrayHasKey('pk95-demo-data', $this->docker['volumes']);

        $app = $this->manager()->activate('demo')->refresh();
        $this->manager()->remove($app, keepData: false);
        $this->assertArrayNotHasKey('pk95-demo-data', $this->docker['volumes']);
    }

    public function test_recover_marks_interrupted_activations_as_failed(): void
    {
        InstalledApp::factory()->status(InstalledApp::INSTALLING)->create();

        $this->artisan('pk95:recover')->assertSuccessful();

        $this->assertSame(InstalledApp::FAILED, InstalledApp::query()->first()->status);
    }

    public function test_sync_command_refreshes_all_apps(): void
    {
        $app = $this->manager()->activate('demo')->refresh();
        $this->docker['containers']['cid-pk95-demo']['running'] = false;

        $this->artisan('pk95:sync')->assertSuccessful();

        $this->assertSame(InstalledApp::STOPPED, $app->refresh()->status);
    }
}
