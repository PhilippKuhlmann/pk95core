<?php

use App\Http\Controllers\Auth\SetupController;
use App\Jobs\AppOperation;
use App\Models\AppLink;
use App\Models\InstalledApp;
use App\Services\Apps\AppManager;
use App\Services\Automation\Linker;
use App\Services\Docker\DockerClient;
use App\Services\Updates\UpdateService;
use App\Support\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Keep the stored app status in line with the containers (crash, manual docker stop, …).
Schedule::command('pk95:sync')->everyMinute()->withoutOverlapping();
// New images in the registry; the maintenance window runs backups and automatic updates.
Schedule::command('pk95:check-updates')->everySixHours()->withoutOverlapping();
Schedule::command('pk95:maintenance')->everyMinute()->withoutOverlapping();
// Proxy hosts waiting for DNS are switched on once the domain points here.
Schedule::command('pk95:links')->everyTenMinutes()->withoutOverlapping();

Artisan::command('pk95:check-updates', function (UpdateService $updates) {
    foreach (InstalledApp::query()->get() as $app) {
        try {
            $found = $updates->check($app);
            $this->line("{$app->app_id}: ".($found ? "Update verfügbar ({$found})" : 'aktuell'));
        } catch (Throwable $e) {
            $app->update(['update_checked_at' => now()]);
            $this->warn("{$app->app_id}: {$e->getMessage()}");
        }
    }
})->purpose('Registry nach neuen Versionen der Apps fragen');

// Once a day at the maintenance time: automatic updates (with their own backup) per the
// app's policy, otherwise the scheduled backup.
Artisan::command('pk95:maintenance {--force : Unabhängig von der Uhrzeit ausführen}', function () {
    $today = now()->toDateString();
    $due = now()->format('H:i') === Settings::get('maintenance_time') && Settings::get('maintenance_last_run') !== $today;
    if (! $due && ! $this->option('force')) {
        return 0;
    }
    Settings::put('maintenance_last_run', $today);

    foreach (InstalledApp::query()->whereIn('status', [InstalledApp::RUNNING, InstalledApp::UNHEALTHY, InstalledApp::STOPPED])->get() as $app) {
        if (in_array($app->update_policy, ['patch', 'minor', 'all'], true) && $app->available_version !== null) {
            AppOperation::update($app->app_id);
            $this->line("{$app->app_id}: Update eingeplant");
        } elseif ($app->auto_backup && $app->definition()?->volumes) {
            AppOperation::backup($app->app_id, 'scheduled');
            $this->line("{$app->app_id}: Sicherung eingeplant");
        }
    }

    return 0;
})->purpose('Wartungsfenster: geplante Sicherungen und automatische Updates');

Artisan::command('pk95:links', function (Linker $linker) {
    foreach (AppLink::query()->where('state', AppLink::PENDING_DNS)->get() as $link) {
        $linker->recheck($link);
        $this->line("{$link->domain}: {$link->fresh()->state}");
    }
})->purpose('Automatisch angelegte Proxy-Hosts einschalten, sobald DNS passt');

// Docker entrypoint: while no account exists, create (or re-print) the one-time code the
// first-run page asks for; once an account exists, remove a left-over code file.
Artisan::command('pk95:setup-token', function () {
    $token = SetupController::ensureToken();
    if ($token !== null) {
        $this->line('==> Einrichtungscode: '.$token);
    }
})->purpose('Einrichtungscode für die Ersteinrichtung erzeugen');

// Docker entrypoint: an activation or removal that was running when the container stopped
// will never finish - mark it as failed so it can be retried.
Artisan::command('pk95:recover', function () {
    $stuck = InstalledApp::query()->whereIn('status', [InstalledApp::INSTALLING, InstalledApp::REMOVING])->get();
    foreach ($stuck as $app) {
        $app->update(['status' => InstalledApp::FAILED, 'error' => 'Unterbrochen durch einen Neustart von Core.']);
        $app->log('Unterbrochen durch einen Neustart von Core.', 'error');
    }
    $this->line("==> {$stuck->count()} unterbrochene Vorgänge zurückgesetzt");
})->purpose('Unterbrochene App-Vorgänge als fehlgeschlagen markieren');

Artisan::command('pk95:sync', function (AppManager $manager, DockerClient $docker) {
    if (! $docker->available()) {
        $this->warn('Docker ist nicht erreichbar.');

        return 1;
    }
    foreach (InstalledApp::query()->get() as $app) {
        try {
            $manager->refresh($app);
        } catch (Throwable $e) {
            $this->warn("{$app->app_id}: {$e->getMessage()}");
        }
    }

    return 0;
})->purpose('Status aller Apps mit Docker abgleichen');
