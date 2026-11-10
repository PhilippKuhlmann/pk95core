<?php

namespace Tests\Feature;

use App\Jobs\AppOperation;
use App\Livewire\Apps\Index;
use App\Models\AppEvent;
use App\Models\InstalledApp;
use App\Services\Apps\AppManager;
use App\Services\Apps\AppRegistry;
use App\Services\Updates\Registry;
use App\Services\Updates\UpdateService;
use App\Support\Settings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;

/**
 * Real versions instead of "latest": a moving tag is resolved to the released version tag
 * (version label of the image + the tag exists) on installation, check and update; builds
 * without a release are shown by commit; module min/max versions give hints on the card.
 */
class VersionUpdateTest extends FeatureTestCase
{
    /** @var list<string> */
    private array $tags = ['1.0.0', '1.3.0', '1.4.0', '2.0.0', 'latest', 'main', 'sha-abc'];

    /** @var array<string, string> labels of every remote tag */
    private array $labels = ['org.opencontainers.image.version' => '1.4.0', 'org.opencontainers.image.revision' => 'abcdef123456',
        'org.opencontainers.image.created' => '2026-11-10T08:55:00Z'];

    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = sys_get_temp_dir().'/pk95-data-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dataDir);
        config(['pk95.data_dir' => $this->dataDir]);
        $this->partialMock(Registry::class, function (MockInterface $m) {
            $m->shouldReceive('tags')->andReturnUsing(fn () => $this->tags);
            $m->shouldReceive('labels')->andReturnUsing(fn () => $this->labels);
            $m->shouldReceive('digest')->andReturn('sha256:remote');
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dataDir);
        parent::tearDown();
    }

    /** Demo app installed on "latest" with version 1.0.0, the local image is older than the registry's. */
    private function demoOnLatest(): InstalledApp
    {
        $this->versionFromImage = true;
        $app = app(AppManager::class)->activate('demo')->refresh();
        $app->update(['tag' => 'latest', 'update_policy' => 'notify']);
        $this->docker['images']['example/demo:latest'] = ['RepoDigests' => ['example/demo@sha256:local']];

        return $app;
    }

    public function test_release_is_the_version_label_when_the_registry_has_that_tag(): void
    {
        $registry = app(Registry::class);
        $this->assertSame('1.4.0', $registry->release('example/demo', 'latest'));

        $this->labels['org.opencontainers.image.version'] = 'v1.3.0';
        $this->assertSame('1.3.0', $registry->release('example/demo', 'latest'));
        $this->tags = ['v1.3.0', 'latest'];
        $this->assertSame('v1.3.0', $registry->release('example/demo', 'latest'));

        $this->labels['org.opencontainers.image.version'] = 'main';
        $this->assertNull($registry->release('example/demo', 'latest'), 'main build');
        $this->labels['org.opencontainers.image.version'] = '1.9.0';
        $this->assertNull($registry->release('example/demo', 'latest'), 'version without a tag');
    }

    public function test_a_new_installation_gets_the_release_behind_latest(): void
    {
        $catalog = $this->dataDir.'/catalog';
        File::ensureDirectoryExists($catalog);
        File::put($catalog.'/demo.yaml', str_replace('tag: 1.0.0', 'tag: latest', File::get(base_path('tests/Fixtures/catalog/demo.yaml'))));
        config(['pk95.catalog_dir' => $catalog]);

        $app = app(AppManager::class)->activate('demo')->refresh();
        $this->assertSame('1.4.0', $app->tag);
        $this->assertSame('example/demo:1.4.0', $this->container('pk95-demo')['spec']['Image']);

        // re-activation keeps what is installed
        $app->update(['tag' => 'latest']);
        $this->assertSame('latest', app(AppManager::class)->activate('demo')->refresh()->tag);

        // no release yet (main builds only): stays on latest
        $app->delete();
        $this->labels['org.opencontainers.image.version'] = 'latest';
        $this->assertSame('latest', app(AppManager::class)->activate('demo')->refresh()->tag);
    }

    public function test_latest_offers_the_released_version_and_the_update_pins_it(): void
    {
        $app = $this->demoOnLatest();

        $this->assertSame('1.4.0', app(UpdateService::class)->check($app));
        $this->assertNull($app->refresh()->available_build);
        $this->assertSame('Für Demo-App gibt es Version 1.4.0', AppEvent::query()->where('type', 'core.update.available')->sole()->title);
        $this->assertSame(['from' => 'Version 1.0.0', 'to' => 'Version 1.4.0'], UpdateService::change($app));

        // the policy counts again
        $app->update(['update_policy' => 'patch']);
        $this->assertNull(app(UpdateService::class)->check($app));
        $app->update(['update_policy' => 'minor']);
        $this->assertSame('1.4.0', app(UpdateService::class)->check($app));

        $this->assertTrue(app(UpdateService::class)->update($app->refresh()));
        $app->refresh();
        $this->assertSame('1.4.0', $app->tag, 'off the moving tag');
        $this->assertSame('1.4.0', $app->version);
        $this->assertSame('example/demo:1.4.0', $this->container('pk95-demo')['spec']['Image']);
        $this->assertSame('1.0.0 → 1.4.0', AppEvent::query()->where('type', 'core.update.done')->sole()->body);

        // from now on version tags: 2.0.0 is the next one
        $app->update(['update_policy' => 'all']);
        $this->assertSame('2.0.0', app(UpdateService::class)->check($app));
    }

    public function test_same_or_older_release_on_latest(): void
    {
        $app = $this->demoOnLatest();
        $app->update(['version' => '1.4.0']);
        $this->assertSame('1.4.0', app(UpdateService::class)->check($app), 'same version, other build: move to the version tag');

        $app->update(['version' => '1.6.0']);
        $this->assertNull(app(UpdateService::class)->check($app), 'never down');
    }

    public function test_a_build_without_release_is_shown_by_commit_and_resolved_when_updating(): void
    {
        $app = $this->demoOnLatest();
        $this->labels['org.opencontainers.image.version'] = 'latest';

        $this->assertSame('latest', app(UpdateService::class)->check($app));
        $app->refresh();
        $this->assertSame(['revision' => 'abcdef1', 'created' => '2026-11-10T08:55:00Z'], $app->available_build);
        $this->assertSame('Build abcdef1 vom 10.11.2026', UpdateService::change($app)['to']);
        $this->assertSame('Für Demo-App gibt es Build abcdef1 vom 10.11.2026', AppEvent::query()->where('type', 'core.update.available')->sole()->title);

        $this->signIn();
        Livewire::test(Index::class)->assertSee('installiert Version 1.0.0 → neu Build abcdef1 vom 10.11.2026');

        // meanwhile a release was published: the update installs it
        $this->labels['org.opencontainers.image.version'] = '1.4.0';
        $this->assertTrue(app(UpdateService::class)->update($app));
        $this->assertSame('1.4.0', $app->refresh()->tag);
        $this->assertNull($app->available_build);
    }

    public function test_hints_when_the_module_needs_another_version(): void
    {
        // DemoModule: 1.0 <= version < 2.0
        $app = InstalledApp::factory()->create(['version' => '0.9.0', 'available_version' => '1.4.0']);
        $hint = fn () => app(AppRegistry::class)->updateHint($app->refresh());

        $this->assertSame('info', $hint()['tone']);
        $this->assertStringContainsString('Das Update auf 1.4.0 behebt das.', $hint()['text']);

        $app->update(['available_version' => '0.9.5']);
        $this->assertStringContainsString('Auch 0.9.5 ist älter', $hint()['text']);

        $app->update(['available_version' => null]);
        $this->assertStringContainsString('Eine passende Version ist noch nicht veröffentlicht.', $hint()['text']);

        $app->update(['version' => '1.2.0', 'available_version' => '2.0.0']);
        $this->assertSame('warn', $hint()['tone']);
        $this->assertStringContainsString('Version 2.0.0 braucht ein neueres PK95 Core', $hint()['text']);

        $app->update(['available_version' => '1.3.0']);
        $this->assertNull($hint());

        $app->update(['available_version' => 'latest', 'version' => '0.9.0']);
        $this->assertStringContainsString('Ob dieser Build reicht', $hint()['text']);

        $this->signIn();
        Livewire::test(Index::class)->assertSee('Ob dieser Build reicht, zeigt sich nach dem Aktualisieren.');
    }

    public function test_maintenance_installs_only_versions_the_module_supports(): void
    {
        Queue::fake();
        $app = InstalledApp::factory()->create(['version' => '1.2.0', 'available_version' => '2.0.0', 'update_policy' => 'all']);

        $this->artisan('pk95:maintenance', ['--force' => true])->assertSuccessful();
        Queue::assertNotPushed(AppOperation::class, fn ($job) => $job->operation === 'update');

        $app->update(['available_version' => '1.4.0']);
        Settings::put('maintenance_last_run', '');
        $this->artisan('pk95:maintenance', ['--force' => true])->assertSuccessful();
        Queue::assertPushed(AppOperation::class, fn ($job) => $job->operation === 'update');
    }
}
