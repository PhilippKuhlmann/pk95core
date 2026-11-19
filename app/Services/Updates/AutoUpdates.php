<?php

namespace App\Services\Updates;

use App\Http\Support\Format;
use App\Models\InstalledApp;
use App\Services\Apps\AppRegistry;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * What the maintenance window installs on its own: the apps per their update policy (when the
 * new version fits the Core module) and Core itself per the Core policy, Core last (UpdateAll
 * starts it once no app operation waits or runs). A Core update to another major version is
 * never automatic, whatever the policy: the module version ranges change with it.
 *
 * Settings: core_update_policy (notify | patch | minor | all), app_update_policy (the policy
 * a newly installed app starts with).
 */
class AutoUpdates
{
    public const CORE_POLICY = 'core_update_policy';

    public const APP_POLICY = 'app_update_policy';

    /** policies that install by themselves */
    public const AUTOMATIC = ['patch', 'minor', 'all'];

    /** Core: no "off", Core is always checked */
    public const CORE_POLICIES = [
        'notify' => 'Nur melden',
        'patch' => 'Automatisch: nur Fehlerbehebungen (x.y.Z)',
        'minor' => 'Automatisch: neue Funktionen (x.Y.z)',
        'all' => 'Automatisch: alle Versionen derselben Hauptversion',
    ];

    public function __construct(
        private readonly AppRegistry $registry,
        private readonly CoreUpdater $core,
    ) {}

    public static function corePolicy(): string
    {
        $policy = (string) Settings::get(self::CORE_POLICY);

        return array_key_exists($policy, self::CORE_POLICIES) ? $policy : 'notify';
    }

    public static function appPolicy(): string
    {
        $policy = (string) Settings::get(self::APP_POLICY);

        return array_key_exists($policy, InstalledApp::UPDATE_POLICIES) ? $policy : 'notify';
    }

    /**
     * Why Core does not update by itself to $available (null = it does).
     *
     * @param  array<string, mixed>|null  $available  the newer build (Settings core_update)
     */
    public static function coreBlocker(string $current, ?array $available, string $policy): ?string
    {
        $version = (string) ($available['version'] ?? '');

        return match (true) {
            $available === null => 'kein Update',
            ! in_array($policy, self::AUTOMATIC, true) => 'nur melden',
            ! UpdateService::isVersion($version) || ! UpdateService::isVersion($current) => 'ohne Versionsnummer – nur von Hand',
            self::major($version) !== self::major($current) => 'neue Hauptversion – nur von Hand',
            UpdateService::pick([$version], $current, $policy) === null => 'außerhalb der Update-Regel',
            default => null,
        };
    }

    /**
     * Why the maintenance window does not install Core's update now (null = it does).
     */
    public function coreReason(): ?string
    {
        $status = $this->core->status();
        $reason = self::coreBlocker((string) $status['version'], $status['available'], self::corePolicy());

        return $reason === null && empty($status['compose']) ? 'nicht mit docker compose gestartet – nur von Hand' : $reason;
    }

    /**
     * Why an app does not update by itself to its available version (null = it does).
     */
    public function appBlocker(InstalledApp $app): ?string
    {
        $available = $app->available_version;
        if ($available === null) {
            return 'kein Update';
        }
        if (! in_array($app->update_policy, self::AUTOMATIC, true)) {
            return $app->update_policy === 'off' ? 'Updates aus' : 'nur melden';
        }
        if (! in_array($app->status, [InstalledApp::RUNNING, InstalledApp::UNHEALTHY, InstalledApp::STOPPED], true)) {
            return 'App ist nicht bereit';
        }
        // the policy may have been tightened since the last check (compared like check(): the version tag)
        $installed = UpdateService::isVersion($app->tag) ? $app->tag : (string) $app->version;
        if (UpdateService::isVersion($available) && UpdateService::isVersion($installed)
            && UpdateService::pick([$available], $installed, $app->update_policy) === null) {
            return 'außerhalb der Update-Regel';
        }
        if (! $this->registry->fits($app, $available)) {
            $max = $this->registry->module($app)?->maxVersion();

            return $max !== null && version_compare(ltrim($available, 'v'), $max, '>=') ? 'wartet auf Core-Update' : 'passt nicht zum Core-Modul';
        }

        return null;
    }

    /**
     * Everything with an update, and whether the next maintenance window installs it, apps in
     * update order.
     *
     * @return array{apps: list<array{app: InstalledApp, name: string, from: string, to: string, planned: bool, reason: ?string}>,
     *     core: array{from: string, to: string, planned: bool, reason: ?string}|null}
     */
    public function plan(): array
    {
        $apps = [];
        foreach (InstalledApp::query()->whereNotNull('available_version')->orderBy('app_id')->get() as $app) {
            $reason = $this->appBlocker($app);
            $change = UpdateService::change($app);
            $apps[] = ['app' => $app, 'name' => $app->displayName(), 'from' => $change['from'], 'to' => $change['to'],
                'planned' => $reason === null, 'reason' => $reason];
        }
        $rank = fn (array $row) => ($i = array_search($row['app']->app_id, UpdateAll::ORDER, true)) === false ? count(UpdateAll::ORDER) : $i;
        usort($apps, fn ($a, $b) => [$rank($a), $a['app']->app_id] <=> [$rank($b), $b['app']->app_id]);

        $status = $this->core->status();
        $core = null;
        if ($status['available'] !== null) {
            $reason = $this->coreReason();
            $current = (string) $status['version'];
            $core = ['from' => UpdateService::isVersion($current) ? 'Version '.ltrim($current, 'v') : '„'.$current.'“',
                'to' => ($status['available']['version'] ?? null) ? 'Version '.$status['available']['version'] : CoreUpdater::describe($status['available']),
                'planned' => $reason === null, 'reason' => $reason];
        }

        return ['apps' => $apps, 'core' => $core];
    }

    /**
     * The next maintenance window (local time); "due" while today's window is open and has not run.
     *
     * @return array{at: Carbon, due: bool}
     */
    public static function nextWindow(): array
    {
        $local = now(Format::timezone());
        [$hour, $minute] = array_map('intval', explode(':', (string) Settings::get('maintenance_time')) + [0, 0]);
        $start = $local->copy()->setTime($hour, $minute);
        $ranToday = Settings::get('maintenance_last_run') === $local->toDateString();
        if (! $ranToday && $local->gte($start) && $local->lt($start->copy()->addHour())) {
            return ['at' => $start, 'due' => true];
        }

        return ['at' => $ranToday || $local->gte($start) ? $start->addDay() : $start, 'due' => false];
    }

    private static function major(string $version): int
    {
        return (int) explode('.', ltrim($version, 'v'))[0];
    }
}
