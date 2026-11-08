<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Models\InstalledApp;
use App\Services\Backups\BackupService;
use App\Services\Updates\UpdateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Long-running work on one app in the queue: backup, restore, update. One at a time per
 * app (cache lock), so a nightly backup cannot stop a container in the middle of an update.
 */
class AppOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * @param  'backup'|'restore'|'update'  $operation
     * @param  array<string, mixed>  $options  backup: reason · restore: backup id · update: tag
     */
    public function __construct(public string $appId, public string $operation, public array $options = []) {}

    public static function backup(string $appId, string $reason = 'manual'): void
    {
        self::dispatch($appId, 'backup', ['reason' => $reason]);
    }

    public static function restore(Backup $backup): void
    {
        self::dispatch($backup->app_id, 'restore', ['backup' => $backup->id]);
    }

    public static function update(string $appId, ?string $tag = null): void
    {
        self::dispatch($appId, 'update', ['tag' => $tag]);
    }

    public function handle(BackupService $backups, UpdateService $updates): void
    {
        $app = InstalledApp::query()->where('app_id', $this->appId)->first();
        if ($app === null) {
            return;
        }
        $lock = Cache::lock('pk95-app-op-'.$this->appId, $this->timeout);
        if (! $lock->get()) {
            $app->log("{$this->label()} übersprungen: Für diese App läuft gerade schon ein Vorgang.", 'error');

            return;
        }

        try {
            $app->update(['step' => $this->label()]);
            match ($this->operation) {
                'backup' => $backups->create($app, (string) ($this->options['reason'] ?? 'manual')),
                'restore' => $backups->restore(Backup::query()->findOrFail($this->options['backup']), $app),
                'update' => $updates->update($app, $this->options['tag'] ?? null),
            };
        } catch (Throwable $e) {
            // Already logged and reported as an event by the services.
            report($e);
        } finally {
            $app->refresh();
            if ($app->status !== InstalledApp::INSTALLING) {
                $app->update(['step' => null]);
            }
            $lock->release();
        }
    }

    private function label(): string
    {
        return ['backup' => 'Sicherung', 'restore' => 'Wiederherstellung', 'update' => 'Update'][$this->operation] ?? $this->operation;
    }
}
