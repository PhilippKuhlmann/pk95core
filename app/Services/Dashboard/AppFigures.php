<?php

namespace App\Services\Dashboard;

use App\Models\InstalledApp;
use App\Modules\Proxy\AccessLog;
use App\Modules\Uptime\Bars;
use App\Services\Apps\AppException;
use App\Support\Settings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Figures of the last 24 h per app for the dashboard, from the apps' own APIs. Each app is
 * read at most every TTL seconds (the tiles and the problem list share the cached result), and
 * a failing app only gives {ok: false, error} for its own tile.
 */
class AppFigures
{
    public const TTL = 45;

    /** Certificates that expire within this many days are a problem. */
    public const CERT_WARN_DAYS = 14;

    /** The client filter (exclude_client) of PK95 Proxy, also on /api/stats. */
    public const PROXY_CLIENT_FILTER = '1.3.0';

    /**
     * @return array<string, mixed> {ok: true, ...} or {ok: false, error: string}
     */
    public function for(InstalledApp $app): array
    {
        if ($app->status !== InstalledApp::RUNNING) {
            return ['ok' => false, 'error' => 'Die App läuft gerade nicht.'];
        }
        $method = match ($app->app_id) {
            'dns' => 'dns', 'proxy' => 'proxy', 'uptime' => 'uptime', 'proxapps' => 'proxapps',
            default => null,
        };
        if ($method === null) {
            return ['ok' => false, 'error' => 'Für diese App gibt es keine Kennzahlen.'];
        }
        $key = "dashboard.figures.{$app->app_id}.".md5((string) json_encode(self::hiddenClients($app)));

        return Cache::remember($key, self::TTL, function () use ($app, $method) {
            try {
                return $this->{$method}($app);
            } catch (AppException $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            } catch (Throwable $e) {
                report($e);

                return ['ok' => false, 'error' => 'Die Antwort der App war nicht lesbar.'];
            }
        });
    }

    /**
     * Clients hidden on Proxy → Zugriffe, left out of the Proxy figures too (needs a Proxy that
     * knows exclude_client; older ones would ignore it and count everything).
     *
     * @return list<string>
     */
    public static function hiddenClients(InstalledApp $app): array
    {
        if ($app->app_id !== 'proxy' || ! $app->version || version_compare(ltrim($app->version, 'v'), self::PROXY_CLIENT_FILTER, '<')) {
            return [];
        }

        return array_values(array_filter((array) Settings::get(AccessLog::HIDDEN_SETTING), 'is_string'));
    }

    // ------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function dns(InstalledApp $app): array
    {
        $stats = self::json($app->client()->get('/api/stats', ['hours' => 24]));
        $t = (array) ($stats['totals'] ?? []);
        $queries = (int) ($t['queries'] ?? 0);

        return [
            'ok' => true,
            'queries' => $queries,
            'blockedPct' => (float) ($t['blocked_pct'] ?? ($queries ? ($t['blocked'] ?? 0) / $queries * 100 : 0)),
            'cachedPct' => $queries ? (int) ($t['cached'] ?? 0) / $queries * 100 : 0.0,
            'avgMs' => isset($t['avg_ms']) ? (float) $t['avg_ms'] : null,
            'series' => array_values((array) ($stats['series'] ?? [])),
            'daily' => ($stats['granularity'] ?? 'hour') === 'day',
        ];
    }

    /** @return array<string, mixed> */
    private function proxy(InstalledApp $app): array
    {
        $client = $app->client();
        $hidden = self::hiddenClients($app);
        $query = 'range=24h'.implode('', array_map(fn ($c) => '&exclude_client='.rawurlencode($c), $hidden));
        $stats = self::json($client->get('/api/stats', $query));
        $requests = (int) ($stats['requests'] ?? 0);

        // the rest is a bonus: a page that fails leaves its figure empty
        $optional = function (string $path) use ($client): ?array {
            try {
                $response = $client->get($path);

                return $response->successful() ? (array) $response->json() : null;
            } catch (AppException) {
                return null;
            }
        };
        $security = $optional('/api/security');
        $hosts = collect($optional('/api/hosts') ?? [])->keyBy('id');
        $down = collect($optional('/api/hosts/checks') ?? [])
            ->filter(fn ($c) => ($c['upstream_known'] ?? false) && ! ($c['upstream_up'] ?? true) && ($hosts[$c['host_id'] ?? 0]['enabled'] ?? false))
            ->map(fn ($c) => ['domain' => (string) ($hosts[$c['host_id']]['domains'][0] ?? '?'), 'error' => (string) ($c['upstream_error'] ?? '')])
            ->values()->all();
        $certs = $optional('/api/certificates') ?? [];
        $limit = now()->getTimestamp() + self::CERT_WARN_DAYS * 86400;
        $expiring = collect([...($certs['hosts'] ?? []), ...($certs['custom'] ?? [])])
            ->filter(fn ($c) => ($c['enabled'] ?? true) && ($c['status'] ?? 'ok') === 'ok' && (int) ($c['not_after'] ?? 0) > 0 && (int) $c['not_after'] < $limit)
            ->map(fn ($c) => ['domain' => (string) ($c['domain'] ?? $c['name'] ?? '?'), 'days' => (int) floor(((int) $c['not_after'] - now()->getTimestamp()) / 86400)])
            ->sortBy('days')->values()->all();
        $updates = $optional('/api/updates');

        return [
            'ok' => true,
            'requests' => $requests,
            'errorPct' => $requests ? (int) ($stats['errors_5xx'] ?? 0) / $requests * 100 : 0.0,
            'bytes' => (int) ($stats['bytes'] ?? 0) + (int) ($stats['bytes_in'] ?? 0),
            'bans' => isset($security['bans_total']) ? (int) $security['bans_total'] : null,
            'hidden' => $hidden,
            'series' => array_values((array) ($stats['series'] ?? [])),
            'bucketMs' => (int) ($stats['bucket_ms'] ?? 3600000),
            'downHosts' => $down,
            'expiring' => $expiring,
            'vulns' => is_array($updates['vulns']['vulns'] ?? null) ? count($updates['vulns']['vulns']) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function uptime(InstalledApp $app): array
    {
        $monitors = collect(self::json($app->client()->get('/api/monitors')));
        $active = $monitors->where('status', '!=', 'paused');
        $withValue = $active->filter(fn ($m) => is_numeric($m['uptime24h'] ?? null));
        $outages = $monitors->sum(fn ($m) => is_array($m['bars24h'] ?? null) ? self::outages($m['bars24h']) : 0);
        $hasBars = $monitors->contains(fn ($m) => is_array($m['bars24h'] ?? null));
        // strips: failing monitors first, then by name
        $strips = $monitors->filter(fn ($m) => Bars::strip($m) !== null)
            ->sortBy(fn ($m) => [($m['status'] ?? '') === 'down' ? 0 : (($m['status'] ?? '') === 'warn' ? 1 : 2), mb_strtolower((string) ($m['name'] ?? ''))])
            ->take(8)->map(fn ($m) => ['name' => (string) ($m['name'] ?? '?'), 'status' => (string) ($m['status'] ?? ''), 'strip' => Bars::strip($m)])
            ->values()->all();

        return [
            'ok' => true,
            'total' => $monitors->count(),
            'up' => $monitors->where('status', 'up')->count(),
            'down' => $monitors->where('status', 'down')->count(),
            'warn' => $monitors->where('status', 'warn')->count(),
            'paused' => $monitors->where('status', 'paused')->count(),
            'availability' => $withValue->isNotEmpty() ? (float) $withValue->avg(fn ($m) => (float) $m['uptime24h']) : null,
            'outages' => $hasBars ? $outages : null,
            'downMonitors' => $monitors->where('status', 'down')->pluck('name')->map(fn ($n) => (string) $n)->values()->all(),
            'strips' => $strips,
        ];
    }

    /** @return array<string, mixed> */
    private function proxapps(InstalledApp $app): array
    {
        $answer = self::json($app->client()->post('/api/bridge/dashboard', ['mount' => [], 'query' => [], 'errors' => []]));
        $view = (array) ($answer['view'] ?? []);
        $counts = array_map('intval', (array) ($view['counts'] ?? []));

        return [
            'ok' => true,
            'deployed' => $counts['success'] ?? 0,
            'active' => ($counts['queued'] ?? 0) + ($counts['running'] ?? 0),
            'failed' => $counts['failed'] ?? 0,
            'connections' => count((array) ($view['connections'] ?? [])),
        ];
    }

    /**
     * @return array<mixed>
     */
    private static function json(Response $response): array
    {
        if (! $response->successful()) {
            throw new AppException((string) ($response->json('error') ?? "Die App antwortet mit HTTP {$response->status()}."));
        }

        return (array) $response->json();
    }

    /**
     * Failed sections in a row count as one outage (like the label of Uptime\Bars).
     *
     * @param  array<mixed>  $bars
     */
    private static function outages(array $bars): int
    {
        $count = 0;
        $in = false;
        foreach ($bars as $bar) {
            $down = is_array($bar) && (int) ($bar['down'] ?? 0) > 0;
            if ($down && ! $in) {
                $count++;
            }
            $in = $down;
        }

        return $count;
    }
}
