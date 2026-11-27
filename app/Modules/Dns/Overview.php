<?php

namespace App\Modules\Dns;

use App\Models\InstalledApp;
use App\Modules\Concerns\LoadsLazily;
use App\Modules\Concerns\TalksToApp;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 DNS: protection switch with pause, warnings (port 53, upstreams), totals, queries
 * over time (SVG bars rendered on the server) and top lists.
 */
class Overview extends Component
{
    use LoadsLazily;
    use TalksToApp;

    public const PLACEHOLDER = [['header', 'Übersicht', 'w-56', 'sub' => 1], ['panel', 'h-36'], ['tiles', 4], ['panel', 'h-72'], ['panels', 4, 'h-64', 'md:grid-cols-2']];

    public InstalledApp $installedApp;

    #[Url]
    public int $hours = 24;

    public const RANGES = [24 => '24 Std.', 168 => '7 Tage', 720 => '30 Tage'];

    /** Pause durations in minutes; 0 = off until switched on again. */
    public const PAUSES = [1 => '1 Minute', 10 => '10 Minuten', 60 => '1 Stunde', 480 => '8 Stunden', 1440 => '24 Stunden', 0 => 'Bis zum Einschalten'];

    public function mount(): void
    {
        $this->setRange($this->hours);
    }

    public function setRange(int $hours): void
    {
        $this->hours = isset(self::RANGES[$hours]) ? $hours : 24;
    }

    /** $hours is public: a value set from the browser goes through the same check. */
    public function updatedHours(): void
    {
        $this->setRange($this->hours);
    }

    public function enable(): void
    {
        $this->protection(['enabled' => true], 'Schutz ist eingeschaltet.');
    }

    public function pause(int $minutes): void
    {
        if (! isset(self::PAUSES[$minutes])) {
            return;
        }
        $this->protection(['enabled' => false, 'minutes' => $minutes],
            $minutes === 0 ? 'Schutz ist ausgeschaltet.' : 'Schutz pausiert für '.self::PAUSES[$minutes].'.');
    }

    public function render()
    {
        [$status, $error] = $this->load('/api/status');
        [$stats, $statsError] = $this->load('/api/stats', ['hours' => $this->hours]);
        // countries (PK95 DNS 1.2.0): only with top_countries in the stats; the filter for the badges
        $geo = is_array($stats) && array_key_exists('top_countries', $stats) ? Countries::loadGeo($this->installedApp)[0] : null;

        return view('modules.dns.overview', [
            'status' => is_array($status) ? $status : null,
            'stats' => is_array($stats) ? $stats : null,
            'geo' => $geo,
            'loadError' => $error ?? $statsError,
            'logBase' => route('apps.page', [$this->installedApp, 'log']),
            'countriesUrl' => route('apps.page', [$this->installedApp, 'countries']),
        ]);
    }

    /**
     * Protection state for the view: on, paused (until) or off.
     *
     * @param  array<string, mixed>  $p
     * @return array{state: string, until: int}
     */
    public static function protectionState(array $p): array
    {
        $until = (int) ($p['paused_until'] ?? 0);
        if ($until > now()->getTimestampMs()) {
            return ['state' => 'paused', 'until' => $until];
        }

        return ['state' => ! empty($p['enabled']) ? 'on' : 'off', 'until' => 0];
    }

    /** Stacked segments from the bottom: answered by the upstream, from the cache, blocked. */
    public const SEGMENTS = [
        'upstream' => 'fill-cerulean-500',
        'cached' => 'fill-emerald-400',
        'blocked' => 'fill-rose-500',
    ];

    /**
     * Geometry of the stacked bar chart (answered below, blocked on top). With "cached" in
     * the series (PK95 DNS since it reports it) the answered part is split into upstream and
     * cache; "cached" in the result tells the view to show it in the legend.
     *
     * @param  list<array{t: int, queries: int, blocked: int, cached?: int}>  $series
     * @return array<string, mixed>
     */
    public static function chart(array $series, bool $daily, int $width = 1000, int $height = 220): array
    {
        $m = ['top' => 8, 'right' => 4, 'bottom' => 22, 'left' => 46];
        $plotW = $width - $m['left'] - $m['right'];
        $plotH = $height - $m['top'] - $m['bottom'];
        $max = max(0, ...array_map(fn ($b) => (int) $b['queries'], $series ?: [['queries' => 0]]));
        $step = self::niceStep($max, 4);
        $top = max($step, ceil($max / $step) * $step);
        $y = fn (float $v) => $m['top'] + $plotH - ($v / $top) * $plotH;
        $n = max(1, count($series));
        $slot = $plotW / $n;
        $barW = min(24, max(1.5, $slot - ($slot > 6 ? 2 : 0.5)));

        $grid = [];
        for ($v = 0; $v <= $top + 0.001; $v += $step) {
            $grid[] = ['y' => round($y($v), 1) + 0.5, 'label' => Fmt::compact($v), 'base' => $v == 0];
        }
        // a label every ~80 px; 7 days of hours get the date at midnight
        $every = max(1, (int) ceil($n / max(1, floor($plotW / 80))));
        $labels = [];
        $bars = [];
        // older PK95 DNS versions do not report the cache share: chart as before
        $hasCache = array_filter($series, fn ($b) => array_key_exists('cached', $b)) !== [];
        foreach ($series as $i => $b) {
            $at = Fmt::at($b['t']);
            $x = $m['left'] + $slot * $i;
            $week = ! $daily && $n > 48;
            if ($week ? $at->hour === 0 : $i % $every === 0) {
                $labels[] = ['x' => round($x + $slot / 2, 1), 'text' => $daily || $week ? $at->format('d.m.') : $at->format('H:i')];
            }
            // clamped so no segment can go negative: blocked ≤ queries, cached ≤ the rest
            $queries = max(0, (int) $b['queries']);
            $blocked = max(0, min($queries, (int) $b['blocked']));
            $cached = $hasCache ? max(0, min($queries - $blocked, (int) ($b['cached'] ?? 0))) : 0;
            $values = ['upstream' => $queries - $blocked - $cached, 'cached' => $cached, 'blocked' => $blocked];
            $bx = round($x + ($slot - $barW) / 2, 1);
            $segs = [];
            $base = 0;
            foreach (self::SEGMENTS as $key => $class) {
                if ($values[$key] > 0) {
                    $top = $y($base + $values[$key]);
                    $segs[] = ['class' => $class, 'x' => $bx, 'y' => round($top, 1), 'h' => round(max(1, $y($base) - $top), 1)];
                    $base += $values[$key];
                }
            }
            // the end is one real hour later (on the night the clocks go back: 02:00 bis 02:00)
            $label = $daily ? $at->format('d.m.Y') : $at->format('d.m. H:i').' bis '.Fmt::at($b['t'] + 3600000)->format('H:i');
            $bars[] = ['x' => round($x, 1), 'w' => round($slot, 1), 'bw' => round($barW, 1), 'segs' => $segs,
                'title' => $label."\n".Fmt::num($queries).' Anfragen, davon '
                    .($hasCache ? Fmt::num($cached).' aus dem Cache, ' : '').Fmt::num($blocked).' blockiert'];
        }

        return ['width' => $width, 'height' => $height, 'm' => $m, 'plotH' => $plotH, 'grid' => $grid, 'labels' => $labels, 'bars' => $bars, 'cached' => $hasCache];
    }

    /**
     * The top lists as pairs, allowed (left) and blocked (right), each at most 20. PK95 DNS 1.4.0
     * sends them (top_allowed, top_clients_allowed/blocked, top_countries_allowed/blocked); older
     * versions are derived: domains from top_domains, clients from top_clients, countries from
     * top_countries (null without countries: no country cards).
     *
     * @param  array<string, mixed>  $stats
     * @return array{domainsAllowed: list<array{name: string, count: int}>, domainsAllowedTitle: string, domainsBlocked: list<array{name: string, count: int}>,
     *     clientsAllowed: list<array{ip: string, name: string, count: int, blocked: int, allowed: int}>, clientsBlocked: list<array{ip: string, name: string, count: int, blocked: int, allowed: int}>,
     *     countriesAllowed: list<array<string, mixed>>|null, countriesBlocked: list<array<string, mixed>>|null}
     */
    public static function topLists(array $stats): array
    {
        $list = fn (string $key) => array_values(array_filter((array) ($stats[$key] ?? []), 'is_array'));
        $domain = fn (array $d) => ['name' => (string) ($d['name'] ?? ''), 'count' => (int) ($d['count'] ?? 0)];
        $client = function (array $c) {
            $count = (int) ($c['count'] ?? 0);
            $blocked = (int) ($c['blocked'] ?? 0);

            return ['ip' => (string) ($c['ip'] ?? ''), 'name' => (string) ($c['name'] ?? ''), 'count' => $count, 'blocked' => $blocked, 'allowed' => max(0, $count - $blocked)];
        };
        $sortBy = function (array $rows, string $key): array {
            usort($rows, fn ($a, $b) => $b[$key] <=> $a[$key]);

            return $rows;
        };
        $max = fn (array $rows) => array_slice($rows, 0, 20);

        $new = array_key_exists('top_allowed', $stats);
        $clients = array_map($client, $list('top_clients'));
        $out = [
            'domainsAllowed' => array_map($domain, $list($new ? 'top_allowed' : 'top_domains')),
            'domainsAllowedTitle' => $new ? 'Häufigste Domains erlaubt' : 'Häufigste Domains',
            'domainsBlocked' => array_map($domain, $list('top_blocked')),
            'clientsAllowed' => array_key_exists('top_clients_allowed', $stats)
                ? array_map($client, $list('top_clients_allowed'))
                : $max($sortBy(array_values(array_filter($clients, fn ($c) => $c['allowed'] > 0)), 'allowed')),
            'clientsBlocked' => array_key_exists('top_clients_blocked', $stats)
                ? array_map($client, $list('top_clients_blocked'))
                : $max($sortBy(array_values(array_filter($clients, fn ($c) => $c['blocked'] > 0)), 'blocked')),
            'countriesAllowed' => null,
            'countriesBlocked' => null,
        ];

        if (array_key_exists('top_countries_allowed', $stats) || array_key_exists('top_countries', $stats)) {
            // "count" includes answers the country filter ("blocked") stopped afterwards: allowed = count − blocked
            $withAllowed = fn (array $rows) => array_map(fn ($r) => $r + ['allowed' => max(0, $r['passed'] - $r['filter_blocked'])], $rows);
            $rows = $withAllowed(CountrySplit::rows($list('top_countries')));
            $allowed = array_key_exists('top_countries_allowed', $stats) ? $withAllowed(CountrySplit::rows($list('top_countries_allowed'))) : $rows;
            $blocked = array_key_exists('top_countries_blocked', $stats) ? $withAllowed(CountrySplit::rows($list('top_countries_blocked'))) : $rows;
            // allowed by count − blocked, blocked by list_blocked + blocked, each only > 0 (as PK95 DNS 1.4.0 sends them)
            $out['countriesAllowed'] = $max($sortBy(array_values(array_filter($allowed, fn ($r) => $r['allowed'] > 0)), 'allowed'));
            $out['countriesBlocked'] = $max($sortBy(array_values(array_filter($blocked, fn ($r) => $r['blocked'] > 0)), 'blocked'));
        }

        return $out;
    }

    public static function niceStep(float $max, int $ticks): float
    {
        if ($max <= 0) {
            return 1;
        }
        $raw = $max / $ticks;
        $pow = 10 ** floor(log10($raw));
        $n = $raw / $pow;

        return max(1, ($n <= 1 ? 1 : ($n <= 2 ? 2 : ($n <= 5 ? 5 : 10))) * $pow);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function protection(array $body, string $notice): void
    {
        if ($this->succeeded($this->appRequest(fn ($api) => $api->post('/api/protection', $body)))) {
            $this->dispatch('notice', kind: 'success', text: $notice);
        }
    }
}
