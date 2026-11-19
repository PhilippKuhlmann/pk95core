<?php

namespace App\Services\Updates;

use App\Jobs\AppOperation;
use App\Models\AppEvent;
use App\Models\InstalledApp;
use App\Services\Apps\AppException;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "Alle aktualisieren" on the Apps page: every app with an update goes into the queue in a
 * fixed order (one worker, so one after another, each with backup and rollback), Core
 * itself comes last and only once no app job waits or runs: its self update replaces the
 * container that runs the queue. The run is kept in the settings, so the new Core (after
 * its own update) finishes it and records one summary event.
 *
 * The maintenance window starts the same kind of run with the apps and Core its policies
 * allow (AutoUpdates, "automatic"). Core starts only once no app operation at all waits or
 * runs (also the scheduled backups), and an automatic run checks the Core policy again then.
 */
class UpdateAll
{
    /** Apps in this order, others after them; DNS last among the apps (port 53 of the LXC). */
    public const ORDER = ['proxapps', 'uptime', 'proxy', 'dns'];

    public const SETTING = 'update_all';

    public const RESULT = 'update_all_result';

    /** Cache lock around tick() (database store: shared by web and scheduler). */
    public const LOCK = 'pk95:update-all-tick';

    /** A run whose app jobs are still not done after this long is closed anyway (queue gone). */
    private const STALE_HOURS = 3;

    public function __construct(private readonly CoreUpdater $core) {}

    /**
     * Apps with an update that can start now, in update order.
     *
     * @return Collection<int, InstalledApp>
     */
    public function apps(): Collection
    {
        $rank = fn (InstalledApp $a) => ($i = array_search($a->app_id, self::ORDER, true)) === false ? count(self::ORDER) : $i;

        return InstalledApp::query()->whereNotNull('available_version')->whereNull('step')
            ->whereIn('status', [InstalledApp::RUNNING, InstalledApp::UNHEALTHY, InstalledApp::STOPPED])
            ->orderBy('app_id')->get()->sortBy($rank)->values();
    }

    /**
     * Core can update itself now ($status from CoreUpdater::status()).
     *
     * @param  array<string, mixed>  $status
     */
    public static function coreReady(array $status): bool
    {
        return $status['available'] !== null && ! empty($status['compose']) && $status['running'] === null;
    }

    /**
     * @return array<string, mixed>|null the run in progress
     */
    public function running(): ?array
    {
        $run = Settings::get(self::SETTING);

        return is_array($run) ? $run : null;
    }

    /**
     * Last summary, shown for a day.
     *
     * @return array{at: string, ok: bool, title: string, body: string}|null
     */
    public function lastResult(): ?array
    {
        $result = Settings::get(self::RESULT);

        return is_array($result) && Carbon::parse($result['at'])->gt(now()->subDay()) ? $result : null;
    }

    /**
     * Queue the app updates; Core follows in tick(). Without arguments: every update there is
     * ("Alle aktualisieren"); the maintenance window passes what the policies allow.
     *
     * @param  Collection<int, InstalledApp>|null  $apps
     * @return array<string, mixed> the run
     */
    public function start(?Collection $apps = null, ?bool $core = null, bool $automatic = false): array
    {
        if ($this->running() !== null) {
            throw new AppException('Die Updates laufen bereits.');
        }
        $apps ??= $this->apps();
        $core ??= self::coreReady($this->core->status());
        if ($apps->isEmpty() && ! $core) {
            throw new AppException('Es gibt keine Updates. Such zuerst nach Updates.');
        }
        foreach ($apps as $app) {
            $app->update(['step' => 'Update eingeplant']);
        }
        $run = ['started_at' => now()->toIso8601String(), 'apps' => $apps->pluck('app_id')->values()->all(), 'core' => $core, 'core_started' => false, 'automatic' => $automatic];
        Settings::put(self::SETTING, $run);
        Settings::put(self::RESULT, null);
        foreach ($apps as $app) {
            AppOperation::update($app->app_id);
        }

        return $run;
    }

    /**
     * Moves the run on (scheduler every minute, Apps page while polling).
     *
     * Both can call this at the same moment: only the holder of the lock moves the run on
     * (re-reading it inside), so Core is started once and the summary recorded once.
     *
     * @return 'core'|'done'|null "core" = Core's self update was just started
     */
    public function tick(): ?string
    {
        if ($this->running() === null) {
            return null;
        }
        $lock = Cache::lock(self::LOCK, 120);
        if (! $lock->get()) {
            return null;
        }
        try {
            return $this->tickLocked();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return 'core'|'done'|null
     */
    private function tickLocked(): ?string
    {
        $run = $this->running();
        if ($run === null) {
            return null;
        }
        $stale = Carbon::parse($run['started_at'])->lt(now()->subHours(self::STALE_HOURS));
        if (! $stale && $this->appsBusy($run['apps'])) {
            return null;
        }
        if ($run['core'] && ! $run['core_started'] && ! isset($run['core_error']) && ! $stale) {
            // Core replaces the container that runs the queue: not while any app operation waits or runs
            if ($this->operationsBusy()) {
                return null;
            }
            $reason = ($run['automatic'] ?? false) ? app(AutoUpdates::class)->coreReason() : null;
            if ($reason !== null) {
                // the policy or the offered build changed since the window started
                $run['core'] = false;
                $run['core_skipped'] = $reason;
                Settings::put(self::SETTING, $run);
                $this->summarize($run);

                return 'done';
            }
            try {
                $this->core->start();
                $run['core_started'] = true;
                Settings::put(self::SETTING, $run);

                return 'core';
            } catch (Throwable $e) {
                report($e);
                $run['core_error'] = $e->getMessage();
            }
        }
        // Core's helper still runs, or the new Core has not recorded the outcome yet.
        if ($run['core_started'] && ! $stale && Settings::get('core_self_update') !== null) {
            return null;
        }
        $this->summarize($run);

        return 'done';
    }

    /**
     * State of every part of the run, for the Apps page.
     *
     * @param  array<string, mixed>  $run
     * @return list<array{name: string, state: 'waiting'|'running'|'done'|'failed'}>
     */
    public function progress(array $run): array
    {
        $outcomes = $this->outcomes($run);
        $apps = InstalledApp::query()->whereIn('app_id', $run['apps'])->get()->keyBy('app_id');
        $out = [];
        foreach ($run['apps'] as $id) {
            $app = $apps[$id] ?? null;
            $state = $outcomes[$id] ?? (($app?->status === InstalledApp::INSTALLING || $app?->step === 'Update') ? 'running' : 'waiting');
            $out[] = ['name' => $app?->displayName() ?? $id, 'state' => $state];
        }
        if ($run['core']) {
            $out[] = ['name' => 'PK95 Core', 'state' => isset($run['core_error']) ? 'failed' : ($run['core_started'] ? 'running' : 'waiting')];
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------

    /**
     * @param  list<string>  $ids
     */
    private function appsBusy(array $ids): bool
    {
        if (InstalledApp::query()->whereIn('app_id', $ids)
            ->where(fn ($q) => $q->whereNotNull('step')->orWhereIn('status', InstalledApp::TRANSITIONAL))->exists()) {
            return true;
        }
        try {
            return DB::table('jobs')->where('payload', 'like', '%AppOperation%')->exists();
        } catch (Throwable) {
            return false; // no database queue
        }
    }

    /**
     * Any app operation, activation or removal waiting or running (all apps, not only the run's).
     */
    private function operationsBusy(): bool
    {
        if (InstalledApp::query()->where(fn ($q) => $q->whereNotNull('step')->orWhereIn('status', InstalledApp::TRANSITIONAL))->exists()) {
            return true;
        }
        try {
            return DB::table('jobs')->exists();
        } catch (Throwable) {
            return false; // no database queue
        }
    }

    /**
     * Outcome per app from the update events since the start.
     *
     * @param  array<string, mixed>  $run
     * @return array<string, 'done'|'failed'>
     */
    private function outcomes(array $run): array
    {
        return AppEvent::query()->whereIn('app_id', $run['apps'])->whereIn('type', ['core.update.done', 'core.update.failed'])
            ->where('created_at', '>=', Carbon::parse($run['started_at']))->orderBy('id')->get()
            ->mapWithKeys(fn (AppEvent $e) => [$e->app_id => $e->type === 'core.update.done' ? 'done' : 'failed'])->all();
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function summarize(array $run): void
    {
        $outcomes = $this->outcomes($run);
        $names = InstalledApp::query()->whereIn('app_id', $run['apps'])->get()->mapWithKeys(fn ($a) => [$a->app_id => $a->displayName()]);
        $done = $failed = $skipped = [];
        foreach ($run['apps'] as $id) {
            $name = $names[$id] ?? $id;
            match ($outcomes[$id] ?? null) {
                'done' => $done[] = $name,
                'failed' => $failed[] = $name,
                default => $skipped[] = $name,
            };
        }
        if ($run['core']) {
            $result = (array) Settings::get('core_self_update_result');
            match (true) {
                isset($run['core_error']) => $failed[] = 'PK95 Core ('.$run['core_error'].')',
                ! $run['core_started'] => $skipped[] = 'PK95 Core',
                in_array($result['result'] ?? null, ['done', 'current'], true) => $done[] = 'PK95 Core',
                default => $failed[] = 'PK95 Core',
            };
        }
        $ok = $failed === [] && $skipped === [];
        $parts = array_filter([
            $done ? count($done).' aktualisiert' : null,
            $failed ? count($failed).' fehlgeschlagen' : null,
            $skipped ? count($skipped).' nicht gelaufen' : null,
        ]);
        $title = (($run['automatic'] ?? false) ? 'Automatische Updates abgeschlossen: ' : 'Alle Updates abgeschlossen: ').implode(', ', $parts);
        $body = implode("\n", array_filter([
            $done ? 'Aktualisiert: '.implode(', ', $done) : null,
            $failed ? 'Fehlgeschlagen: '.implode(', ', $failed).'. Die vorige Version läuft weiter, Details unter Ereignisse.' : null,
            $skipped ? 'Nicht gelaufen: '.implode(', ', $skipped).'.' : null,
            isset($run['core_skipped']) ? 'PK95 Core nicht aktualisiert: '.$run['core_skipped'].'.' : null,
        ]));
        AppEvent::record(AppEvent::CORE, 'core.update.all', $ok ? 'info' : 'warning', $title, $body);
        Settings::put(self::RESULT, ['at' => now()->toIso8601String(), 'ok' => $ok, 'title' => $title, 'body' => $body]);
        Settings::put(self::SETTING, null);
    }
}
