<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Component;

/**
 * PK95 Proxy: availability of the reverse proxy targets (checked every minute) with a
 * 24-hour timeline, uptime figures and the list of outages and recoveries.
 */
class Health extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    public function render()
    {
        [$d, $error] = $this->load('/api/health');
        $d = is_array($d) ? $d : ['hosts' => [], 'events' => [], 'now' => (int) (microtime(true) * 1000)];

        $hosts = $d['hosts'];
        usort($hosts, fn ($a, $b) => [self::rank($a), $a['domains'][0] ?? ''] <=> [self::rank($b), $b['domains'][0] ?? '']);

        // The next event of a host ends its outage.
        $events = $d['events'];
        $start = [];
        for ($i = count($events) - 1; $i >= 0; $i--) {
            $e = &$events[$i];
            if (! $e['up']) {
                $start[$e['host_id']] = $e['ts'];
            } elseif (isset($start[$e['host_id']])) {
                $e['lasted'] = $e['ts'] - $start[$e['host_id']];
                unset($start[$e['host_id']]);
            }
            unset($e);
        }

        $active = array_filter($hosts, fn ($x) => $x['enabled']);
        $down = count(array_filter($active, fn ($x) => $x['known'] && ! $x['up']));
        $open = count(array_filter($active, fn ($x) => ! $x['known'] || ($x['up'] && $x['fails'] > 0)));
        $parts = [];
        if ($down) {
            $parts[] = "{$down} von ".count($active).' Hosts ausgefallen';
        }
        if ($open) {
            $parts[] = $open.' '.($open === 1 ? 'wird' : 'werden').' gerade geprüft';
        }
        $summary = ! $active ? '' : ($parts ? implode(', ', $parts).'.' : 'Alle '.count($active).' Hosts erreichbar. Prüfung jede Minute.');

        return view('modules.proxy.health', [
            'hosts' => $hosts,
            'events' => $events,
            'now' => $d['now'],
            'summary' => $summary,
            'loadError' => $error,
        ]);
    }

    /**
     * @param  array<string, mixed>  $x
     */
    public static function rank(array $x): int
    {
        return match (true) {
            ! $x['enabled'] => 4,
            (bool) $x['maintenance'] => 2,
            ! $x['known'] => 3,
            ! $x['up'] => 0,
            default => $x['fails'] > 0 ? 1 : 2,
        };
    }

    /**
     * @param  array<string, mixed>  $x
     * @return array{0: string, 1: string, 2: string} tone, label, detail
     */
    public static function label(array $x, int $now): array
    {
        return match (true) {
            ! $x['enabled'] => ['none', 'Deaktiviert', ''],
            (bool) $x['maintenance'] => ['maint', 'Wartung', 'Keine Prüfung während der Wartung'],
            ! $x['known'] => ['warn', 'Wird geprüft', ''],
            ! $x['up'] => ['bad', 'Ausgefallen', ($x['error'] ?: 'Fehler').', seit '.Fmt::duration($now - $x['since'])],
            $x['fails'] > 0 => ['warn', 'Unsicher', ($x['error'] ?: 'Fehler').', wird nachgeprüft'],
            default => ['good', 'Erreichbar', 'HTTP '.$x['status'].', '.Fmt::ms($x['latency_ms'])],
        };
    }
}
