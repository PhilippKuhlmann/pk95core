<?php

namespace App\Livewire\Apps;

use App\Models\InstalledApp;
use App\Services\Apps\AppManager;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\CatalogException;
use App\Services\Docker\DockerClient;
use App\Services\Updates\CoreUpdater;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * The catalog with the state of every app: activate, start, stop, restart, remove. On top
 * Core itself: its build, newer builds in the registry and the self update.
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

    /**
     * Called by wire:poll while something is in motion.
     */
    public function poll(AppManager $manager, CoreUpdater $core): void
    {
        $core->finish();
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

    public function render(Catalog $catalog, DockerClient $docker, CoreUpdater $core)
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

        return view('livewire.apps.index', [
            'definitions' => $definitions,
            'installed' => $installed,
            'catalogError' => $error,
            'dockerOk' => $docker->available(),
            'core' => $coreStatus,
            'polling' => $coreStatus['running'] !== null || $installed->contains(fn (InstalledApp $a) => $a->isTransitional()),
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
