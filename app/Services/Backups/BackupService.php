<?php

namespace App\Services\Backups;

use App\Models\AppEvent;
use App\Models\Backup;
use App\Models\InstalledApp;
use App\Services\Apps\AppException;
use App\Services\Apps\AppManager;
use App\Services\Catalog\Catalog;
use App\Services\Docker\DockerClient;
use App\Support\Settings;
use Throwable;

/**
 * Backups of an app's volumes. For each volume a helper container mounts the volume and
 * the backup directory and runs tar - Core itself never needs access to the volume data.
 *
 * The backup directory is the same path inside Core and on the LXC (docker-compose.yml),
 * so Core can hand it to Docker as a bind mount and read the files it gets back.
 */
class BackupService
{
    public function __construct(
        private readonly DockerClient $docker,
        private readonly Catalog $catalog,
        private readonly AppManager $manager,
    ) {}

    public function directory(string $appId = ''): string
    {
        return rtrim((string) config('pk95.data_dir'), '/').'/backups'.($appId !== '' ? '/'.$appId : '');
    }

    /**
     * Back up all volumes of the app. The container is stopped meanwhile (consistent files)
     * and started again afterwards. Throws on failure; the Backup row says "failed" then.
     */
    public function create(InstalledApp $app, string $reason = 'manual'): Backup
    {
        $definition = $this->catalog->get($app->app_id);
        if ($definition->volumes === []) {
            throw new AppException("{$definition->name} hat keine Daten, die sich sichern lassen.");
        }

        $backup = Backup::query()->create(['app_id' => $app->app_id, 'reason' => $reason, 'status' => 'running', 'version' => $app->version]);
        $dir = $this->directory($app->app_id);
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $stamp = now()->format('Ymd-His').'-'.$backup->id;
        $app->log('Sicherung gestartet');

        $wasRunning = false;
        $files = [];
        try {
            if ($app->status === InstalledApp::RUNNING && $app->manifest()?->has('backup')) {
                // The app writes everything pending into its files (e.g. SQLite WAL checkpoint).
                $app->client()->post('/core/backup/prepare');
            }
            $wasRunning = $this->manager->pause($app);

            foreach ($definition->volumes as $volume) {
                $file = "{$stamp}-{$volume['name']}.tar.gz";
                $result = $this->docker->run((string) config('pk95.helper_image'), ['tar', 'czf', '/backups/'.$file, '-C', '/source', '.'], [
                    ['Type' => 'volume', 'Source' => $definition->volumeName($volume['name']), 'Target' => '/source', 'ReadOnly' => true],
                    ['Type' => 'bind', 'Source' => $dir, 'Target' => '/backups'],
                ]);
                if ($result['exit'] !== 0) {
                    throw new AppException("tar für Volume {$volume['name']} endete mit Code {$result['exit']}: {$result['output']}");
                }
                $files[$volume['name']] = $dir.'/'.$file;
            }

            $size = array_sum(array_map(fn (string $f) => is_file($f) ? (int) filesize($f) : 0, $files));
            $backup->update(['status' => 'done', 'files' => $files, 'size' => $size]);
            $app->log('Sicherung erstellt ('.self::humanSize($size).')', 'success');
        } catch (Throwable $e) {
            $backup->update(['status' => 'failed', 'files' => $files, 'error' => $e->getMessage()]);
            $app->log('Sicherung fehlgeschlagen: '.$e->getMessage(), 'error');
            AppEvent::record($app->app_id, 'core.backup.failed', 'critical', "Sicherung von {$app->displayName()} fehlgeschlagen", $e->getMessage());
            throw $e;
        } finally {
            if ($wasRunning) {
                $this->manager->unpause($app);
            }
        }

        $this->prune($app->app_id);

        return $backup;
    }

    /**
     * Replace the volumes' content with the backup. The container is stopped meanwhile.
     */
    public function restore(Backup $backup, InstalledApp $app): void
    {
        if ($backup->status !== 'done' || ! $backup->files) {
            throw new AppException('Diese Sicherung ist unvollständig und lässt sich nicht zurückspielen.');
        }
        $definition = $this->catalog->get($app->app_id);
        $dir = $this->directory($app->app_id);
        foreach ($backup->files as $file) {
            if (! is_file($file)) {
                throw new AppException('Die Sicherungsdatei '.basename($file).' fehlt.');
            }
        }

        $app->log('Stelle Sicherung vom '.$backup->created_at?->format('d.m.Y H:i').' wieder her');
        $wasRunning = $this->manager->pause($app);
        try {
            foreach ($backup->files as $volume => $file) {
                $script = 'find /target -mindepth 1 -delete && tar xzf /backups/'.escapeshellarg(basename($file)).' -C /target';
                $result = $this->docker->run((string) config('pk95.helper_image'), ['sh', '-c', $script], [
                    ['Type' => 'volume', 'Source' => $definition->volumeName((string) $volume), 'Target' => '/target'],
                    ['Type' => 'bind', 'Source' => $dir, 'Target' => '/backups', 'ReadOnly' => true],
                ]);
                if ($result['exit'] !== 0) {
                    throw new AppException("Wiederherstellen von Volume {$volume} endete mit Code {$result['exit']}: {$result['output']}");
                }
            }
            $app->log('Sicherung wiederhergestellt', 'success');
        } catch (Throwable $e) {
            $app->log('Wiederherstellen fehlgeschlagen: '.$e->getMessage(), 'error');
            AppEvent::record($app->app_id, 'core.backup.restore_failed', 'critical', "Wiederherstellen von {$app->displayName()} fehlgeschlagen", $e->getMessage());
            throw $e;
        } finally {
            if ($wasRunning) {
                $this->manager->unpause($app);
            }
        }
    }

    public function delete(Backup $backup): void
    {
        foreach ((array) $backup->files as $file) {
            if (is_file($file) && str_starts_with(realpath($file) ?: '', realpath($this->directory()) ?: $this->directory())) {
                @unlink($file);
            }
        }
        $backup->delete();
    }

    /**
     * Keep the newest N successful backups of the app, drop failed ones older than a day.
     */
    public function prune(string $appId): void
    {
        $keep = max(1, (int) Settings::get('backup_keep'));
        $old = Backup::query()->where('app_id', $appId)->where('status', 'done')->orderByDesc('id')->skip($keep)->take(1000)->get();
        foreach ($old as $backup) {
            $this->delete($backup);
        }
        foreach (Backup::query()->where('app_id', $appId)->where('status', 'failed')->where('created_at', '<', now()->subDay())->get() as $backup) {
            $this->delete($backup);
        }
    }

    public static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return number_format($value, $i === 0 ? 0 : 1, ',', '.').' '.$units[$i];
    }
}
