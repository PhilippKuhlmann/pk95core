<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 Proxy: page views per host and day as a heatmap table (host × day), 7 or 30 days,
 * one metric at a time, totals per host and per day, sortable, filtered to one host.
 * Data: Daily (GET /api/hosts/daily, PK95 Proxy 1.4.0), always without bots and without
 * the clients hidden on Proxy → Zugriffe.
 */
class Views extends Component
{
    public InstalledApp $installedApp;

    #[Url]
    public int $days = 7;

    #[Url]
    public string $metric = 'pages';

    #[Url]
    public string $host = '';

    /** 'total', 'host' or a date of the table */
    #[Url]
    public string $sort = 'total';

    #[Url]
    public string $dir = 'desc';

    public function mount(): void
    {
        $this->check();
    }

    public function updated(): void
    {
        $this->check();
    }

    public function sortBy(string $key): void
    {
        if ($this->sort === $key) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            [$this->sort, $this->dir] = [$key, $key === 'host' ? 'asc' : 'desc'];
        }
        $this->check();
    }

    public function setDays(int $days): void
    {
        $this->days = $days;
        $this->check();
    }

    public function setMetric(string $metric): void
    {
        $this->metric = $metric;
        $this->check();
    }

    public function render()
    {
        $data = Daily::load($this->installedApp, $this->days);
        $metric = $this->metric;
        $days = $data['days'];

        $rows = [];
        foreach ($data['hosts'] as $name => $h) {
            if ($this->host !== '' && $name !== $this->host) {
                continue;
            }
            $values = [];
            foreach ($days as $day) {
                $values[$day] = (int) ($h['series'][$day][$metric] ?? 0);
            }
            $rows[] = ['host' => $name, 'values' => $values, 'total' => (int) $h['total'][$metric], 'series' => $h['series']];
        }
        $sort = $this->sort;
        usort($rows, fn ($a, $b) => match ($sort) {
            'host' => strnatcasecmp($a['host'], $b['host']),
            'total' => $a['total'] <=> $b['total'] ?: strnatcasecmp($b['host'], $a['host']),
            default => ($a['values'][$sort] ?? 0) <=> ($b['values'][$sort] ?? 0) ?: $a['total'] <=> $b['total'],
        });
        if ($this->dir === 'desc') {
            $rows = array_reverse($rows);
        }

        $byDay = array_combine($days, $days) ?: [];
        $dayTotals = array_map(fn ($day) => array_sum(array_map(fn ($r) => $r['values'][$day], $rows)), $byDay);
        $estimated = array_map(fn ($day) => collect($data['hosts'])->contains(fn ($h) => $h['series'][$day]['estimated'] ?? false), $byDay);
        $hostNames = array_keys($data['hosts']);
        natcasesort($hostNames);
        if ($this->host !== '' && ! in_array($this->host, $hostNames, true)) {
            $hostNames[] = $this->host;
        }

        return view('modules.proxy.views', [
            'data' => $data,
            'dates' => $days,
            'rows' => $rows,
            'dayTotals' => $dayTotals,
            'total' => array_sum($dayTotals),
            'estimated' => $estimated,
            // heatmap scale over the cells of the hosts shown, not the totals
            'max' => max(1, ...array_map(fn ($r) => $r['values'] ? max($r['values']) : 0, $rows ?: [['values' => []]])),
            'hostOptions' => array_values(array_map(fn ($n) => ['value' => $n, 'label' => $n,
                'hint' => isset($data['hosts'][$n]) ? Fmt::num($data['hosts'][$n]['total'][$metric]) : null], $hostNames)),
        ]);
    }

    /** Values from the URL that the page does not know fall back to the defaults. */
    private function check(): void
    {
        $this->days = isset(Daily::RANGES[$this->days]) ? $this->days : 7;
        $this->metric = isset(Daily::METRICS[$this->metric]) ? $this->metric : 'pages';
        $this->dir = $this->dir === 'asc' ? 'asc' : 'desc';
        if (! in_array($this->sort, ['total', 'host'], true) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->sort) !== 1) {
            $this->sort = 'total';
        }
        $this->host = mb_substr(trim($this->host), 0, 253);
    }
}
