<?php

namespace Tests\Feature;

use App\Jobs\AppOperation;
use App\Livewire\Apps\Index;
use App\Models\AppEvent;
use App\Models\InstalledApp;
use App\Services\Updates\CoreUpdater;
use App\Services\Updates\UpdateAll;
use App\Services\Updates\UpdateService;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;

/**
 * "Alle aktualisieren" on the Apps page: apps one after another in a fixed order, Core last
 * and only once no app job waits, one summary at the end. App updates and Core's self
 * update are mocked (UpdateService and CoreUpdater have their own tests).
 */
class UpdateAllTest extends FeatureTestCase
{
    /** @var list<string> what ran, in order ("core" for Core's self update) */
    private array $ran = [];

    /** @var list<string> apps whose update fails */
    private array $failing = [];

    private bool $coreAvailable = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();

        $this->mock(UpdateService::class, function (MockInterface $m) {
            $m->shouldReceive('update')->andReturnUsing(function (InstalledApp $app) {
                $this->ran[] = $app->app_id;
                $ok = ! in_array($app->app_id, $this->failing, true);
                AppEvent::record($app->app_id, $ok ? 'core.update.done' : 'core.update.failed', $ok ? 'info' : 'critical', 'x');
                if ($ok) {
                    $app->update(['available_version' => null]);
                }

                return $ok;
            });
        });
        $this->mock(CoreUpdater::class, function (MockInterface $m) {
            $m->shouldReceive('finish')->andReturnNull();
            $m->shouldReceive('container')->andReturnNull();
            $m->shouldReceive('status')->andReturnUsing(fn () => [
                'version' => '1.0.0', 'docker' => true, 'image' => 'ghcr.io/philippkuhlmann/pk95core:latest', 'revision' => 'abc1234',
                'built' => now(), 'compose' => ['project' => 'pk95'], 'checked_at' => now(), 'last' => null,
                'available' => $this->coreAvailable ? ['digest' => 'sha256:new', 'revision' => 'def5678', 'created' => null] : null,
                'running' => Settings::get('core_self_update'),
            ]);
            $m->shouldReceive('start')->andReturnUsing(function () {
                $this->ran[] = 'core';
                Settings::put('core_self_update', ['started_at' => now()->toIso8601String()]);
            });
        });
    }

    private function app(string $id, ?string $available = '1.1.0', string $status = InstalledApp::RUNNING): InstalledApp
    {
        return InstalledApp::factory()->create(['app_id' => $id, 'status' => $status, 'version' => '1.0.0', 'available_version' => $available,
            'manifest' => ['id' => $id, 'name' => 'App '.$id, 'version' => '1.0.0', 'protocol' => 1, 'menu' => [], 'capabilities' => [], 'events' => []]]);
    }

    public function test_apps_run_in_order_core_last_and_one_summary_at_the_end(): void
    {
        foreach (['dns', 'zeta', 'uptime', 'proxy', 'proxapps'] as $id) {
            $this->app($id);
        }
        $this->app('quiet', null);
        $this->app('broken', '2.0.0', InstalledApp::FAILED);
        $this->failing = ['proxy'];

        Livewire::test(Index::class)
            ->assertSee('Alle aktualisieren (6)')
            ->call('updateAll')
            ->assertDispatched('notice', kind: 'success', text: '6 Updates gestartet, eins nach dem anderen.')
            ->assertDispatched('core-restarting');

        // sync queue: the app jobs ran inside start(), then Core
        $this->assertSame(['proxapps', 'uptime', 'proxy', 'dns', 'zeta', 'core'], $this->ran);
        $this->assertTrue(app(UpdateAll::class)->running()['core_started']);
        $this->assertNull(app(UpdateAll::class)->tick(), 'waits for the new Core to record the outcome');

        // the new Core: helper done, outcome recorded
        Settings::put('core_self_update', null);
        Settings::put('core_self_update_result', ['result' => 'done', 'message' => '', 'at' => now()->toIso8601String()]);
        $this->assertSame('done', app(UpdateAll::class)->tick());

        $event = AppEvent::query()->where('type', 'core.update.all')->sole();
        $this->assertSame('warning', $event->severity);
        $this->assertSame('Alle Updates abgeschlossen: 5 aktualisiert, 1 fehlgeschlagen', $event->title);
        $this->assertStringContainsString('Aktualisiert: App proxapps, App uptime, App dns, App zeta, PK95 Core', $event->body);
        $this->assertStringContainsString('Fehlgeschlagen: App proxy.', $event->body);
        $this->assertNull(app(UpdateAll::class)->running());
        $this->assertNull(app(UpdateAll::class)->tick());

        $this->coreAvailable = false;
        Livewire::test(Index::class)->assertSee('Alle Updates abgeschlossen: 5 aktualisiert, 1 fehlgeschlagen')->assertSee('Alle aktualisieren (1)');
    }

    public function test_core_waits_while_an_app_job_is_queued_or_running(): void
    {
        Queue::fake();
        $this->app('uptime');
        app(UpdateAll::class)->start();
        Queue::assertPushed(AppOperation::class, fn ($job) => $job->appId === 'uptime' && $job->operation === 'update');

        $this->assertNull(app(UpdateAll::class)->tick(), 'uptime still has its step');
        Livewire::test(Index::class)->assertSee('Alle Updates laufen')->assertSee('1. App uptime')->assertSee('2. PK95 Core')
            ->assertSee('wartet');

        InstalledApp::query()->where('app_id', 'uptime')->update(['step' => null]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{"displayName":"App\\\\Jobs\\\\AppOperation"}', 'attempts' => 0,
            'available_at' => time(), 'created_at' => time()]);
        $this->assertNull(app(UpdateAll::class)->tick(), 'another app job waits in the queue');
        $this->assertSame([], $this->ran);

        DB::table('jobs')->delete();
        $this->assertSame('core', app(UpdateAll::class)->tick());
        $this->assertSame(['core'], $this->ran);
    }

    public function test_a_run_whose_queue_never_finishes_is_closed_after_hours(): void
    {
        Queue::fake();
        $this->app('uptime');
        app(UpdateAll::class)->start();
        $this->travel(4)->hours();

        $this->assertSame('done', app(UpdateAll::class)->tick());
        $this->assertSame([], $this->ran, 'Core is not started on a stale run');
        $event = AppEvent::query()->where('type', 'core.update.all')->sole();
        $this->assertSame('Alle Updates abgeschlossen: 2 nicht gelaufen', $event->title);
    }

    public function test_core_start_failure_ends_the_run_with_a_message(): void
    {
        $this->mock(CoreUpdater::class, function (MockInterface $m) {
            $m->shouldReceive('finish')->andReturnNull();
            $m->shouldReceive('status')->andReturn(['available' => ['digest' => 'x'], 'compose' => ['project' => 'pk95'], 'running' => null]);
            $m->shouldReceive('start')->andThrow(new \RuntimeException('Docker antwortet nicht'));
        });
        app(UpdateAll::class)->start();

        $this->assertSame('done', app(UpdateAll::class)->tick());
        $this->assertStringContainsString('Fehlgeschlagen: PK95 Core (Docker antwortet nicht)', AppEvent::query()->where('type', 'core.update.all')->sole()->body);
    }

    public function test_card_shows_the_update_and_updates_one_app(): void
    {
        Queue::fake();
        $this->coreAvailable = false;
        $app = $this->app('demo', '1.3.0');

        Livewire::test(Index::class)
            ->assertSee('Update verfügbar')->assertSee('installiert Version 1.0.0 → neu Version 1.3.0')->assertSee('Alle aktualisieren (1)')
            ->call('updateApp', 'demo')
            ->assertDispatched('notice', kind: 'success', text: 'Update von App demo gestartet – vorher wird gesichert.')
            ->assertSee('Update eingeplant')->assertDontSee('installiert Version 1.0.0');

        Queue::assertPushed(AppOperation::class, 1);
        $this->assertSame('Update eingeplant', $app->fresh()->step);
        $this->assertSame(['from' => '„latest“', 'to' => 'neuer Build von „latest“'],
            UpdateService::change(new InstalledApp(['tag' => 'latest', 'available_version' => 'latest'])));
    }

    public function test_check_all_and_nothing_to_update(): void
    {
        $this->coreAvailable = false;
        $this->app('demo', null);
        app(UpdateService::class)->shouldReceive('check')->andReturnUsing(function (InstalledApp $app) {
            $app->update(['available_version' => '1.4.0']);

            return '1.4.0';
        });

        Livewire::test(Index::class)
            ->call('updateAll')->assertDispatched('notice', kind: 'error', text: 'Es gibt keine Updates. Such zuerst nach Updates.')
            ->call('checkAll')->assertDispatched('notice', kind: 'success', text: '1 Update gefunden.')
            ->assertSee('Alle aktualisieren (1)');
    }
}
