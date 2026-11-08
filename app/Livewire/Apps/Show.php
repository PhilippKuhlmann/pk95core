<?php

namespace App\Livewire\Apps;

use App\Jobs\AppOperation;
use App\Models\Backup;
use App\Models\InstalledApp;
use App\Services\Apps\AppManager;
use App\Services\Apps\AppRegistry;
use App\Services\Backups\BackupService;
use App\Services\Updates\UpdateService;
use Livewire\Component;
use Throwable;

/**
 * Generic overview of one activated app: state, container, manifest, lifecycle log and
 * container log. Every running app gets this page, with or without a Core module.
 */
class Show extends Component
{
    public InstalledApp $installedApp;

    public bool $showLogs = false;

    public string $updatePolicy = 'notify';

    public bool $autoBackup = true;

    public function mount(): void
    {
        $this->updatePolicy = $this->installedApp->update_policy;
        $this->autoBackup = $this->installedApp->auto_backup;
    }

    public function updatedUpdatePolicy(string $value): void
    {
        if (array_key_exists($value, InstalledApp::UPDATE_POLICIES)) {
            $this->installedApp->update(['update_policy' => $value]);
            $this->dispatch('notice', kind: 'success', text: 'Update-Regel gespeichert.');
        }
    }

    public function updatedAutoBackup(bool $value): void
    {
        $this->installedApp->update(['auto_backup' => $value]);
        $this->dispatch('notice', kind: 'success', text: $value ? 'Tägliche Sicherung an.' : 'Tägliche Sicherung aus.');
    }

    public function checkUpdates(UpdateService $updates): void
    {
        try {
            $found = $updates->check($this->installedApp);
            $this->dispatch('notice', kind: 'success', text: $found ? "Update verfügbar: {$found}" : 'Kein Update gefunden.');
        } catch (Throwable $e) {
            $this->installedApp->update(['update_checked_at' => now()]);
            $this->dispatch('notice', kind: 'error', text: 'Update-Prüfung fehlgeschlagen: '.$e->getMessage());
        }
    }

    public function runUpdate(): void
    {
        $this->installedApp->update(['step' => 'Update eingeplant']);
        AppOperation::update($this->installedApp->app_id);
        $this->dispatch('notice', kind: 'success', text: 'Update gestartet – vorher wird gesichert.');
    }

    public function backupNow(): void
    {
        $this->installedApp->update(['step' => 'Sicherung eingeplant']);
        AppOperation::backup($this->installedApp->app_id);
        $this->dispatch('notice', kind: 'success', text: 'Sicherung gestartet.');
    }

    public function restore(int $backupId): void
    {
        $backup = Backup::query()->where('app_id', $this->installedApp->app_id)->findOrFail($backupId);
        $this->installedApp->update(['step' => 'Wiederherstellung eingeplant']);
        AppOperation::restore($backup);
        $this->dispatch('notice', kind: 'success', text: 'Wiederherstellung gestartet.');
    }

    public function deleteBackup(int $backupId, BackupService $backups): void
    {
        $backups->delete(Backup::query()->where('app_id', $this->installedApp->app_id)->findOrFail($backupId));
        $this->dispatch('notice', kind: 'success', text: 'Sicherung gelöscht.');
    }

    public function action(string $action, AppManager $manager): void
    {
        try {
            match ($action) {
                'start' => $manager->start($this->installedApp),
                'stop' => $manager->stop($this->installedApp),
                'restart' => $manager->restart($this->installedApp),
                'reactivate' => $manager->activate($this->installedApp->app_id),
                'refresh' => $manager->refresh($this->installedApp),
            };
            $this->dispatch('notice', kind: 'success', text: match ($action) {
                'start' => 'Gestartet.',
                'stop' => 'Gestoppt.',
                'restart' => 'Neustart ausgelöst.',
                'reactivate' => 'Container wird neu erstellt.',
                default => 'Status aktualisiert.',
            });
            $this->dispatch('apps-changed');
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('notice', kind: 'error', text: $e->getMessage());
        }
        $this->installedApp->refresh();
    }

    public function remove(bool $keepData, AppManager $manager)
    {
        try {
            $manager->remove($this->installedApp, $keepData);
        } catch (Throwable $e) {
            $this->dispatch('notice', kind: 'error', text: $e->getMessage());

            return null;
        }
        session()->flash('success', $this->installedApp->displayName().' wurde entfernt.');

        return $this->redirectRoute('apps.index', navigate: false);
    }

    public function poll(AppManager $manager): void
    {
        if ($this->installedApp->status === InstalledApp::STARTING) {
            try {
                $manager->refresh($this->installedApp);
            } catch (Throwable) {
                // shown on the next regular sync
            }
        }
        $this->installedApp->refresh();
        $this->dispatch('apps-changed');
    }

    public function render(AppRegistry $registry, AppManager $manager)
    {
        $logs = [];
        $logError = null;
        if ($this->showLogs && $this->installedApp->container_id) {
            try {
                $logs = $manager->containerLogs($this->installedApp, 300);
            } catch (Throwable $e) {
                $logError = $e->getMessage();
            }
        }

        return view('livewire.apps.show', [
            'definition' => $this->installedApp->definition(),
            'manifest' => $this->installedApp->manifest(),
            'reason' => $registry->unavailableReason($this->installedApp),
            'pages' => collect($this->installedApp->manifest()?->menu ?? [])
                ->filter(fn (array $entry) => $registry->page($this->installedApp, $entry['key']) !== null)
                ->values(),
            'history' => $this->installedApp->logs()->latest('id')->limit(50)->get(),
            'backups' => Backup::query()->where('app_id', $this->installedApp->app_id)->latest('id')->limit(30)->get(),
            'policies' => InstalledApp::UPDATE_POLICIES,
            'logs' => $logs,
            'logError' => $logError,
        ])->title($this->installedApp->displayName());
    }
}
