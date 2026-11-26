<?php

namespace App\Modules\Proxy;

use App\Http\Support\Format;
use App\Models\InstalledApp;
use App\Services\Apps\AppException;

/**
 * The web firewall of PK95 Proxy 1.8.0 (Coraza + OWASP CRS), shared by the host form, the
 * page „Web-Firewall“ and the dashboard. An older Proxy answers 404 on every WAF route, a
 * Proxy whose Caddy has no WAF module reports so in the status: then Core shows nothing of it.
 */
class Waf
{
    public const MODES = ['off' => 'Aus', 'detect' => 'Nur erkennen', 'block' => 'Blockieren'];

    public const MODE_HINTS = [
        'off' => 'Keine Prüfung.',
        'detect' => 'Prüft jede Anfrage und protokolliert Treffer, lässt aber alles durch. Gut zum Einstieg.',
        'block' => 'Weist Anfragen ab, deren Punktzahl die Schwelle erreicht (403).',
    ];

    public const PARANOIA = [1 => 'Normal (Stufe 1)', 2 => 'Streng (Stufe 2)'];

    /** action of a hit as /api/waf/events names it */
    public const ACTIONS = ['detect' => 'Erkannt', 'block' => 'Blockiert'];

    /** What a new host gets (PK95 Proxy sets detect itself when a host is created). */
    public const DEFAULTS = ['mode' => 'detect', 'paranoia' => 1, 'anomaly_threshold' => 5, 'body_limit_kb' => 1024, 'profiles' => []];

    /** The fields Core sends: PUT is a partial update, the exceptions go through their own routes. */
    public const FIELDS = ['mode', 'paranoia', 'anomaly_threshold', 'body_limit_kb', 'profiles'];

    /** status error of a Caddy without the WAF module, the fallback when "module" is missing: not a problem, just no WAF */
    public const NO_MODULE = 'ohne WAF-Modul';

    /**
     * GET /api/waf/status, or null when this Proxy has no web firewall (404, Caddy without the
     * module) or does not answer.
     *
     * @return array{crs: string, coraza: string, rules_loaded: bool, error: string, hosts: list<array<string, mixed>>}|null
     */
    public static function status(InstalledApp $app): ?array
    {
        try {
            $response = $app->client()->get('/api/waf/status');
        } catch (AppException) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $s = (array) $response->json();
        $loaded = (bool) ($s['rules_loaded'] ?? false);
        $error = trim((string) ($s['error'] ?? ''));
        // standalone (Caddy without the WAF module): "module": false; without the field the error text tells
        $withoutModule = array_key_exists('module', $s) ? $s['module'] === false : (! $loaded && str_contains($error, self::NO_MODULE));
        if ($withoutModule) {
            return null;
        }

        return [
            'crs' => (string) ($s['crs_version'] ?? ''),
            'coraza' => (string) ($s['coraza_version'] ?? ''),
            'rules_loaded' => $loaded,
            'error' => $error,
            'hosts' => array_values(array_filter((array) ($s['hosts'] ?? []), 'is_array')),
        ];
    }

    /**
     * Hosts whose rules did not load (they run without WAF, fail-open): host_id → error.
     *
     * @param  array<string, mixed>|null  $status
     * @return array<int, string>
     */
    public static function hostErrors(?array $status): array
    {
        $out = [];
        foreach ($status['hosts'] ?? [] as $h) {
            if (trim((string) ($h['error'] ?? '')) !== '') {
                $out[(int) ($h['host_id'] ?? 0)] = trim((string) $h['error']);
            }
        }

        return $out;
    }

    /**
     * GET /api/hosts/{id}/waf → form state (strings for the inputs).
     *
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>
     */
    public static function toForm(array $w): array
    {
        $w += self::DEFAULTS;

        return [
            'mode' => isset(self::MODES[$w['mode']]) ? $w['mode'] : 'detect',
            'paranoia' => (string) $w['paranoia'],
            'anomaly_threshold' => (string) $w['anomaly_threshold'],
            'body_limit_kb' => (string) $w['body_limit_kb'],
            'profiles' => array_values(array_map('strval', (array) $w['profiles'])),
        ];
    }

    /**
     * Form state → body of PUT /api/hosts/{id}/waf: only the settings, never "exceptions"
     * (in a PUT it would replace the list).
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function payload(array $f): array
    {
        $int = fn (string $key) => preg_match('/^\d+$/', trim((string) ($f[$key] ?? ''))) ? (int) trim((string) $f[$key]) : trim((string) ($f[$key] ?? ''));

        return [
            'mode' => (string) ($f['mode'] ?? 'detect'),
            'paranoia' => $int('paranoia'),
            'anomaly_threshold' => $int('anomaly_threshold'),
            'body_limit_kb' => $int('body_limit_kb'),
            'profiles' => array_values(array_map('strval', (array) ($f['profiles'] ?? []))),
        ];
    }

    /**
     * Whether a rule may get an exception: not the CRS rules that evaluate the score
     * (949xxx inbound, 959xxx outbound, 980xxx correlation). Excepting them would switch off
     * blocking on that path altogether instead of one false alarm.
     */
    public static function excludable(int|string $ruleId): bool
    {
        if (! ctype_digit((string) $ruleId)) {
            return false;
        }

        return ! in_array(intdiv((int) $ruleId, 1000), [949, 959, 980], true);
    }

    /**
     * Path of a hit as the prefix of a new exception: without query string.
     */
    public static function pathPrefix(string $path): string
    {
        $path = (string) strtok($path, '?');

        return $path === '' ? '/' : $path;
    }

    /**
     * Start of a period for /api/waf/stats in ms: local midnight for "today" (days 0), else
     * now minus the days.
     */
    public static function from(int $days): int
    {
        return $days === 0
            ? now(Format::timezone())->startOfDay()->getTimestampMs()
            : now()->subDays($days)->getTimestampMs();
    }
}
