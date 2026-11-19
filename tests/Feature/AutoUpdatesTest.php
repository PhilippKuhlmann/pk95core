<?php

namespace Tests\Feature;

use App\Livewire\Settings\Updates;
use App\Models\AppEvent;
use App\Models\InstalledApp;
use App\Services\Updates\AutoUpdates;
use App\Services\Updates\CoreUpdater;
use App\Services\Updates\UpdateAll;
use App\Services\Updates\UpdateService;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery\MockInterface;

/**
 * Automatic updates in the maintenance window: the apps per their policy, then Core per the
 * Core policy (never a new major version), Core only once no app operation waits or runs;
 * and the page Updates. App updates and Core's self update are mocked (own tests).
 */
class AutoUpdatesTest extends FeatureTestCase
{
    /** @var list<string> what ran, in order ("core" for Core's self update) */
    private array $ran = [];

    /** @var array<string, mixed>|null the newer Core build (Settings core_update) */
    private ?array $coreAvailable = ['digest' => 'sha256:new', 'version' => '1.14.1', 'revision' => 'def5678', 'created' => null];

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
        config(['pk95.version' => '1.14.0']);

        $this->mock(UpdateService::class, function (MockInterface $m) {
            $m->shouldReceive('update')->andReturnUsing(function (InstalledApp $app) {
                $this->ran[] = $app->app_id;
                AppEvent::record($app->app_id, 'core.update.done', 'info', 'x');
                $app->update(['available_version' => null]);

                return true;
            });
        });
        $this->mock(CoreUpdater::class, function (MockInterface $m) {
            $m->shouldReceive('finish')->andReturnNull();
            $m->shouldReceive('container')->andReturnNull();
            $m->shouldReceive('status')->andReturnUsing(fn () => [
                'version' => '1.14.0', 'docker' => true, 'image' => 'ghcr.io/philippkuhlmann/pk95core:latest', 'revision' => 'abc1234',
                'built' => now(), 'compose' => ['project' => 'pk95'], 'checked_at' => now(), 'last' => null,
                'available' => $this->coreAvailable, 'running' => Settings::get('core_self_update'),
            ]);
            $m->shouldReceive('start')->andReturnUsing(function () {
                $this->ran[] = 'core';
                Settings::put('core_self_update', ['started_at' => now()->toIso8601String()]);
            });
        });
    }

    private function app(string $id, ?string $available = '1.0.1', string $policy = 'patch'): InstalledApp
    {
        return InstalledApp::factory()->create(['app_id' => $id, 'tag' => '1.0.0', 'version' => '1.0.0', 'available_version' => $available, 'update_policy' => $policy,
            'manifest' => ['id' => $id, 'name' => 'App '.$id, 'version' => '1.0.0', 'protocol' => 1, 'menu' => [], 'capabilities' => [], 'events' => []]]);
    }

    public function test_core_policy_matrix_never_crosses_a_major_version(): void
    {
        $build = fn (?string $v) => ['digest' => 'sha256:x', 'version' => $v, 'revision' => null, 'created' => null];
        $expected = [
            // available => [notify, patch, minor, all]
            '1.14.1' => ['nur melden', null, null, null],
            '1.15.0' => ['nur melden', 'außerhalb der Update-Regel', null, null],
            '2.0.0' => ['nur melden', 'neue Hauptversion – nur von Hand', 'neue Hauptversion – nur von Hand', 'neue Hauptversion – nur von Hand'],
            '1.13.9' => ['nur melden', 'außerhalb der Update-Regel', 'außerhalb der Update-Regel', 'außerhalb der Update-Regel'],
        ];
        foreach ($expected as $version => $reasons) {
            foreach (['notify', 'patch', 'minor', 'all'] as $i => $policy) {
                $this->assertSame($reasons[$i], AutoUpdates::coreBlocker('1.14.0', $build($version), $policy), "$version with $policy");
            }
        }
        $this->assertSame('ohne Versionsnummer – nur von Hand', AutoUpdates::coreBlocker('1.14.0', $build(null), 'all'), 'a build without a release');
        $this->assertSame('kein Update', AutoUpdates::coreBlocker('1.14.0', null, 'all'));
        $this->assertSame('notify', AutoUpdates::corePolicy(), 'default');
    }

    public function test_maintenance_updates_the_apps_first_and_core_last(): void
    {
        Settings::put(AutoUpdates::CORE_POLICY, 'minor');
        $this->app('dns');
        $this->app('uptime');
        $this->app('quiet', '1.0.1', 'notify');
        $this->app('later', '1.1.0', 'patch');

        $this->artisan('pk95:maintenance --force')
            ->expectsOutputToContain('uptime: Update eingeplant')->expectsOutputToContain('dns: Update eingeplant')
            ->expectsOutputToContain('core: Update eingeplant (nach den Apps)')
            ->expectsOutputToContain('quiet: kein automatisches Update (nur melden)')
            ->expectsOutputToContain('later: kein automatisches Update (außerhalb der Update-Regel)')
            ->assertSuccessful();

        // sync queue: the app jobs ran inside start(); Core follows on the next tick (pk95:sync)
        $this->assertSame(['uptime', 'dns'], $this->ran);
        $this->assertTrue(app(UpdateAll::class)->running()['automatic']);
        $this->assertSame('core', app(UpdateAll::class)->tick());
        $this->assertSame(['uptime', 'dns', 'core'], $this->ran);

        Settings::put('core_self_update', null);
        Settings::put('core_self_update_result', ['result' => 'done', 'message' => '', 'at' => now()->toIso8601String()]);
        $this->assertSame('done', app(UpdateAll::class)->tick());
        $this->assertSame('Automatische Updates abgeschlossen: 3 aktualisiert', AppEvent::query()->where('type', 'core.update.all')->sole()->title);
    }

    public function test_core_waits_while_any_app_operation_waits_or_runs(): void
    {
        Settings::put(AutoUpdates::CORE_POLICY, 'patch');
        $this->app('uptime', null);
        app(UpdateAll::class)->start(collect(), true, automatic: true);

        // a queued job (the scheduled backup of another app, say)
        $job = DB::table('jobs')->insertGetId(['queue' => 'default', 'payload' => '{"displayName":"App\\\\Jobs\\\\AppOperation"}', 'attempts' => 0,
            'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        $this->assertNull(app(UpdateAll::class)->tick());
        DB::table('jobs')->where('id', $job)->delete();

        // an app operation running (any app, not only the run's)
        InstalledApp::query()->where('app_id', 'uptime')->update(['step' => 'Sicherung']);
        $this->assertNull(app(UpdateAll::class)->tick());
        $this->assertSame([], $this->ran);

        InstalledApp::query()->where('app_id', 'uptime')->update(['step' => null]);
        $this->assertSame('core', app(UpdateAll::class)->tick());
        $this->assertSame(['core'], $this->ran);
    }

    public function test_core_is_checked_again_before_it_starts_and_a_new_major_version_never_starts(): void
    {
        // "alle": a new major version stays manual
        Settings::put(AutoUpdates::CORE_POLICY, 'all');
        $this->coreAvailable['version'] = '2.0.0';
        $this->artisan('pk95:maintenance --force')
            ->expectsOutputToContain('core: kein automatisches Update (neue Hauptversion – nur von Hand)')->assertSuccessful();
        $this->assertNull(app(UpdateAll::class)->running());
        $this->assertSame([], $this->ran);

        // the policy changes between the window and Core's turn: Core is skipped, the run ends
        $this->coreAvailable['version'] = '1.14.1';
        app(UpdateAll::class)->start(collect(), true, automatic: true);
        Settings::put(AutoUpdates::CORE_POLICY, 'notify');
        $this->assertSame('done', app(UpdateAll::class)->tick());
        $this->assertSame([], $this->ran);
        $event = AppEvent::query()->where('type', 'core.update.all')->sole();
        $this->assertStringContainsString('PK95 Core nicht aktualisiert: nur melden.', $event->body);
    }

    public function test_updates_page_shows_the_plan_and_changes_policies(): void
    {
        Settings::put('maintenance_time', '03:00');
        Settings::put(AutoUpdates::CORE_POLICY, 'patch');
        $this->travelTo(now('Europe/Berlin')->setTime(1, 0)->utc());
        $this->app('uptime', '1.0.1', 'patch');
        // DemoModule fits 1.0 <= version < 2.0: 2.0.0 waits for a Core update
        $this->app('demo', '2.0.0', 'all');
        $this->app('quiet', '1.0.1', 'notify');

        $page = Livewire::test(Updates::class)
            ->assertSee('Nächstes Wartungsfenster: heute 03:00 Uhr')
            ->assertSeeInOrder(['Geplant:', 'App uptime', 'Version 1.0.0 → Version 1.0.1', 'PK95 Core', 'Version 1.14.0 → Version 1.14.1, zuletzt'])
            ->assertSeeInOrder(['Wartet:', 'App demo', 'Version 1.0.0 → Version 2.0.0', 'wartet auf Core-Update'])
            ->assertSee('Vor jedem Update sichert Core die App.')
            ->assertSee('Jetzt alle installieren (4)')
            ->assertSet('policyOf', ['demo' => 'all', 'quiet' => 'notify', 'uptime' => 'patch'])
            ->assertSet('corePolicy', 'patch');

        // policies right in the table, for all apps at once, for Core and for new apps
        $page->set('policyOf.quiet', 'minor')->assertDispatched('notice');
        $this->assertSame('minor', InstalledApp::query()->where('app_id', 'quiet')->value('update_policy'));
        $page->set('policyOf.quiet', 'bogus');
        $this->assertSame('minor', InstalledApp::query()->where('app_id', 'quiet')->value('update_policy'));

        $page->call('setAll')->assertHasErrors('allPolicy')
            ->set('allPolicy', 'notify')->call('setAll')->assertHasNoErrors()
            ->assertSet('policyOf', ['demo' => 'notify', 'quiet' => 'notify', 'uptime' => 'notify'])
            ->assertDontSee('Wartet:');
        $this->assertSame(['notify'], InstalledApp::query()->distinct()->pluck('update_policy')->all());

        $page->set('corePolicy', 'minor')->set('appPolicy', 'patch');
        $this->assertSame(['minor', 'patch'], [AutoUpdates::corePolicy(), AutoUpdates::appPolicy()]);

        // after today's window: tomorrow
        Settings::put('maintenance_last_run', now('Europe/Berlin')->toDateString());
        Livewire::test(Updates::class)->assertSee('Nächstes Wartungsfenster: morgen 03:00 Uhr');

        // "Jetzt alle installieren" starts the run and follows it on the Apps page
        Livewire::test(Updates::class)->call('updateAll')->assertRedirect(route('apps.index'));
        $this->assertSame(['uptime', 'demo', 'quiet'], array_slice($this->ran, 0, 3), 'update order');
    }
}
