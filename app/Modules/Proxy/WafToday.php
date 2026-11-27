<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Services\Apps\AppException;
use Illuminate\Support\Facades\Cache;

/**
 * The web firewall of today for the Proxy overview: blocked and only detected hits (from
 * /api/waf/stats) and the IP bans of today (from the /api/security the overview loads anyway).
 * Optional like the dashboard tile: a Proxy without WAF gives null (no card), a failing call
 * leaves the figures empty.
 */
class WafToday
{
    /** seconds, as the dashboard figures */
    public const TTL = 45;

    /**
     * @return array{active: bool, blocked: ?int, detected: ?int}|null
     */
    public static function load(InstalledApp $app): ?array
    {
        $status = Waf::status($app);
        if ($status === null) {
            return null;
        }

        return Cache::remember('proxy.waf.today.'.$app->id, self::TTL, function () use ($app, $status) {
            try {
                $response = $app->client()->get('/api/waf/stats', ['from' => Waf::from(0), 'to' => now()->getTimestampMs()]);
                $stats = $response->successful() ? (array) $response->json() : [];
            } catch (AppException) {
                $stats = [];
            }
            // hits contain the blocks: „nur erkannt“ is the rest
            $hits = isset($stats['hits']) ? (int) $stats['hits'] : null;
            $blocks = $hits === null ? null : (int) ($stats['blocks'] ?? 0);

            return [
                'active' => collect($status['hosts'])->contains(fn ($h) => ($h['mode'] ?? 'off') !== 'off'),
                'blocked' => $blocks,
                'detected' => $hits === null ? null : max(0, $hits - $blocks),
            ];
        });
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

    /** Link to a tab of the page Web-Firewall. */
    public static function url(InstalledApp $app, string $tab, array $filters = []): string
    {
        return route('apps.page', [$app, 'waf']).'?'.http_build_query(['tab' => $tab] + array_filter($filters, fn ($v) => $v !== '' && $v !== null));
    }
}
