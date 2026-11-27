<?php

namespace App\Modules\Proxy;

use App\Http\Support\Format;
use App\Models\InstalledApp;
use App\Services\Apps\AppException;

/**
 * The web firewall of PK95 Proxy 1.8.0 (Coraza + OWASP CRS; 1.9.0 adds paranoia 3–4, detection
 * level, rule groups, bypass networks, limits and exceptions per parameter), shared by the host form, the
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

    /** PK95 Proxy 1.9.0: four levels */
    public const PARANOIA_19 = [
        1 => 'Stufe 1: Standard, kaum Fehlalarme',
        2 => 'Stufe 2: streng, gelegentlich Fehlalarme',
        3 => 'Stufe 3: sehr streng, Ausnahmen nötig',
        4 => 'Stufe 4: maximal, viele Fehlalarme',
    ];

    /** HTTP methods PK95 Proxy 1.9.0 accepts in limits.allowed_methods, in this order */
    public const METHODS = ['GET', 'HEAD', 'POST', 'OPTIONS', 'PUT', 'PATCH', 'DELETE',
        'PROPFIND', 'PROPPATCH', 'MKCOL', 'COPY', 'MOVE', 'LOCK', 'UNLOCK', 'REPORT', 'SEARCH', 'MKCALENDAR', 'ACL'];

    /** number limits of 1.9.0: field → [label, unit] */
    public const LIMIT_NUMBERS = [
        'max_args' => ['Parameter je Anfrage', 'Anzahl'],
        'arg_name_length' => ['Länge eines Parameternamens', 'Zeichen'],
        'arg_length' => ['Länge eines Parameterwerts', 'Zeichen'],
        'total_arg_length' => ['Länge aller Parameter zusammen', 'Zeichen'],
        'max_file_size_kb' => ['Größe einer hochgeladenen Datei', 'KB'],
        'combined_file_sizes_kb' => ['Größe aller Dateien einer Anfrage', 'KB'],
    ];

    /** list limits of 1.9.0 */
    public const LIMIT_LISTS = ['allowed_methods', 'allowed_content_types', 'restricted_extensions'];

    /** exception target: COLLECTION:name, name as PK95 Proxy accepts it */
    public const TARGET_NAME = '/^[A-Za-z0-9_.\-\[\]]+$/';

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
            // 1.9.0: exception_targets is its sign (also before a host exists)
            'v19' => array_key_exists('exception_targets', $s),
            'exception_targets' => array_values(array_map('strval', (array) ($s['exception_targets'] ?? []))),
            'bypassed_today' => (int) ($s['bypassed_today'] ?? 0),
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
     * GET /api/hosts/{id}/waf → form state (strings for the inputs). The fields of 1.9.0 come
     * along when the answer has them; limits that are null show the CRS default (lists) or
     * stay empty (numbers: no limit).
     *
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>
     */
    public static function toForm(array $w): array
    {
        $w += self::DEFAULTS;

        return [
            'mode' => isset(self::MODES[$w['mode']]) ? $w['mode'] : 'detect',
            'profiles' => array_values(array_map('strval', (array) $w['profiles'])),
            'bypass_cidrs' => implode("\n", (array) ($w['bypass_cidrs'] ?? [])),
        ] + self::tuningForm($w, (array) ($w['defaults'] ?? []));
    }

    /**
     * The six settings a host takes from the central configuration (PK95 Proxy 1.9.0: block and
     * detection level, threshold, body limit, disabled groups, limits) as form state. The same
     * form serves the host and „Zentrale Einstellungen“ (partial modules.proxy.partials.waf-tuning).
     *
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>  $defaults  CRS defaults of the limits
     * @return array<string, mixed>
     */
    public static function tuningForm(array $w, array $defaults): array
    {
        $w += self::DEFAULTS;
        $form = [
            'paranoia' => (string) $w['paranoia'],
            'anomaly_threshold' => (string) $w['anomaly_threshold'],
            'body_limit_kb' => (string) $w['body_limit_kb'],
            'detection_paranoia' => (string) ($w['detection_paranoia'] ?? $w['paranoia']),
            'disabled_groups' => array_values(array_map('strval', (array) ($w['disabled_groups'] ?? []))),
        ];
        $limits = (array) ($w['limits'] ?? []);
        $list = fn (string $k) => array_values(array_map('strval', (array) ($limits[$k] ?? $defaults[$k] ?? [])));
        $form['limits'] = [
            'allowed_methods' => $list('allowed_methods'),
            'allowed_content_types' => implode("\n", $list('allowed_content_types')),
            'restricted_extensions' => implode("\n", $list('restricted_extensions')),
        ];
        foreach (array_keys(self::LIMIT_NUMBERS) as $k) {
            $form['limits'][$k] = isset($limits[$k]) ? (string) $limits[$k] : '';
        }

        return $form;
    }

    /**
     * Form state → body of PUT /api/hosts/{id}/waf (a partial update): the settings, never
     * "exceptions" (in a PUT it would replace the list). With $v19 also the fields of 1.9.0;
     * limits only when the Proxy's defaults are known (an existing host), complete, null =
     * CRS default (also for a list that equals the default).
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>|null  $defaults  limits defaults of GET /waf
     * @return array<string, mixed>
     */
    public static function payload(array $f, bool $v19 = false, ?array $defaults = null, ?bool $inherit = null): array
    {
        $profiles = array_values(array_map('strval', (array) ($f['profiles'] ?? [])));
        if (! $v19) {
            // PK95 Proxy 1.8.0: exactly the request it has always got
            $t = self::tuningPayload($f, null);

            return ['mode' => (string) ($f['mode'] ?? 'detect'), 'paranoia' => $t['paranoia'], 'anomaly_threshold' => $t['anomaly_threshold'],
                'body_limit_kb' => $t['body_limit_kb'], 'profiles' => $profiles];
        }
        $body = ['mode' => (string) ($f['mode'] ?? 'detect'), 'profiles' => $profiles];
        $body['bypass_cidrs'] = self::lines((string) ($f['bypass_cidrs'] ?? ''));
        if ($inherit !== null) {
            $body['inherit'] = $inherit;
        }

        // a host that inherits keeps its own values stored at the Proxy: not sent (partial update)
        return $inherit === true ? $body : $body + self::tuningPayload($f, $defaults);
    }

    /**
     * The six tuning fields → API (host or central). limits only when the Proxy's defaults are
     * known, complete, null = CRS default (also for a list that equals the default).
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>|null  $defaults
     * @return array<string, mixed>
     */
    public static function tuningPayload(array $f, ?array $defaults): array
    {
        $int = fn ($v) => preg_match('/^\d+$/', trim((string) $v)) ? (int) trim((string) $v) : trim((string) $v);
        $body = [
            'paranoia' => $int($f['paranoia'] ?? ''),
            'detection_paranoia' => $int($f['detection_paranoia'] ?? $f['paranoia'] ?? ''),
            'anomaly_threshold' => $int($f['anomaly_threshold'] ?? ''),
            'body_limit_kb' => $int($f['body_limit_kb'] ?? ''),
            'disabled_groups' => array_values(array_map('strval', (array) ($f['disabled_groups'] ?? []))),
        ];
        if ($defaults !== null) {
            $body['limits'] = self::limitsPayload((array) ($f['limits'] ?? []), $defaults);
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $l
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private static function limitsPayload(array $l, array $defaults): array
    {
        $same = fn (array $a, $b) => is_array($b) && ! array_diff($a, $b) && ! array_diff($b, $a);
        $methods = array_values(array_intersect(self::METHODS, array_map('strtoupper', (array) ($l['allowed_methods'] ?? []))));
        $types = array_values(array_unique(array_map(fn ($t) => strtolower(trim((string) strtok($t, ';'))), self::lines((string) ($l['allowed_content_types'] ?? '')))));
        $ext = array_values(array_unique(array_map(fn ($e) => '.'.ltrim(strtolower($e), '.'),
            self::lines(str_replace([',', ' '], "\n", (string) ($l['restricted_extensions'] ?? ''))))));
        $out = [
            // the CRS default stays the default (null), so the host follows later CRS changes
            'allowed_methods' => $same($methods, $defaults['allowed_methods'] ?? null) ? null : $methods,
            'allowed_content_types' => $types === [] || $same($types, $defaults['allowed_content_types'] ?? null) ? null : $types,
            'restricted_extensions' => $same($ext, $defaults['restricted_extensions'] ?? null) ? null : $ext,
        ];
        foreach (array_keys(self::LIMIT_NUMBERS) as $k) {
            $v = trim((string) ($l[$k] ?? ''));
            $out[$k] = $v === '' ? null : (ctype_digit($v) ? (int) $v : $v);
        }

        return $out;
    }

    /**
     * How many advanced settings differ from the default (for „Erweitert · N angepasst“).
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>|null  $defaults
     */
    public static function adjusted(array $f, ?array $defaults): int
    {
        $t = self::tuningPayload($f, $defaults);
        $n = (int) ((string) $t['detection_paranoia'] !== (string) $t['paranoia']) + (int) ($t['disabled_groups'] !== [])
            + (int) ((string) $t['anomaly_threshold'] !== (string) self::DEFAULTS['anomaly_threshold'])
            + (int) ((string) $t['body_limit_kb'] !== (string) self::DEFAULTS['body_limit_kb']);

        return $n + count(array_filter($t['limits'] ?? [], fn ($v) => $v !== null));
    }

    /**
     * 422 fields of the WAF routes → [form key, message]: an index goes into the message
     * ("bypass_cidrs.2" → bypass_cidrs, „Zeile 3: …“; "limits.allowed_methods.1" → limits.allowed_methods).
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    public static function fieldErrors(array $fields): array
    {
        $out = [];
        foreach ($fields as $field => $message) {
            $key = (string) $field;
            if (preg_match('/^(.+)\.(\d+)$/', $key, $m)) {
                $key = $m[1];
                $message = 'Zeile '.((int) $m[2] + 1).': '.$message;
            }
            $out[$key] = isset($out[$key]) ? $out[$key].' '.$message : (string) $message;
        }

        return $out;
    }

    /**
     * Target of a rule as an exception may name it: only "COLLECTION:name" whose collection
     * the Proxy accepts (status.exception_targets) and whose name fits; else null (the
     * exception then holds for the whole rule).
     *
     * @param  list<string>  $collections
     */
    public static function exceptionTarget(?string $target, array $collections): ?string
    {
        $target = trim((string) $target);
        if (strlen($target) > 100 || ! str_contains($target, ':')) {
            return null;
        }
        [$collection, $name] = explode(':', $target, 2);

        return in_array($collection, $collections, true) && preg_match(self::TARGET_NAME, $name) ? $target : null;
    }

    /** @return list<string> non-empty trimmed lines */
    public static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text)), fn ($l) => $l !== ''));
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
