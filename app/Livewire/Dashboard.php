<?php

namespace App\Livewire;

use App\Http\Support\Format;
use App\Models\AppEvent;
use App\Models\Backup;
use App\Models\InstalledApp;
use App\Services\Docker\DockerClient;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The master dashboard: a status line, problems and warnings, per app its figures of the last
 * 24 h with a small chart (lazy tiles, see Dashboard\AppPanel), Core itself, the app cards and
 * the latest events. Only the tiles talk to the apps; this page reads Core's own data.
 */
#[Title('Dashboard')]
class Dashboard extends Component
{
    /** Apps with figures on the dashboard, in this order. */
    public const PANELS = ['dns', 'proxy', 'uptime', 'proxapps'];

    public function render(DockerClient $docker)
    {
        $apps = InstalledApp::query()->get()->sortBy(fn (InstalledApp $a) => mb_strtolower($a->displayName()))->values();
        $lastBackup = Backup::query()->where('status', 'done')->latest('id')->first();

        return view('livewire.dashboard', [
            'apps' => $apps,
            'panels' => collect(self::PANELS)->filter(fn ($id) => $apps->contains('app_id', $id))->values()->all(),
            'running' => $apps->where('status', InstalledApp::RUNNING)->count(),
            'updates' => $apps->filter(fn (InstalledApp $a) => $a->available_version !== null)->count() + (Settings::get('core_update') ? 1 : 0),
            'dockerOk' => $docker->available(),
            'events' => AppEvent::query()->orderByDesc('occurred_at')->orderByDesc('id')->limit(8)->get(),
            'lastBackup' => $lastBackup,
            'lastBackupApp' => $lastBackup ? $apps->firstWhere('app_id', $lastBackup->app_id)?->displayName() ?? $lastBackup->app_id : null,
            'maintenance' => self::nextMaintenance((string) Settings::get('maintenance_time')),
            'coreUpdate' => (bool) Settings::get('core_update'),
        ]);
    }

    /** Next maintenance window as local time ("heute 03:30", "morgen 03:30"), null without a valid time. */
    public static function nextMaintenance(string $time): ?string
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            return null;
        }
        $now = now(Format::timezone());
        $next = $now->copy()->setTime((int) $m[1], (int) $m[2]);
        if ($next->lte($now)) {
            $next = $next->addDay();
        }

        return ($next->isSameDay($now) ? 'heute ' : 'morgen ').$time;
    }

    /** "vor 3 Stunden" for the last backup (null-safe). */
    public static function ago(?Carbon $at): string
    {
        return $at?->diffForHumans() ?? 'noch nie';
    }
}
