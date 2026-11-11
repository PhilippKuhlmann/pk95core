<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 Proxy: access statistics (tiles, requests and data over time, world map, top lists).
 * Charts are rendered as SVG on the server, the map is drawn in the browser from the
 * panel's map data (public/modules/proxy/world.json).
 */
class Overview extends Component
{
    use TalksToApp;

    public InstalledApp $installedApp;

    #[Url]
    public string $range = '24h';

    #[Url]
    public string $host = '';

    #[Url(as: 'hide_bots')]
    public bool $hideBots = false;

    public const RANGES = ['1h' => '1 Std.', '24h' => '24 Std.', '7d' => '7 Tage', '30d' => '30 Tage'];

    public const SERIES = [
        's2xx' => ['2xx Erfolg', 'fill-cerulean-600', 'bg-cerulean-600'],
        's3xx' => ['3xx Weiterleitung', 'fill-cerulean-300', 'bg-cerulean-300'],
        's4xx' => ['4xx Client-Fehler', 'fill-amber-500', 'bg-amber-500'],
        's5xx' => ['5xx Server-Fehler', 'fill-rose-500', 'bg-rose-500'],
    ];

    public function mount(): void
    {
        if (! isset(self::RANGES[$this->range])) {
            $this->range = '24h';
        }
    }

    public function setRange(string $range): void
    {
        $this->range = isset(self::RANGES[$range]) ? $range : '24h';
    }

    public function render()
    {
        $query = ['range' => $this->range] + array_filter(['host' => $this->host, 'hide_bots' => $this->hideBots ? '1' : null]);
        [$d, $error] = $this->load('/api/stats', $query);
        [$security] = $this->load('/api/security');
        [$hosts] = $this->load('/api/hosts');
        $configured = collect(is_array($hosts) ? $hosts : [])->flatMap(fn ($h) => $h['domains'])->unique()->sort()->values()->all();
        if ($this->host !== '' && ! in_array($this->host, $configured, true)) {
            array_unshift($configured, $this->host);
        }

        return view('modules.proxy.overview', [
            'd' => is_array($d) ? $d : null,
            'security' => is_array($security) ? $security : null,
            'configured' => $configured,
            'logBase' => route('apps.page', [$this->installedApp, 'log']),
            'loadError' => $error,
        ]);
    }

    /** Log page link with the current host and bot filter. */
    public function logLink(string $base, array $params): string
    {
        if ($this->host !== '' && ! isset($params['host'])) {
            $params['host'] = $this->host;
        }
        if ($this->hideBots) {
            $params['hide_bots'] = '1';
        }

        return $base.'?'.http_build_query($params);
    }

    public static function niceStep(float $max, int $ticks): float
    {
        if ($max <= 0) {
            return 1;
        }
        $raw = $max / $ticks;
        $pow = 10 ** floor(log10($raw));
        $n = $raw / $pow;
        $nice = $n <= 1 ? 1 : ($n <= 2 ? 2 : ($n <= 5 ? 5 : 10));

        return max(1, $nice * $pow);
    }

    /**
     * End of a bucket (UTC ms). PK95 Proxy since 1.1.1 aligns buckets of 3 h and more to the
     * local wall clock and sends their real "end": on the days the clocks change such a bucket
     * is an hour shorter or longer than the nominal bucket_ms. Older versions: ts + bucket_ms.
     *
     * @param  array<string, mixed>  $b
     */
    public static function bucketEnd(array $b, int $bucketMs): int
    {
        return isset($b['end']) ? (int) $b['end'] : (int) $b['ts'] + $bucketMs;
    }

    /** Label of a time bucket ("08.11. 14:00 bis 15:00"); $end from bucketEnd(). */
    public static function bucketLabel(int $ts, int $bucketMs, ?int $end = null): string
    {
        $start = Fmt::at($ts);
        $end = Fmt::at($end ?? $ts + $bucketMs);

        return $bucketMs >= 3600000 ? $start->format('d.m. H:i').' bis '.$end->format('H:i') : $start->format('H:i').' bis '.$end->format('H:i');
    }

    /**
     * Geometry of a bar chart: axis ticks, x labels and per bucket the stacked segments.
     *
     * @param  list<array<string, mixed>>  $series
     * @param  list<string>  $keys  stacked values per bucket
     * @return array<string, mixed>
     */
    public static function bars(array $series, array $keys, int $bucketMs, int $width, int $height, int $left, int $ticks, callable $axis): array
    {
        $m = ['top' => 8, 'right' => 4, 'bottom' => 22, 'left' => $left];
        $plotW = $width - $m['left'] - $m['right'];
        $plotH = $height - $m['top'] - $m['bottom'];
        $max = 0;
        foreach ($series as $b) {
            $max = max($max, array_sum(array_map(fn ($k) => $b[$k] ?? 0, $keys)));
        }
        $step = self::niceStep($max, $ticks);
        $top = max($step, ceil($max / $step) * $step);
        $y = fn ($v) => $m['top'] + $plotH - ($v / $top) * $plotH;
        $n = max(1, count($series));
        $slot = $plotW / $n;
        $barW = min(24, max(2, $slot - ($slot > 6 ? 3 : 1)));

        $grid = [];
        for ($v = 0; $v <= $top + 0.001; $v += $step) {
            $grid[] = ['y' => round($y($v), 1) + 0.5, 'label' => $axis($v), 'base' => $v == 0];
        }
        $every = max(1, (int) ceil($n / max(1, floor($plotW / 70))));
        $labels = [];
        $bars = [];
        foreach ($series as $i => $b) {
            if ($i % $every === 0) {
                $labels[] = ['x' => round($m['left'] + $slot * $i + $slot / 2, 1),
                    'text' => $bucketMs >= 3 * 3600000 ? Fmt::at($b['ts'])->format('d.m.') : Fmt::time($b['ts'])];
            }
            $x = $m['left'] + $slot * $i + ($slot - $barW) / 2;
            $acc = 0;
            $segs = [];
            foreach ($keys as $k) {
                $v = $b[$k] ?? 0;
                if ($v <= 0) {
                    continue;
                }
                $y0 = $y($acc);
                $y1 = $y($acc + $v);
                $acc += $v;
                $segs[] = ['key' => $k, 'x' => round($x, 1), 'y' => round($y1, 1), 'w' => round($barW, 1), 'h' => round(max(1, $y0 - $y1 - ($segs && $y0 - $y1 > 3 ? 2 : 0)), 1)];
            }
            $bars[] = ['band' => ['x' => round($m['left'] + $slot * $i, 1), 'w' => round($slot, 1)], 'segs' => $segs, 'b' => $b,
                'end' => self::bucketEnd($b, $bucketMs)];
        }

        return ['width' => $width, 'height' => $height, 'm' => $m, 'plotH' => $plotH, 'grid' => $grid, 'labels' => $labels, 'bars' => $bars];
    }
}
