<?php

namespace Tests\Feature;

use App\Livewire\Apps\Index;
use App\Models\AppEvent;
use App\Services\Docker\DockerClient;
use App\Services\Updates\CoreUpdater;
use App\Services\Updates\Registry;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mockery\MockInterface;

/**
 * Core on the Apps page: the running build, newer builds in the registry and the self update
 * through the helper container. Docker and the registry are mocked.
 */
class CoreUpdateTest extends FeatureTestCase
{
    /** @var array<string, mixed> */
    private array $core = [
        'Name' => '/pk95core',
        'Image' => 'sha256:old',
        'Config' => [
            'Image' => 'ghcr.io/philippkuhlmann/pk95core:latest',
            'Labels' => [
                'com.docker.compose.project' => 'pk95',
                'com.docker.compose.project.working_dir' => '/opt/pk95',
                'com.docker.compose.project.config_files' => '/opt/pk95/docker-compose.yml',
                'com.docker.compose.service' => 'pk95core',
            ],
        ],
    ];

    /** @var array<string, mixed>|null inspect data of the helper container */
    private ?array $helper = null;

    /** @var list<string> */
    private array $helperLog = [];

    /** Runs (once) while finish() reads the helper's log: a second caller arriving meanwhile. */
    private ?\Closure $whileReadingLogs = null;

    /** @var list<array<string, mixed>> */
    private array $created = [];

    private string $remoteDigest = 'sha256:new';

    /** org.opencontainers.image.version of the remote image ("latest" for main builds before releases). */
    private string $remoteVersion = 'latest';

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(DockerClient::class, function (MockInterface $m) {
            $m->shouldReceive('available')->andReturn(true);
            $m->shouldReceive('inspectContainer')->andReturnUsing(fn (string $ref) => match ($ref) {
                'pk95core' => $this->core,
                CoreUpdater::HELPER => $this->helper,
                default => null,
            });
            $m->shouldReceive('inspectImage')->andReturnUsing(fn (string $ref) => match ($ref) {
                'sha256:old' => ['RepoDigests' => ['ghcr.io/philippkuhlmann/pk95core@sha256:old'],
                    'Config' => ['Labels' => ['org.opencontainers.image.revision' => 'abcdef1234567', 'org.opencontainers.image.created' => '2026-11-01T10:00:00Z']]],
                default => ['Id' => 'sha256:helper'],
            });
            $m->shouldReceive('removeContainer')->andReturnNull();
            $m->shouldReceive('createContainer')->andReturnUsing(function (?string $name, array $spec) {
                $this->created[] = ['name' => $name] + $spec;
                $this->helper = ['State' => ['Running' => true]];

                return 'helper-id';
            });
            $m->shouldReceive('startContainer')->andReturnNull();
            $m->shouldReceive('logs')->andReturnUsing(function () {
                if ($hook = $this->whileReadingLogs) {
                    $this->whileReadingLogs = null;
                    $hook();
                }

                return array_map(fn ($l) => ['stream' => 'stdout', 'line' => '2026-11-09T10:00:00.000Z '.$l], $this->helperLog);
            });
            // the Apps page lists the catalog containers as well
            $m->shouldReceive('containersWithLabel')->andReturn([]);
        });
        $this->mock(Registry::class, function (MockInterface $m) {
            $m->shouldReceive('digest')->andReturnUsing(fn () => $this->remoteDigest);
            $m->shouldReceive('labels')->andReturnUsing(fn () => ['org.opencontainers.image.revision' => '9876543210fed',
                'org.opencontainers.image.created' => '2026-11-09T08:30:00Z', 'org.opencontainers.image.version' => $this->remoteVersion]);
        });
    }

    public function test_check_finds_a_newer_build_once(): void
    {
        $found = app(CoreUpdater::class)->check();

        $this->assertSame(['digest' => 'sha256:new', 'version' => null, 'revision' => '9876543', 'created' => '2026-11-09T08:30:00Z'], $found);
        $this->assertSame($found, Settings::get('core_update'));
        app(CoreUpdater::class)->check();
        $this->assertSame(1, AppEvent::query()->where('type', 'core.update.available')->where('app_id', AppEvent::CORE)->count());

        $this->remoteDigest = 'sha256:old';
        $this->assertNull(app(CoreUpdater::class)->check());
        $this->assertNull(Settings::get('core_update'));
    }

    public function test_a_release_shows_its_version(): void
    {
        config(['pk95.version' => '1.0.0']);
        $this->remoteVersion = 'v1.0.1';
        $found = app(CoreUpdater::class)->check();

        $this->assertSame('1.0.1', $found['version']);
        $this->assertSame('Für PK95 Core gibt es Version 1.0.1', AppEvent::query()->where('type', 'core.update.available')->sole()->title);
        $this->assertStringStartsWith('Version 1.0.1 · Build 9876543 vom 09.11.2026', CoreUpdater::describe($found));
        $this->assertSame('v1.0.0', CoreUpdater::versionLabel());
        config(['pk95.version' => 'main']);
        $this->assertSame('main', CoreUpdater::versionLabel());

        $this->signIn();
        config(['pk95.version' => '1.0.0']);
        $this->get(route('apps.index'))->assertSee('installiert Version 1.0.0 → neu Version 1.0.1')->assertSee('PK95 Core v1.0.0');
    }

    public function test_start_runs_the_helper_for_the_compose_project(): void
    {
        app(CoreUpdater::class)->start();

        $spec = $this->created[0];
        $this->assertSame(CoreUpdater::HELPER, $spec['name']);
        $this->assertSame('docker:28-cli', $spec['Image']);
        $this->assertSame(['/var/run/docker.sock:/var/run/docker.sock', '/opt/pk95:/opt/pk95'], $spec['HostConfig']['Binds']);
        $this->assertSame('pk95', $spec['HostConfig']['NetworkMode']);
        $script = $spec['Cmd'][2];
        $this->assertStringContainsString("docker compose -p 'pk95' --project-directory '/opt/pk95' -f '/opt/pk95/docker-compose.yml'", $script);
        $this->assertStringContainsString("dc pull 'pk95core'", $script);
        $this->assertStringContainsString("wget -q -T 3 -O /dev/null 'http://pk95core:8080/up' 2>/dev/null", $script);
        $this->assertStringContainsString("docker tag 'sha256:old' 'ghcr.io/philippkuhlmann/pk95core:latest'", $script);
        $this->assertSame('abcdef1', Settings::get('core_self_update')['from']);

        $this->expectExceptionMessage('läuft bereits');
        app(CoreUpdater::class)->start();
    }

    public function test_start_needs_a_compose_project(): void
    {
        $this->core['Config']['Labels'] = [];

        $this->expectExceptionMessage('nicht mit docker compose gestartet');
        app(CoreUpdater::class)->start();
    }

    public function test_finish_records_a_successful_update(): void
    {
        Settings::put('core_update', ['digest' => 'sha256:new', 'revision' => '9876543', 'created' => null]);
        Settings::put('core_self_update', ['started_at' => now()->toIso8601String(), 'from' => '1111111']);
        $this->helper = ['State' => ['Running' => true]];
        $this->helperLog = ['10:00:01 Lade das neue Image'];

        $this->assertNull(app(CoreUpdater::class)->finish(), 'still running');

        $this->helper = ['State' => ['Running' => false]];
        $this->helperLog[] = '10:00:40 RESULT done';
        $this->assertSame('done', app(CoreUpdater::class)->finish());

        $event = AppEvent::query()->where('type', 'core.update.done')->sole();
        $this->assertSame('1111111 → abcdef1', $event->body);
        $this->assertNull(Settings::get('core_self_update'));
        $this->assertNull(Settings::get('core_update'));
        $this->assertSame('done', Settings::get('core_self_update_result')['result']);
        $this->assertStringContainsString('10:00:01 Lade das neue Image', Settings::get('core_self_update_result')['log']);
    }

    public function test_an_update_is_recorded_once_when_two_callers_finish_at_the_same_time(): void
    {
        // after the restart the scheduler (pk95:sync) and the reloading Apps page both call
        // finish(): the second one arrives while the first still reads the helper's log
        Settings::put('core_self_update', ['started_at' => now()->subMinutes(2)->toIso8601String(), 'from' => 'f65ec9b']);
        $this->helper = ['State' => ['Running' => false]];
        $this->helperLog = ['RESULT done'];
        $second = 'not called';
        $this->whileReadingLogs = function () use (&$second) {
            $second = app(CoreUpdater::class)->finish();
        };

        $this->assertSame('done', app(CoreUpdater::class)->finish());
        $this->assertNull($second, 'the second caller does not finish the same update');
        $this->assertSame(1, AppEvent::query()->where('type', 'core.update.done')->count());

        // and later calls find nothing to finish
        $this->assertNull(app(CoreUpdater::class)->finish());
        $this->assertSame(1, AppEvent::query()->where('type', 'core.update.done')->count());
    }

    public function test_finish_waits_while_another_process_holds_the_lock(): void
    {
        Settings::put('core_self_update', ['started_at' => now()->subMinutes(2)->toIso8601String(), 'from' => 'f65ec9b']);
        $this->helper = ['State' => ['Running' => false]];
        $this->helperLog = ['RESULT done'];

        $other = Cache::lock(CoreUpdater::FINISH_LOCK, 120);
        $this->assertTrue($other->get());
        $this->assertNull(app(CoreUpdater::class)->finish());
        $this->assertSame(0, AppEvent::query()->count());
        $this->assertNotNull(Settings::get('core_self_update'), 'left for the lock holder');

        $other->release();
        $this->assertSame('done', app(CoreUpdater::class)->finish());
        $this->assertSame(1, AppEvent::query()->where('type', 'core.update.done')->count());
    }

    public function test_finish_reports_a_rollback(): void
    {
        Settings::put('core_update', ['digest' => 'sha256:new', 'revision' => '9876543', 'created' => null]);
        Settings::put('core_self_update', ['started_at' => now()->toIso8601String(), 'from' => 'abcdef1']);
        $this->helper = ['State' => ['Running' => false]];
        $this->helperLog = ['RESULT rolled_back Die neue Version startete nicht, die vorige läuft wieder.'];

        $this->assertSame('rolled_back', app(CoreUpdater::class)->finish());

        $event = AppEvent::query()->where('type', 'core.update.failed')->sole();
        $this->assertSame('critical', $event->severity);
        $this->assertNotNull(Settings::get('core_update'), 'the newer build is still on offer');
    }

    public function test_split_ref(): void
    {
        $this->assertSame(['ghcr.io/o/pk95core', 'latest'], CoreUpdater::splitRef('ghcr.io/o/pk95core:latest'));
        $this->assertSame(['localhost:5000/pk95core', 'latest'], CoreUpdater::splitRef('localhost:5000/pk95core'));
        $this->assertSame(['docker', '28-cli'], CoreUpdater::splitRef('docker:28-cli'));
        $this->assertSame(['ghcr.io/o/x', 'latest'], CoreUpdater::splitRef('ghcr.io/o/x@sha256:abc'));
    }

    public function test_apps_page_shows_core_and_updates_it(): void
    {
        $this->signIn();
        Settings::put('core_update', ['digest' => 'sha256:new', 'revision' => '9876543', 'created' => '2026-11-09T08:30:00Z']);

        $this->get(route('apps.index'))->assertOk()
            ->assertSee('PK95 Core')
            ->assertSee('abcdef1')
            ->assertSee('Update verfügbar')
            ->assertSee('Build 9876543')
            // the image without ":latest", the version in its own chip (like the app cards)
            ->assertSee('>ghcr.io/philippkuhlmann/pk95core</span>', false)
            ->assertDontSee('>ghcr.io/philippkuhlmann/pk95core:latest</span>', false);

        Livewire::test(Index::class)
            ->call('updateCore')
            ->assertDispatched('core-restarting')
            ->assertSee('Wird aktualisiert');
        $this->assertCount(1, $this->created);
    }
}
