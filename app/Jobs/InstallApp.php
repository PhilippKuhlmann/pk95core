<?php

namespace App\Jobs;

use App\Models\InstalledApp;
use App\Services\Apps\AppManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Activates one app: pull, create and start its container, wait for health, read the manifest.
 */
class InstallApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Seconds; set in the constructor from the pull and health timeouts. */
    public int $timeout;

    public function __construct(public int $installedAppId)
    {
        $this->timeout = (int) config('pk95.docker.pull_timeout', 900) + (int) config('pk95.health_timeout', 120) + 120;
    }

    public function handle(AppManager $manager): void
    {
        $app = InstalledApp::query()->find($this->installedAppId);
        if ($app === null || $app->status !== InstalledApp::INSTALLING) {
            return;
        }
        $manager->install($app);
    }

    public function failed(?Throwable $e): void
    {
        $app = InstalledApp::query()->find($this->installedAppId);
        if ($app !== null && $app->status === InstalledApp::INSTALLING) {
            $message = $e?->getMessage() ?: 'Die Aktivierung wurde abgebrochen.';
            $app->update(['status' => InstalledApp::FAILED, 'error' => $message]);
            $app->log($message, 'error');
        }
    }
}
