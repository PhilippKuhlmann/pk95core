<?php

namespace App\Services\Dashboard;

use App\Models\InstalledApp;
use App\Modules\Ad\AdTime;
use App\Modules\Dns\CountrySplit as DnsCountrySplit;
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
 * a failing app only gives {ok: false, error} for its own tile. An app Core has no figures for
 * gives {ok: true, none: true}: nothing to show, and nothing wrong.
 *
 * "warnings" (optional, any app): [{text, page}] for "Probleme & Warnungen", page = module page.
 */
class AppFigures
{
    public const TTL = 45;

    /** Certificates that expire within this many days are a problem. */
    public const CERT_WARN_DAYS = 14;

    /** The client filter (exclude_client) of PK95 Proxy, also on /api/stats. */
    public const PROXY_CLIENT_FILTER = '1.3.0';

    /**
     * @return array<string, mixed> {ok: true, ...}, {ok: true, none: true} or {ok: false, error: string}
     */
    public function for(InstalledApp $app): array
    {
        if ($app->status !== InstalledApp::RUNNING) {
            return ['ok' => false, 'error' => 'Die App läuft gerade nicht.'];
        }
        $method = match ($app->app_id) {
            'dns' => 'dns', 'proxy' => 'proxy', 'uptime' => 'uptime', 'proxapps' => 'proxapps', 'ad' => 'ad',
            default => null,
        };
        if ($method === null) {
            return ['ok' => true, 'none' => true];
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
            'countries' => $this->dnsCountries($app, $stats),
        ];
    }

    /**
     * Countries of the answers (PK95 DNS 1.2.0, split into let through and blocked since 1.3.0)
     * for the dashboard card; null before 1.2.0 (no top_countries).
     *
     * @param  array<mixed>  $stats
     * @return array{rows: list<array<string, mixed>>, split: bool, reached: int, unknownBlocked: int, available: bool}|null
     */
    private function dnsCountries(InstalledApp $app, array $stats): ?array
    {
        if (! array_key_exists('top_countries', $stats)) {
            return null;
        }
        $top = array_values((array) $stats['top_countries']);
        // the GeoIP database may still be loading; a failing /api/geo hides nothing
        $available = true;
        try {
            $geo = $app->client()->get('/api/geo');
            $available = ! $geo->successful() || ($geo->json('available') ?? true) !== false;
        } catch (AppException) {
            // keep the countries
        }

        return [
            'rows' => DnsCountrySplit::rows($top),
            'split' => DnsCountrySplit::hasSplit($top),
            'reached' => count($top),
            'unknownBlocked' => (int) ($stats['totals']['unknown_blocked'] ?? 0),
            'available' => $available,
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

    /** names of the Samba services in /api/status */
    private const AD_SERVICES = ['ldap' => 'LDAP', 'kerberos' => 'Kerberos', 'smb' => 'SMB', 'dns' => 'DNS'];

    /**
     * PK95 AD: the counts of /api/domain, a status line from /api/status and /api/domain (Samba
     * and its services, NTP, the DC's resolver, Unix IDs) and the locked accounts of the user list.
     * Every check that fails is a warning with the app's own message.
     *
     * @return array<string, mixed>
     */
    private function ad(InstalledApp $app): array
    {
        $client = $app->client();
        $status = self::json($client->get('/api/status'));
        $state = (string) ($status['state'] ?? '');
        if ($state !== 'ready') {
            $text = $state === 'provisioning' ? 'PK95 AD: Die Domäne wird gerade eingerichtet.' : 'PK95 AD: Domäne noch nicht eingerichtet';

            return ['ok' => true, 'ready' => false, 'state' => $state, 'warnings' => [['text' => $text, 'page' => 'domain']]];
        }
        $domain = self::json($client->get('/api/domain'));
        $readAt = now()->getTimestampMs();
        $counts = (array) ($domain['counts'] ?? []);

        $checks = [];
        $samba = (array) ($status['samba'] ?? []);
        $sambaState = (string) ($samba['state'] ?? 'running');
        $down = array_values(array_map(fn ($k) => self::AD_SERVICES[$k] ?? strtoupper((string) $k),
            array_keys(array_filter((array) ($samba['services'] ?? []), fn ($up) => ! $up))));
        $checks['samba'] = match (true) {
            // starting, or stopped for a backup: on its way, not a problem
            in_array($sambaState, ['starting', 'backup'], true) => ['label' => 'Samba', 'ok' => true, 'message' => ''],
            $sambaState !== 'running' => ['label' => 'Samba', 'ok' => false,
                'message' => 'Samba läuft nicht'.($sambaState === 'restarting' ? ' und wird neu gestartet' : '').(($samba['last_exit'] ?? '') !== '' ? ': '.$samba['last_exit'] : '.')],
            $down !== [] => ['label' => 'Samba', 'ok' => false,
                'message' => (count($down) === 1 ? 'Der Dienst '.$down[0].' antwortet nicht.' : 'Die Dienste '.implode(', ', $down).' antworten nicht.')],
            default => ['label' => 'Samba', 'ok' => true, 'message' => ''],
        };
        $ntp = (array) ($domain['ntp'] ?? $status['ntp'] ?? []);
        // the clock (PK95 AD 1.4.0): compact "ntp.time"; older versions have none
        $time = AdTime::normalize($ntp['time'] ?? null, $readAt);
        if ($ntp !== []) {
            $checks['ntp'] = match (true) {
                ! ($ntp['available'] ?? true) => ['label' => 'NTP', 'ok' => false, 'tone' => 'warn', 'message' => (string) (($ntp['message'] ?? '') ?: 'NTP ist nicht verfügbar.')],
                in_array($time['status'] ?? null, ['warn', 'error'], true) => ['label' => 'NTP', 'ok' => false, 'tone' => AdTime::tone($time['status']),
                    'message' => $time['message'] !== '' ? $time['message'] : 'Die Uhr weicht ab: '.AdTime::line($time)],
                default => ['label' => 'NTP', 'ok' => true, 'message' => ''],
            };
        }
        foreach (['resolver' => 'DNS-Auflösung', 'idmap' => 'Unix-IDs'] as $key => $label) {
            if (is_array($domain[$key] ?? null)) {
                $checks[$key] = ['label' => $label, 'ok' => (bool) ($domain[$key]['ok'] ?? true), 'message' => (string) (($domain[$key]['message'] ?? '') ?: "{$label}: Problem.")];
            }
        }
        $checks = array_map(fn ($c) => $c + ['tone' => $c['ok'] ? 'ok' : 'warn'], $checks);

        // locked accounts from the user list (at most 2000 items): a bonus, empty when it fails
        $locked = null;
        try {
            $users = $client->get('/api/users');
            if ($users->successful()) {
                $locked = collect((array) $users->json('items'))->where('locked', true)->count();
            }
        } catch (AppException) {
            // shown as "–"
        }

        return [
            'ok' => true,
            'ready' => true,
            'realm' => (string) ($domain['realm'] ?? $status['domain']['realm'] ?? ''),
            'users' => (int) ($counts['users'] ?? 0),
            'groups' => (int) ($counts['groups'] ?? 0),
            'computers' => (int) ($counts['computers'] ?? 0),
            'gpos' => (int) ($counts['gpos'] ?? 0),
            'locked' => $locked,
            'time' => $time,
            'checks' => array_values($checks),
            'warnings' => array_values(array_map(fn ($c) => ['text' => 'PK95 AD: '.$c['message'], 'page' => 'domain', 'tone' => $c['tone']],
                array_filter($checks, fn ($c) => ! $c['ok']))),
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
