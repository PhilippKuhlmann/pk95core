<?php

namespace App\Modules\Dns;

use App\Models\InstalledApp;
use App\Modules\Concerns\TalksToApp;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 DNS: protection switch with pause, warnings (port 53, upstreams), totals, queries
 * over time (SVG bars rendered on the server) and top lists.
 */
class Overview extends Component
{
    use TalksToApp;

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

        return view('modules.dns.overview', [
            'status' => is_array($status) ? $status : null,
            'stats' => is_array($stats) ? $stats : null,
            'loadError' => $error ?? $statsError,
            'logBase' => route('apps.page', [$this->installedApp, 'log']),
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

    /**
     * Geometry of the stacked bar chart (allowed below, blocked on top).
     *
     * @param  list<array{t: int, queries: int, blocked: int}>  $series
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
        foreach ($series as $i => $b) {
            $at = Fmt::at($b['t']);
            $x = $m['left'] + $slot * $i;
            $week = ! $daily && $n > 48;
            if ($week ? $at->hour === 0 : $i % $every === 0) {
                $labels[] = ['x' => round($x + $slot / 2, 1), 'text' => $daily || $week ? $at->format('d.m.') : $at->format('H:i')];
            }
            $queries = (int) $b['queries'];
            $blocked = min($queries, (int) $b['blocked']);
            $bx = round($x + ($slot - $barW) / 2, 1);
            $segs = [];
            if ($queries - $blocked > 0) {
                $segs[] = ['class' => 'fill-cerulean-500', 'x' => $bx, 'y' => round($y($queries - $blocked), 1), 'h' => round(max(1, $plotH - ($y($queries - $blocked) - $m['top'])), 1)];
            }
            if ($blocked > 0) {
                $segs[] = ['class' => 'fill-rose-500', 'x' => $bx, 'y' => round($y($queries), 1), 'h' => round(max(1, $y($queries - $blocked) - $y($queries)), 1)];
            }
            // the end is one real hour later (on the night the clocks go back: 02:00 bis 02:00)
            $label = $daily ? $at->format('d.m.Y') : $at->format('d.m. H:i').' bis '.Fmt::at($b['t'] + 3600000)->format('H:i');
            $bars[] = ['x' => round($x, 1), 'w' => round($slot, 1), 'bw' => round($barW, 1), 'segs' => $segs,
                'title' => $label."\n".Fmt::num($queries).' Anfragen, davon '.Fmt::num($blocked).' blockiert'];
        }

        return ['width' => $width, 'height' => $height, 'm' => $m, 'plotH' => $plotH, 'grid' => $grid, 'labels' => $labels, 'bars' => $bars];
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
