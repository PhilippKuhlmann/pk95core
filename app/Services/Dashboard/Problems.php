<?php

namespace App\Services\Dashboard;

use App\Models\AppEvent;
use App\Models\Backup;
use App\Models\InstalledApp;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * Everything on the dashboard that needs a look, worst first, each with the page to go to:
 * apps that do not run or do not answer, failed proxy hosts and monitors, certificates that
 * expire soon, known vulnerabilities, failed backups and updates.
 */
class Problems
{
    /** Failed updates and backups older than this are history, not a problem. */
    public const RECENT_DAYS = 7;

    public function __construct(private readonly AppFigures $figures) {}

    /**
     * @return list<array{tone: 'bad'|'warn', text: string, href: string}>
     */
    public function collect(): array
    {
        $out = [];
        $apps = InstalledApp::query()->get();
        foreach ($apps as $app) {
            $name = $app->displayName();
            if (in_array($app->status, [InstalledApp::FAILED, InstalledApp::UNHEALTHY, InstalledApp::STOPPED], true)) {
                $out[] = ['tone' => $app->status === InstalledApp::STOPPED ? 'warn' : 'bad',
                    'text' => "{$name} läuft nicht".($app->error ? ': '.$app->error : ($app->status === InstalledApp::STOPPED ? ' (gestoppt)' : '.')),
                    'href' => route('apps.show', $app)];

                continue;
            }
            if ($app->status !== InstalledApp::RUNNING) {
                continue; // installing, updating: shown on the app cards
            }
            $f = $this->figures->for($app);
            if (! $f['ok'] && $app->definition()?->module) {
                $out[] = ['tone' => 'warn', 'text' => "{$name} antwortet nicht: {$f['error']}", 'href' => route('apps.show', $app)];

                continue;
            }
            $page = fn (string $key) => route('apps.page', [$app, $key]);
            foreach ($f['downHosts'] ?? [] as $host) {
                $out[] = ['tone' => 'bad', 'text' => "Proxy-Host {$host['domain']} ist nicht erreichbar".($host['error'] !== '' ? ": {$host['error']}" : '.'), 'href' => $page('hosts')];
            }
            foreach ($f['downMonitors'] ?? [] as $monitor) {
                $out[] = ['tone' => 'bad', 'text' => "Monitor „{$monitor}“ ist ausgefallen.", 'href' => $page('monitors')];
            }
            foreach ($f['expiring'] ?? [] as $cert) {
                $out[] = $cert['days'] < 0
                    ? ['tone' => 'bad', 'text' => "Zertifikat für {$cert['domain']} ist abgelaufen.", 'href' => $page('certificates')]
                    : ['tone' => 'warn', 'text' => "Zertifikat für {$cert['domain']} läuft in ".($cert['days'] === 1 ? '1 Tag' : "{$cert['days']} Tagen").' ab.', 'href' => $page('certificates')];
            }
            if (($f['vulns'] ?? 0) > 0) {
                $out[] = ['tone' => 'bad', 'text' => "{$name}: ".($f['vulns'] === 1 ? '1 bekannte Schwachstelle' : "{$f['vulns']} bekannte Schwachstellen").'.', 'href' => $page('updates')];
            }
        }

        $since = now()->subDays(self::RECENT_DAYS);
        foreach ($apps as $app) {
            $backup = Backup::query()->where('app_id', $app->app_id)->latest('id')->first();
            if ($backup?->status === 'failed' && $backup->created_at?->gte($since)) {
                $out[] = ['tone' => 'warn', 'text' => "Letzte Sicherung von {$app->displayName()} ist fehlgeschlagen.", 'href' => route('apps.show', $app)];
            }
            $update = AppEvent::query()->where('app_id', $app->app_id)->whereIn('type', ['core.update.done', 'core.update.failed'])
                ->where('occurred_at', '>=', $since)->latest('occurred_at')->latest('id')->first();
            if ($update?->type === 'core.update.failed') {
                $out[] = ['tone' => 'warn', 'text' => "Letztes Update von {$app->displayName()} ist fehlgeschlagen.", 'href' => route('apps.show', $app)];
            }
        }
        $core = (array) Settings::get('core_self_update_result');
        if (in_array($core['result'] ?? null, ['failed', 'rolled_back'], true) && ($core['at'] ?? null) && Carbon::parse($core['at'])->gte($since)) {
            $out[] = ['tone' => 'warn', 'text' => 'Letztes Update von PK95 Core ist fehlgeschlagen'.($core['result'] === 'rolled_back' ? ', die vorige Version läuft wieder.' : '.'), 'href' => route('apps.index')];
        }

        // worst first, otherwise in the order found (usort is stable)
        usort($out, fn ($a, $b) => ($a['tone'] === 'bad' ? 0 : 1) <=> ($b['tone'] === 'bad' ? 0 : 1));

        return $out;
    }
}
