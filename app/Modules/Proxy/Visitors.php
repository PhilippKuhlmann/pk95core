<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Services\Apps\AppException;
use App\Services\Dashboard\AppFigures;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * Who visited a host on one day (GET /api/hosts/visitors, PK95 Proxy 1.5.0): per IP the
 * clients, page views, requests, first and last time and the requested paths. Always
 * without bots and without the clients hidden on Proxy → Zugriffe. Names of LAN addresses
 * come from PK95 DNS (client name or reverse DNS), the own public IP is marked.
 */
final class Visitors
{
    public const MIN_VERSION = '1.5.0';

    /** Paths that vulnerability scanners try; a hint only, never a verdict. */
    public const SCANNER_PATHS = [
        '/wp-login.php', '/wp-admin', '/wp-includes', '/xmlrpc.php', '/.env', '/.git', '/.aws', '/.ssh', '/phpmyadmin', '/pma',
        '/cgi-bin', '/vendor/phpunit', '/actuator', '/server-status', '/hnap1', '/boaform', '/owa', '/autodiscover',
        '/admin.php', '/setup.cgi', '/shell', '/config.json', '/.ds_store', '/solr', '/console',
    ];

    /**
     * @return array{missing: bool, error: ?string, hidden: list<string>, estimated: bool, computed_at: ?int, visitors: list<array<string, mixed>>}
     */
    public static function load(InstalledApp $app, string $host, string $date, bool $pagesOnly = true): array
    {
        $hidden = AppFigures::hiddenClients($app);
        $query = http_build_query(['host' => $host, 'date' => $date, 'hide_bots' => 1, 'pages_only' => $pagesOnly ? 1 : 0], '', '&', PHP_QUERY_RFC3986)
            .implode('', array_map(fn ($c) => '&exclude_client='.rawurlencode($c), $hidden));
        $key = 'proxy.visitors.'.$app->id.'.'.md5($query);

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
     * @return array{missing: bool, error: ?string, estimated: bool, computed_at: ?int, visitors: list<array<string, mixed>>}
     */
    private static function fetch(InstalledApp $app, string $query): array
    {
        $empty = ['missing' => false, 'error' => null, 'estimated' => false, 'computed_at' => null, 'visitors' => [], 'total_visitors' => null, 'visitors_truncated' => false, 'host_type' => null];
        try {
            $response = $app->client()->get('/api/hosts/visitors', $query);
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
     * The panel's answer → visitors with most page views first.
     *
     * @param  array<mixed>  $json
     * @return array{estimated: bool, computed_at: ?int, visitors: list<array<string, mixed>>}
     */
    public static function normalize(array $json): array
    {
        $visitors = [];
        foreach ((array) ($json['visitors'] ?? []) as $v) {
            $ip = is_array($v) ? trim((string) ($v['ip'] ?? '')) : '';
            if ($ip === '') {
                continue;
            }
            $paths = [];
            foreach ((array) ($v['paths'] ?? []) as $p) {
                if (is_array($p)) {
                    $paths[] = [
                        'ts' => (int) ($p['ts'] ?? 0), 'method' => (string) ($p['method'] ?? ''), 'path' => (string) ($p['path'] ?? ''),
                        'status' => (int) ($p['status'] ?? 0), 'client' => (string) ($p['client'] ?? ''), 'referer' => (string) ($p['referer'] ?? ''),
                    ];
                }
            }
            usort($paths, fn ($a, $b) => $a['ts'] <=> $b['ts']);
            $visitors[] = [
                'ip' => $ip,
                'country' => is_string($v['country'] ?? null) ? $v['country'] : '',
                'clients' => array_values(array_filter(array_map(fn ($c) => trim((string) $c), (array) ($v['clients'] ?? [])))),
                'is_bot' => (bool) ($v['is_bot'] ?? false),
                'pages' => max(0, (int) ($v['pages'] ?? 0)),
                'requests' => max(0, (int) ($v['requests'] ?? 0)),
                'first' => (int) ($v['first'] ?? 0),
                'last' => (int) ($v['last'] ?? 0),
                'paths' => $paths,
                'paths_truncated' => (bool) ($v['paths_truncated'] ?? false),
            ];
        }
        usort($visitors, fn ($a, $b) => [$b['pages'], $b['requests']] <=> [$a['pages'], $a['requests']]);

        return [
            'estimated' => (bool) ($json['estimated'] ?? false),
            'computed_at' => is_numeric($json['computed_at'] ?? null) ? (int) $json['computed_at'] : null,
            'visitors' => $visitors,
            // more than the panel lists (it sends the top 200)
            'total_visitors' => is_numeric($json['total_visitors'] ?? null) ? (int) $json['total_visitors'] : null,
            'visitors_truncated' => (bool) ($json['visitors_truncated'] ?? false),
            'host_type' => in_array($json['host_type'] ?? null, Daily::HOST_TYPES, true) ? $json['host_type'] : null,
        ];
    }

    /** Private, loopback, link-local or otherwise not public: an address of the own network. */
    public static function isLan(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** The own public address (automation setting, or the last detected one; never fetched here). */
    public static function ownPublicIp(): ?string
    {
        $ip = trim((string) (Settings::get('automation_public_ip') ?: Cache::get('automation.public_ip', '')));

        return $ip !== '' ? $ip : null;
    }

    /**
     * Names of LAN addresses from PK95 DNS: the client name, otherwise the reverse DNS name.
     *
     * @param  list<string>  $ips
     * @return array<string, string> ip → name
     */
    public static function names(array $ips): array
    {
        $lan = array_values(array_filter($ips, fn ($ip) => self::isLan($ip)));
        $dns = $lan ? InstalledApp::query()->where('app_id', 'dns')->where('status', InstalledApp::RUNNING)->first() : null;
        if ($dns === null) {
            return [];
        }
        $map = Cache::remember('proxy.visitors.dns-names.'.$dns->id, 60, fn () => self::dnsNames($dns));

        return array_intersect_key($map, array_flip($lan));
    }

    /** @return array<string, string> */
    private static function dnsNames(InstalledApp $dns): array
    {
        try {
            $response = $dns->client()->get('/api/clients');
        } catch (AppException) {
            return [];
        }
        if (! $response->successful()) {
            return [];
        }
        $map = [];
        foreach ((array) $response->json('clients') as $c) {
            foreach ((array) ($c['ids'] ?? []) as $id) {
                if (is_string($id) && filter_var($id, FILTER_VALIDATE_IP) && trim((string) ($c['name'] ?? '')) !== '') {
                    $map[$id] = trim((string) $c['name']);
                }
            }
        }
        foreach ((array) $response->json('recent') as $r) {
            $ip = (string) ($r['ip'] ?? '');
            $name = trim((string) ($r['name'] ?? '')) ?: rtrim(trim((string) ($r['hostname'] ?? '')), '.');
            if ($ip !== '' && $name !== '' && ! isset($map[$ip])) {
                $map[$ip] = $name;
            }
        }

        return $map;
    }

    /**
     * Observations that help to judge a visitor, no verdicts.
     *
     * @param  array<string, mixed>  $v
     * @return list<string>
     */
    public static function hints(array $v): array
    {
        $hints = [];
        // a page with its assets is often 20 to 60 requests: only a lot without (almost) any page stands out
        if ($v['pages'] <= 2 && $v['requests'] >= 50) {
            $hints[] = 'Viele Zugriffe, wenige Seiten';
        }
        $statuses = array_column($v['paths'], 'status');
        if ($statuses && min($statuses) >= 400) {
            $hints[] = 'Nur Fehlerstatus';
        }
        foreach ($v['paths'] as $p) {
            $path = strtolower(strtok($p['path'], '?') ?: '');
            foreach (self::SCANNER_PATHS as $scan) {
                if ($path === $scan || str_starts_with($path, $scan.'/') || (str_contains($scan, '.') && str_starts_with($path, $scan))) {
                    $hints[] = 'Typische Scanner-Pfade';
                    break 2;
                }
            }
        }
        if ($v['is_bot']) {
            $hints[] = 'Als Bot erkannt';
        }

        return $hints;
    }
}
