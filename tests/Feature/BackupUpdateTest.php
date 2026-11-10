<?php

namespace Tests\Feature;

use App\Jobs\AppOperation;
use App\Livewire\Apps\Show;
use App\Models\AppEvent;
use App\Models\Backup;
use App\Models\InstalledApp;
use App\Services\Apps\AppManager;
use App\Services\Backups\BackupService;
use App\Services\Updates\Registry;
use App\Services\Updates\UpdateService;
use App\Support\Settings;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class BackupUpdateTest extends FeatureTestCase
{
    private string $dataDir;

    /** @var list<string> */
    private array $registryTags = ['1.0.0', '1.0.3', '1.1.0', '2.0.0', 'latest', 'sha-abc'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = sys_get_temp_dir().'/pk95-data-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dataDir);
        config(['pk95.data_dir' => $this->dataDir]);

        // example/demo lives on Docker Hub: anonymous token, then tags and manifests
        Http::fake([
            'auth.example/*' => Http::response(['token' => 'anon']),
            'registry-1.docker.io/v2/example/demo/tags/list*' => fn ($r) => $r->hasHeader('Authorization')
                ? Http::response(['name' => 'example/demo', 'tags' => $this->registryTags])
                : Http::response('', 401, ['WWW-Authenticate' => 'Bearer realm="https://auth.example/token",service="registry.docker.io",scope="repository:example/demo:pull"']),
            'registry-1.docker.io/v2/example/demo/manifests/*' => Http::response('', 200, ['Docker-Content-Digest' => 'sha256:remote']),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dataDir);
        parent::tearDown();
    }

    private function runningDemo(): InstalledApp
    {
        return app(AppManager::class)->activate('demo')->refresh();
    }

    public function test_backup_runs_tar_in_a_helper_and_restarts_the_app(): void
    {
        // "backup" capability: Core asks the app to flush its data first
        $this->appManifest['capabilities'][] = 'backup';
        $app = $this->runningDemo();

        $backup = app(BackupService::class)->create($app, 'manual');

        $this->assertSame('done', $backup->status);
        $this->assertSame(2048, $backup->size);
        $file = $backup->files['data'];
        $this->assertFileExists($file);
        $this->assertStringStartsWith($this->dataDir.'/backups/demo/', $file);

        $run = $this->docker['helperRuns'][0];
        $this->assertSame(['tar', 'czf', '/backups/'.basename($file), '-C', '/source', '.'], $run['cmd']);
        $this->assertSame(['Type' => 'volume', 'Source' => 'pk95-demo-data', 'Target' => '/source', 'ReadOnly' => true], $run['mounts'][0]);
        $this->assertSame($this->dataDir.'/backups/demo', $run['mounts'][1]['Source']);
        $this->assertTrue($this->container('pk95-demo')['running'], 'app runs again');
        $this->assertContains('/core/backup/prepare', array_column($this->appRequests, 'path'));
        // helper containers are removed again
        $this->assertCount(1, $this->docker['containers']);
    }

    public function test_failed_backup_is_reported_and_the_app_still_restarts(): void
    {
        $app = $this->runningDemo();
        $this->docker['helperExit'] = 2;

        try {
            app(BackupService::class)->create($app);
            $this->fail('expected an exception');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Code 2', $e->getMessage());
        }
        $this->assertSame('failed', Backup::query()->sole()->status);
        $this->assertTrue($this->container('pk95-demo')['running']);
        $this->assertSame('core.backup.failed', AppEvent::query()->latest('id')->first()->type);
        $this->assertNotContains('/core/backup/prepare', array_column($this->appRequests, 'path'), 'no capability, no prepare');
    }

    public function test_restore_extracts_into_the_volume(): void
    {
        $app = $this->runningDemo();
        $backup = app(BackupService::class)->create($app);

        app(BackupService::class)->restore($backup, $app);

        $run = end($this->docker['helperRuns']);
        $this->assertSame('sh', $run['cmd'][0]);
        $this->assertStringContainsString("tar xzf /backups/'".basename($backup->files['data'])."' -C /target", $run['cmd'][2]);
        $this->assertSame(['Type' => 'volume', 'Source' => 'pk95-demo-data', 'Target' => '/target'], $run['mounts'][0]);
        $this->assertTrue($this->container('pk95-demo')['running']);
    }

    public function test_old_backups_are_pruned(): void
    {
        Settings::put('backup_keep', 2);
        $app = $this->runningDemo();
        $service = app(BackupService::class);
        $first = $service->create($app);
        $service->create($app);
        $service->create($app);

        $this->assertSame(2, Backup::query()->count());
        $this->assertNull(Backup::query()->find($first->id));
        $this->assertFileDoesNotExist($first->files['data']);
    }

    public function test_pick_respects_the_policy(): void
    {
        $tags = ['1.0.0', '1.0.3', 'v1.0.4', '1.1.0', '2.0.0', 'latest'];
        $this->assertSame('v1.0.4', UpdateService::pick($tags, '1.0.0', 'patch'));
        $this->assertSame('1.1.0', UpdateService::pick($tags, '1.0.0', 'minor'));
        $this->assertSame('2.0.0', UpdateService::pick($tags, '1.0.0', 'all'));
        $this->assertSame('2.0.0', UpdateService::pick($tags, '1.0.0', 'notify'));
        $this->assertNull(UpdateService::pick($tags, '2.0.0', 'all'));
        $this->assertSame(['registry' => 'ghcr.io', 'repo' => 'philippkuhlmann/pk95-uptime'], Registry::parse('ghcr.io/philippkuhlmann/pk95-uptime'));
        $this->assertSame(['registry' => 'registry-1.docker.io', 'repo' => 'library/alpine'], Registry::parse('alpine'));
    }

    public function test_check_finds_version_tags_and_reports_once(): void
    {
        $app = $this->runningDemo();
        $app->update(['update_policy' => 'minor']);

        $this->assertSame('1.1.0', app(UpdateService::class)->check($app));
        app(UpdateService::class)->check($app);

        $this->assertSame('1.1.0', $app->refresh()->available_version);
        $this->assertSame(1, AppEvent::query()->where('type', 'core.update.available')->count());
    }

    public function test_private_images_get_a_clear_message(): void
    {
        $app = $this->runningDemo();
        $app->update(['tag' => 'latest']);
        Http::swap(new Factory(app('events')));
        Http::fake(['*' => Http::response('', 403)]);

        $this->expectExceptionMessage('Kein Zugriff auf das Image example/demo (HTTP 403): Es ist nicht öffentlich');
        app(UpdateService::class)->check($app);
    }

    public function test_check_compares_digests_for_moving_tags(): void
    {
        $app = $this->runningDemo();
        $app->update(['tag' => 'latest']);
        $this->docker['images']['example/demo:latest'] = ['RepoDigests' => ['example/demo@sha256:remote']];
        $this->assertNull(app(UpdateService::class)->check($app));

        $this->docker['images']['example/demo:latest'] = ['RepoDigests' => ['example/demo@sha256:local']];
        $this->assertSame('latest', app(UpdateService::class)->check($app));
    }

    public function test_update_backs_up_and_switches_the_version(): void
    {
        $this->versionFromImage = true;
        $app = $this->runningDemo();
        $this->assertSame('1.0.0', $app->version);
        $app->update(['update_policy' => 'all', 'available_version' => '2.0.0']);

        $this->assertTrue(app(UpdateService::class)->update($app));

        $app->refresh();
        $this->assertSame('2.0.0', $app->tag);
        $this->assertSame('2.0.0', $app->version);
        $this->assertNull($app->available_version);
        $this->assertSame(InstalledApp::RUNNING, $app->status);
        $this->assertSame('update', Backup::query()->sole()->reason);
        $this->assertSame('example/demo:2.0.0', $this->container('pk95-demo')['spec']['Image']);
        $done = AppEvent::query()->where('type', 'core.update.done')->sole();
        $this->assertSame('1.0.0 → 2.0.0', $done->body);
    }

    public function test_failed_update_rolls_back_to_the_previous_image_and_data(): void
    {
        $this->versionFromImage = true;
        $app = $this->runningDemo();
        $previousImage = $app->image_id;
        $this->docker['brokenImage'] = 'example/demo:2.0.0';

        $this->assertFalse(app(UpdateService::class)->update($app, '2.0.0'));

        $app->refresh();
        $this->assertSame(InstalledApp::RUNNING, $app->status);
        $this->assertSame('1.0.0', $app->tag);
        // the old tag still points to the old image, so it runs under its readable name
        $this->assertSame('example/demo:1.0.0', $this->container('pk95-demo')['spec']['Image'], 'old image runs again');
        $this->assertSame($previousImage, $app->image_id);
        $this->assertSame('sh', end($this->docker['helperRuns'])['cmd'][0], 'backup was restored');
        $failed = AppEvent::query()->where('type', 'core.update.failed')->sole();
        $this->assertStringContainsString('vorige Version läuft wieder', $failed->title);
    }

    public function test_rollback_uses_the_image_id_when_the_tag_moved_on(): void
    {
        $app = $this->runningDemo();
        $app->update(['tag' => 'latest']);
        $previousImage = $app->image_id;
        // "latest" now points to a different (broken) image than the one that ran before
        $this->docker['images']['example/demo:latest'] = ['Id' => 'sha256:newlatest'];
        $this->docker['brokenImage'] = 'example/demo:latest';

        $this->assertFalse(app(UpdateService::class)->update($app));

        $this->assertSame($previousImage, $this->container('pk95-demo')['spec']['Image']);
        $this->assertSame(InstalledApp::RUNNING, $app->refresh()->status);
    }

    public function test_maintenance_window_plans_updates_and_backups_once_a_day(): void
    {
        $app = $this->runningDemo();
        $app->update(['update_policy' => 'all', 'available_version' => '1.1.0']);
        Settings::put('maintenance_time', now('Europe/Berlin')->format('H:i'));

        $this->artisan('pk95:maintenance')->expectsOutputToContain('demo: Update eingeplant')->assertSuccessful();
        $this->assertSame('1.1.0', $app->refresh()->tag);

        $this->artisan('pk95:maintenance')->doesntExpectOutputToContain('demo:')->assertSuccessful();

        $app->update(['update_policy' => 'notify', 'available_version' => '2.0.0']);
        $this->artisan('pk95:maintenance --force')->expectsOutputToContain('demo: Sicherung eingeplant')->assertSuccessful();
        $this->assertSame('1.1.0', $app->refresh()->tag);
    }

    public function test_app_page_offers_backups_and_updates(): void
    {
        $this->signIn();
        $app = $this->runningDemo();
        $app->update(['available_version' => '1.1.0']);

        Livewire::test(Show::class, ['installedApp' => $app])
            ->assertSee('Update verfügbar')
            ->assertSee('1.1.0')
            ->set('updatePolicy', 'patch')
            ->set('autoBackup', false)
            ->call('backupNow')
            ->assertDispatched('notice', kind: 'success')
            ->assertSee('Manuell');

        $app->refresh();
        $this->assertSame('patch', $app->update_policy);
        $this->assertFalse($app->auto_backup);
        $this->assertNull($app->step);
        $this->assertSame('done', Backup::query()->sole()->status);
    }

    public function test_operations_on_one_app_do_not_overlap(): void
    {
        $app = $this->runningDemo();
        $lock = cache()->lock('pk95-app-op-demo', 60);
        $lock->get();

        AppOperation::backup('demo');

        $this->assertSame(0, Backup::query()->count());
        $this->assertTrue($app->logs()->where('message', 'like', '%läuft gerade schon ein Vorgang%')->exists());
        $lock->release();
    }
}
