<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Modules\Concerns\LoadsLazily;
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
    use LoadsLazily;

    public const PLACEHOLDER = [['header', 'Aufrufe', 1, 'sub' => 1], ['filters', 2], ['table', 10, 6]];

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

    /** the visitors dialog: host and day of the clicked number, null = closed */
    public ?string $visitHost = null;

    public ?string $visitDate = null;

    /** all requests instead of page views only */
    public bool $visitAll = false;

    public function showVisitors(string $host, string $date): void
    {
        // an empty host is valid: the requests without Host header
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }
        [$this->visitHost, $this->visitDate, $this->visitAll] = [mb_substr(trim($host), 0, 253), $date, false];
    }

    public function closeVisitors(): void
    {
        [$this->visitHost, $this->visitDate] = [null, null];
    }

    /** "Client ausblenden" in the dialog: into the remembered list of Proxy → Zugriffe. */
    public function hideClient(string $name): void
    {
        $error = AccessLog::hideRemembered($name);
        $this->dispatch('notice', kind: $error ? 'error' : 'success',
            text: $error ?? "„{$name}“ ist ausgeblendet, auch in den Zugriffen und Aufrufen.");
    }

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
            $rows[] = ['host' => (string) $name, 'type' => $h['type'] ?? 'configured', 'values' => $values, 'total' => (int) $h['total'][$metric], 'series' => $h['series']];
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
        // direct IP, no Host header and unknown names form their own group at the end
        $rows = [...array_filter($rows, fn ($r) => $r['type'] === 'configured'), ...array_filter($rows, fn ($r) => $r['type'] !== 'configured')];

        $byDay = array_combine($days, $days) ?: [];
        $dayTotals = array_map(fn ($day) => array_sum(array_map(fn ($r) => $r['values'][$day], $rows)), $byDay);
        $estimated = array_map(fn ($day) => collect($data['hosts'])->contains(fn ($h) => $h['series'][$day]['estimated'] ?? false), $byDay);
        $hostNames = array_values(array_filter(array_map('strval', array_keys($data['hosts'])), fn ($n) => $n !== ''));
        natcasesort($hostNames);
        if ($this->host !== '' && ! in_array($this->host, $hostNames, true)) {
            $hostNames[] = $this->host;
        }

        $visit = null;
        if ($this->visitHost !== null && $this->visitDate !== null) {
            $visit = Visitors::load($this->installedApp, $this->visitHost, $this->visitDate, ! $this->visitAll);
            $visit['names'] = Visitors::names(array_column($visit['visitors'], 'ip'));
            $visit['own'] = Visitors::ownPublicIp();
            $visit['host_type'] ??= $data['hosts'][$this->visitHost]['type'] ?? null;
            // the panel may not know the day as estimated yet; the table does
            $visit['estimated'] = $visit['estimated'] || ($data['hosts'][$this->visitHost]['series'][$this->visitDate]['estimated'] ?? false);
        }

        return view('modules.proxy.views', [
            'visit' => $visit,
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
