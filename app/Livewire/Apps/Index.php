<?php

namespace App\Livewire\Apps;

use App\Jobs\AppOperation;
use App\Models\InstalledApp;
use App\Services\Apps\AppManager;
use App\Services\Apps\AppRegistry;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\CatalogException;
use App\Services\Docker\DockerClient;
use App\Services\Updates\CoreUpdater;
use App\Services\Updates\UpdateAll;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * The catalog with the state of every app: activate, start, stop, restart, remove, update.
 * On top Core itself: its build, newer builds in the registry and the self update. The
 * header checks all apps for updates and installs them all (UpdateAll, Core last).
 */
#[Title('Apps')]
class Index extends Component
{
    public function activate(string $id, AppManager $manager): void
    {
        $this->run(fn () => $manager->activate($id), 'Aktivierung gestartet – das kann einen Moment dauern.');
    }

    public function start(string $id, AppManager $manager): void
    {
        $this->run(fn () => $manager->start($this->installed($id)), 'Gestartet.');
    }

    public function stop(string $id, AppManager $manager): void
    {
        $this->run(fn () => $manager->stop($this->installed($id)), 'Gestoppt.');
    }

    public function restart(string $id, AppManager $manager): void
    {
        $this->run(fn () => $manager->restart($this->installed($id)), 'Neustart ausgelöst.');
    }

    public function remove(string $id, bool $keepData, AppManager $manager): void
    {
        $this->run(fn () => $manager->remove($this->installed($id), $keepData), $keepData ? 'Entfernt. Die Daten bleiben erhalten.' : 'Entfernt samt Daten.');
    }

    public function checkCore(CoreUpdater $core): void
    {
        try {
            $found = $core->check();
            $this->dispatch('notice', kind: 'success', text: $found ? 'Neue Version gefunden: '.CoreUpdater::describe($found).'.' : 'PK95 Core ist aktuell.');
        } catch (Throwable $e) {
            $this->dispatch('notice', kind: 'error', text: $e->getMessage());
        }
    }

    /**
     * Starts the helper; the page waits in the browser until the new Core answers and reloads.
     */
    public function updateCore(CoreUpdater $core): void
    {
        try {
            $core->start();
            $this->dispatch('core-restarting');
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('notice', kind: 'error', text: $e->getMessage());
        }
    }

    /** Registry check for Core and every app (the same as the scheduler every 6 hours). */
    public function checkAll(UpdateAll $all): void
    {
        Artisan::call('pk95:check-updates');
        $count = $all->apps()->count() + (UpdateAll::coreReady(app(CoreUpdater::class)->status()) ? 1 : 0);
        $this->dispatch('notice', kind: 'success', text: match ($count) {
            0 => 'Alles aktuell.',
            1 => '1 Update gefunden.',
            default => "{$count} Updates gefunden.",
        });
    }

    /** One app, in the queue: backup, new image, health check, rollback on failure. */
    public function updateApp(string $id): void
    {
        $app = $this->installed($id);
        if ($app->available_version === null || $app->step !== null || $app->isTransitional()) {
            return;
        }
        $app->update(['step' => 'Update eingeplant']);
        AppOperation::update($app->app_id);
        $this->dispatch('notice', kind: 'success', text: "Update von {$app->displayName()} gestartet – vorher wird gesichert.");
    }

    public function updateAll(UpdateAll $all): void
    {
        try {
            $run = $all->start();
            $parts = count($run['apps']) + ($run['core'] ? 1 : 0);
            $this->dispatch('notice', kind: 'success', text: $parts === 1 ? 'Update gestartet.' : "{$parts} Updates gestartet, eins nach dem anderen.");
            if ($all->tick() === 'core') {
                $this->dispatch('core-restarting');
            }
        } catch (Throwable $e) {
            $this->dispatch('notice', kind: 'error', text: $e->getMessage());
        }
    }

    /**
     * Called by wire:poll while something is in motion.
     */
    public function poll(AppManager $manager, CoreUpdater $core, UpdateAll $all): void
    {
        $core->finish();
        if ($all->tick() === 'core') {
            $this->dispatch('core-restarting');
        }
        foreach (InstalledApp::query()->where('status', InstalledApp::STARTING)->get() as $app) {
            try {
                $manager->refresh($app);
            } catch (Throwable) {
                // shown on the next regular sync
            }
        }
        // an activation running in the queue may have finished: let the sidebar follow
        $this->dispatch('apps-changed');
    }

    public function render(Catalog $catalog, DockerClient $docker, CoreUpdater $core, UpdateAll $all, AppRegistry $registry)
    {
        $core->finish();
        $coreStatus = $core->status();
        $error = null;
        try {
            $definitions = $catalog->all();
        } catch (CatalogException $e) {
            $definitions = [];
            $error = $e->getMessage();
        }
        $installed = InstalledApp::query()->get()->keyBy('app_id');
        $run = $all->running();

        return view('livewire.apps.index', [
            'definitions' => $definitions,
            'installed' => $installed,
            'catalogError' => $error,
            'dockerOk' => $docker->available(),
            'core' => $coreStatus,
            'hints' => $installed->map(fn (InstalledApp $a) => $registry->updateHint($a)),
            'updateCount' => $all->apps()->count() + (UpdateAll::coreReady($coreStatus) ? 1 : 0),
            'run' => $run,
            'progress' => $run ? $all->progress($run) : [],
            'lastRun' => $all->lastResult(),
            'polling' => $coreStatus['running'] !== null || $run !== null
                || $installed->contains(fn (InstalledApp $a) => $a->isTransitional() || $a->step !== null),
        ]);
    }

    private function installed(string $id): InstalledApp
    {
        return InstalledApp::query()->where('app_id', $id)->firstOrFail();
    }

    private function run(callable $action, string $success): void
    {
        try {
            $action();
            $this->dispatch('notice', kind: 'success', text: $success);
            $this->dispatch('apps-changed');
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('notice', kind: 'error', text: $e->getMessage());
        }
    }
}
