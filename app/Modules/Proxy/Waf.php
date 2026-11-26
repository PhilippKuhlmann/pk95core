<?php

namespace App\Modules\Proxy;

use App\Models\InstalledApp;
use App\Services\Apps\AppException;

/**
 * The web firewall of PK95 Proxy (Coraza + OWASP CRS), shared by the host form, the page
 * „Web-Firewall“ and the dashboard. A Proxy without it answers 404 on /api/waf/status (or
 * available: false when Coraza is missing): then Core shows nothing of it.
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

    public const ACTIONS = ['detected' => 'Erkannt', 'blocked' => 'Blockiert'];

    /** What a new host gets (PK95 Proxy sets detect itself when a host is created). */
    public const DEFAULTS = ['mode' => 'detect', 'paranoia' => 1, 'anomaly_threshold' => 5, 'body_limit_kb' => 1024, 'profiles' => []];

    /**
     * GET /api/waf/status, or null when this Proxy has no web firewall (404, available false)
     * or does not answer.
     *
     * @return array{coraza: string, crs: string, profiles: list<array<string, mixed>>, hosts_with_errors: list<array<string, mixed>>}|null
     */
    public static function status(InstalledApp $app): ?array
    {
        try {
            $response = $app->client()->get('/api/waf/status');
        } catch (AppException) {
            return null;
        }
        $s = $response->successful() ? (array) $response->json() : [];
        if (! ($s['available'] ?? false)) {
            return null;
        }

        return [
            'coraza' => (string) ($s['coraza'] ?? ''),
            'crs' => (string) ($s['crs'] ?? ''),
            'profiles' => array_values(array_filter((array) ($s['profiles'] ?? []), 'is_array')),
            'hosts_with_errors' => array_values(array_filter((array) ($s['hosts_with_errors'] ?? []), 'is_array')),
        ];
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
     * Form state → body of PUT /api/hosts/{id}/waf. The exclusions are sent as the panel last
     * reported them: they are only changed through their own routes.
     *
     * @param  array<string, mixed>  $f
     * @param  list<array<string, mixed>>  $exclusions
     * @return array<string, mixed>
     */
    public static function payload(array $f, array $exclusions): array
    {
        $int = fn (string $key) => preg_match('/^\d+$/', trim((string) ($f[$key] ?? ''))) ? (int) trim((string) $f[$key]) : trim((string) ($f[$key] ?? ''));

        return [
            'mode' => (string) ($f['mode'] ?? 'detect'),
            'paranoia' => $int('paranoia'),
            'anomaly_threshold' => $int('anomaly_threshold'),
            'body_limit_kb' => $int('body_limit_kb'),
            'profiles' => array_values(array_map('strval', (array) ($f['profiles'] ?? []))),
            'exclusions' => array_values($exclusions),
        ];
    }

    /**
     * Whether a rule may get an exclusion: not the CRS rules that evaluate the score
     * (949xxx inbound, 959xxx outbound, 980xxx correlation). Excluding them would switch off
     * blocking on that path altogether instead of one false alarm.
     */
    public static function excludable(int|string $ruleId): bool
    {
        if (! ctype_digit((string) $ruleId)) {
            return false;
        }
        $block = intdiv((int) $ruleId, 1000);

        return ! in_array($block, [949, 959, 980], true);
    }

    /**
     * Path of a hit as the prefix of a new exclusion: without query string.
     */
    public static function pathPrefix(string $path): string
    {
        $path = (string) strtok($path, '?');

        return $path === '' ? '/' : $path;
    }
}
