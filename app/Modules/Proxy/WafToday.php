<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Services\Apps\AppException;
use Illuminate\Support\Facades\Cache;

/**
 * The web firewall of today for the Proxy overview: blocked and only detected hits, the last
 * blocks, the top rules and hosts that only detect. Optional like the dashboard tile: a Proxy
 * without WAF gives null (no card), a failing call leaves its part empty.
 */
class WafToday
{
    /** seconds, as the dashboard figures */
    public const TTL = 45;

    /**
     * @return array{active: bool, blocked: ?int, detected: ?int, recent: list<array<string, mixed>>, rules: list<array<string, mixed>>, detectHosts: int, detectHits: int}|null
     */
    public static function load(InstalledApp $app): ?array
    {
        $status = Waf::status($app);
        if ($status === null) {
            return null;
        }

        return Cache::remember('proxy.waf.today.'.$app->id, self::TTL, function () use ($app, $status) {
            $get = function (string $path, array $query) use ($app): ?array {
                try {
                    $response = $app->client()->get($path, $query);
                } catch (AppException) {
                    return null;
                }

                return $response->successful() ? (array) $response->json() : null;
            };
            $stats = $get('/api/waf/stats', ['from' => Waf::from(0), 'to' => now()->getTimestampMs()]);
            $events = $get('/api/waf/events', ['action' => 'block', 'limit' => 5]);

            // hosts that only detect and had hits today: worth a look before switching them to block
            $detect = collect($status['hosts'])->where('mode', 'detect')->pluck('host_id')->map(fn ($id) => (int) $id)->all();
            $detectCounts = collect((array) ($stats['top_hosts'] ?? []))->filter(fn ($h) => in_array((int) ($h['host_id'] ?? 0), $detect, true) && (int) ($h['count'] ?? 0) > 0);

            $hits = isset($stats['hits']) ? (int) $stats['hits'] : null;
            $blocks = isset($stats['hits']) ? (int) ($stats['blocks'] ?? 0) : null;

            return [
                'active' => collect($status['hosts'])->contains(fn ($h) => ($h['mode'] ?? 'off') !== 'off'),
                'blocked' => $blocks,
                'detected' => $hits === null ? null : max(0, $hits - (int) $blocks),
                'recent' => array_map(fn (array $e) => self::recent($e), array_values(array_filter((array) ($events['items'] ?? $events ?? []), 'is_array'))),
                'rules' => array_slice(array_values(array_filter((array) ($stats['top_rules'] ?? []), 'is_array')), 0, 3),
                'detectHosts' => $detectCounts->count(),
                'detectHits' => (int) $detectCounts->sum('count'),
            ];
        });
    }

    /**
     * A block in short: the rule that hit (not the one that only counts the score).
     *
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    private static function recent(array $e): array
    {
        $rule = collect((array) ($e['rules'] ?? []))->first(fn ($r) => is_array($r) && Waf::excludable($r['id'] ?? ''))
            ?? (array) (($e['rules'] ?? [])[0] ?? []);

        return ['ts' => (int) ($e['ts'] ?? 0), 'host_id' => (int) ($e['host_id'] ?? 0), 'domain' => (string) ($e['domain'] ?? ''),
            'country' => (string) ($e['country'] ?? ''), 'path' => (string) ($e['path'] ?? ''),
            'rule' => (string) ($rule['id'] ?? ''), 'msg' => (string) ($rule['msg'] ?? '')];
    }

    /** IP bans of today from /api/security (the overview loads it anyway). */
    public static function bansToday(?array $security): ?int
    {
        if (! is_array($security['bans'] ?? null)) {
            return null;
        }
        $midnight = Waf::from(0);

        return count(array_filter($security['bans'], fn ($b) => (int) ($b['created_at'] ?? 0) >= $midnight));
    }

    /** Link into the log tab of the page Web-Firewall. */
    public static function logUrl(InstalledApp $app, array $filters): string
    {
        return route('apps.page', [$app, 'waf']).'?'.http_build_query(['tab' => 'protokoll'] + array_filter($filters, fn ($v) => $v !== '' && $v !== null));
    }
}
