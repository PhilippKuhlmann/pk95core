<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Services\Apps\AppException;
use App\Services\Dashboard\AppFigures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Real page views per host and day (GET /api/hosts/daily, PK95 Proxy 1.4.0):
 * pages = GET answers with text/html without bots and hidden clients, visitors = distinct IPs,
 * requests = all requests. Days before the page counting started are estimated by the panel.
 * Bots are always left out, the clients hidden on Proxy → Zugriffe too.
 */
final class Daily
{
    public const MIN_VERSION = '1.4.0';

    public const METRICS = ['pages' => 'Seitenaufrufe', 'visitors' => 'Besucher', 'requests' => 'Zugriffe'];

    public const RANGES = [7 => '7 Tage', 30 => '30 Tage'];

    public const ESTIMATED = 'geschätzt, vor Einführung der Seitenzählung';

    /** cell background by intensity 0..5 (heatmap), readable text on every step */
    public const HEAT = [
        '',
        'bg-cerulean-50 dark:bg-cerulean-950/50',
        'bg-cerulean-100 dark:bg-cerulean-900/60',
        'bg-cerulean-200 dark:bg-cerulean-800/70',
        'bg-cerulean-300 text-gray-900 dark:bg-cerulean-700 dark:text-white',
        'bg-cerulean-500 text-white dark:bg-cerulean-500',
    ];

    /**
     * The daily figures of all hosts; cached for a minute (the hosts page polls every 20 s).
     *
     * @return array{missing: bool, error: ?string, hidden: list<string>, days: list<string>, hosts: array<string, array{total: array<string, mixed>, series: array<string, array<string, mixed>>}>, since: ?string, computed_at: ?int}
     */
    public static function load(InstalledApp $app, int $days = 7): array
    {
        $days = isset(self::RANGES[$days]) ? $days : 7;
        $hidden = AppFigures::hiddenClients($app);
        // repeated key, not "a,b": client names may contain commas
        $query = 'days='.$days.'&hide_bots=1'.implode('', array_map(fn ($c) => '&exclude_client='.rawurlencode($c), $hidden));
        $key = 'proxy.daily.'.$app->id.'.'.md5($query);

        $result = Cache::get($key);
        if (! is_array($result)) {
            $result = self::fetch($app, $query);
            if ($result['error'] === null) {
                Cache::put($key, $result, 60);
            }
        }

        return ['hidden' => $hidden] + $result;
    }

    /**
     * @return array{missing: bool, error: ?string, days: list<string>, hosts: array<string, mixed>, since: ?string, computed_at: ?int}
     */
    private static function fetch(InstalledApp $app, string $query): array
    {
        $empty = ['missing' => false, 'error' => null, 'days' => [], 'hosts' => [], 'since' => null, 'computed_at' => null];
        try {
            $response = $app->client()->get('/api/hosts/daily', $query);
        } catch (AppException $e) {
            return ['error' => $e->getMessage()] + $empty;
        }
        if ($response->status() === 404) {
            return ['missing' => true] + $empty;
        }
        if (! $response->successful()) {
            return ['error' => (string) ($response->json('error') ?? "Die App antwortet mit HTTP {$response->status()}.")] + $empty;
        }

        return self::normalize((array) $response->json()) + $empty;
    }

    /**
     * The panel's answer → days (oldest first) and per host: total and series by date.
     *
     * @param  array<mixed>  $json
     * @return array{days: list<string>, hosts: array<string, array{total: array<string, mixed>, series: array<string, array<string, mixed>>}>, since: ?string, computed_at: ?int}
     */
    public static function normalize(array $json): array
    {
        $isDate = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
        $hosts = [];
        foreach ((array) ($json['hosts'] ?? []) as $h) {
            $name = is_array($h) ? trim((string) ($h['host'] ?? '')) : '';
            if ($name === '') {
                continue;
            }
            $series = [];
            foreach ((array) ($h['series'] ?? []) as $p) {
                if (is_array($p) && $isDate($p['date'] ?? null)) {
                    $series[$p['date']] = self::point($p);
                }
            }
            $hosts[$name] = [
                'total' => is_array($h['total'] ?? null) ? self::point($h['total']) : self::sum($series),
                'series' => $series,
            ];
        }
        $days = array_values(array_filter((array) ($json['days'] ?? []), $isDate));
        if ($days === []) {
            $days = collect($hosts)->flatMap(fn ($h) => array_keys($h['series']))->unique()->sort()->values()->all();
        }
        sort($days);

        return [
            'days' => $days,
            'hosts' => $hosts,
            'since' => $isDate($json['since_page_tracking'] ?? null) ? $json['since_page_tracking'] : null,
            'computed_at' => is_numeric($json['computed_at'] ?? null) ? (int) $json['computed_at'] : null,
        ];
    }

    /**
     * Figures of a proxy host over all its domains (the panel counts by Host header). Visitors
     * are added up per domain, so one IP on two domains of the same host counts twice.
     *
     * @param  array{days: list<string>, hosts: array<string, mixed>}  $data
     * @param  list<string>  $domains
     * @return array{total: array<string, mixed>, series: array<string, array<string, mixed>>}
     */
    public static function forDomains(array $data, array $domains): array
    {
        $series = [];
        foreach ($data['days'] as $day) {
            $series[$day] = self::sum(array_map(fn ($d) => $data['hosts'][$d]['series'][$day] ?? self::point([]), $domains));
        }

        return ['total' => self::sum($series), 'series' => $series];
    }

    /**
     * Tooltip of one day: "14.11.: 100 Seitenaufrufe, 7 Besucher, 2.100 Zugriffe".
     *
     * @param  array<string, mixed>  $p
     */
    public static function tip(string $day, array $p): string
    {
        return self::label($day).': '.Fmt::num($p['pages']).' Seitenaufrufe, '.Fmt::num($p['visitors']).' Besucher, '
            .Fmt::num($p['requests']).' Zugriffe'.($p['estimated'] ? "\n".ucfirst(self::ESTIMATED) : '');
    }

    /** "14.11." */
    public static function label(string $day): string
    {
        return Carbon::parse($day)->format('d.m.');
    }

    /** "Sa" */
    public static function weekday(string $day): string
    {
        return Carbon::parse($day)->locale('de')->isoFormat('dd');
    }

    /** Heatmap class: square-root scale, so small hosts still show next to big ones. */
    public static function heat(int $value, int $max): string
    {
        if ($value <= 0 || $max <= 0) {
            return self::HEAT[0];
        }

        return self::HEAT[max(1, min(5, (int) ceil(5 * sqrt($value / $max))))];
    }

    /**
     * @param  array<mixed>  $p
     * @return array{pages: int, visitors: int, requests: int, estimated: bool}
     */
    private static function point(array $p): array
    {
        return [
            'pages' => max(0, (int) ($p['pages'] ?? 0)),
            'visitors' => max(0, (int) ($p['visitors'] ?? 0)),
            'requests' => max(0, (int) ($p['requests'] ?? 0)),
            'estimated' => (bool) ($p['estimated'] ?? false),
        ];
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $points
     * @return array{pages: int, visitors: int, requests: int, estimated: bool}
     */
    private static function sum(array $points): array
    {
        $sum = self::point([]);
        foreach ($points as $p) {
            foreach (array_keys(self::METRICS) as $k) {
                $sum[$k] += $p[$k];
            }
            $sum['estimated'] = $sum['estimated'] || $p['estimated'];
        }

        return $sum;
    }
}
