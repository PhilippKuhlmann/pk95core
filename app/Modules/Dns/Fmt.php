<?php

namespace App\Modules\Dns;

use Illuminate\Support\Carbon;

/**
 * Formats and German names of the PK95 DNS pages. Timestamps of the app are milliseconds.
 */
final class Fmt
{
    /** Query log status → German label. */
    public const STATUSES = [
        'ok' => 'Weitergeleitet',
        'allowed' => 'Erlaubt',
        'blocked' => 'Blockiert',
        'blocked_service' => 'Dienst blockiert',
        'safesearch' => 'SafeSearch',
        'rewrite' => 'Lokaler Eintrag',
        'refused' => 'Abgelehnt',
        'error' => 'Fehler',
    ];

    public const REASONS = [
        'list' => 'Liste',
        'custom' => 'Eigene Regel',
        'service' => 'Dienst',
        'safesearch' => 'SafeSearch',
        'rewrite' => 'Lokaler Eintrag',
        'access' => 'Zugriff nicht erlaubt',
        'any' => 'ANY-Anfrage',
        'ratelimit' => 'Ratenlimit',
        'upstream' => 'Upstream nicht erreichbar',
    ];

    public const GROUPS = [
        'social' => 'Soziale Netzwerke',
        'messenger' => 'Messenger',
        'video' => 'Video und Streaming',
        'music' => 'Musik',
        'gaming' => 'Spiele',
        'shopping' => 'Shopping',
        'ai' => 'KI-Dienste',
        'other' => 'Sonstige',
    ];

    public const ENGINES = [
        'google' => 'Google',
        'bing' => 'Bing',
        'duckduckgo' => 'DuckDuckGo',
        'youtube' => 'YouTube',
        'yandex' => 'Yandex',
        'pixabay' => 'Pixabay',
    ];

    public static function num(int|float $n, int $decimals = 0): string
    {
        return number_format($n, $decimals, ',', '.');
    }

    /** Up to $max decimals, trailing zeros dropped. */
    public static function dec(float $n, int $max = 1): string
    {
        $s = number_format($n, $max, ',', '.');

        return str_contains($s, ',') ? rtrim(rtrim($s, '0'), ',') : $s;
    }

    public static function compact(int|float $n): string
    {
        return match (true) {
            $n >= 1e6 => self::dec($n / 1e6).' Mio.',
            $n >= 10000 => self::dec($n / 1e3).' Tsd.',
            default => self::num($n),
        };
    }

    public static function pct(float $p): string
    {
        return match (true) {
            $p == 0 => '0 %',
            $p < 0.1 => '<0,1 %',
            default => self::dec($p).' %',
        };
    }

    /** Share of $part in $total as percent text. */
    public static function share(int|float $part, int|float $total): string
    {
        return self::pct($total > 0 ? $part / $total * 100 : 0);
    }

    public static function ms(int|float|null $ms): string
    {
        return match (true) {
            $ms === null => '–',
            $ms < 0.1 => '<0,1 ms',
            $ms < 10 => self::dec($ms, 1).' ms',
            $ms < 1000 => round($ms).' ms',
            default => self::dec($ms / 1000, 2).' s',
        };
    }

    public static function at(int|float $ms): Carbon
    {
        return Carbon::createFromTimestampMs($ms)->timezone(config('app.timezone'));
    }

    /** 08.11. 14:03:12 */
    public static function stamp(int|float $ms): string
    {
        return self::at($ms)->format('d.m. H:i:s');
    }

    /** 08.11.2026, 14:03 */
    public static function dateTime(int|float $ms): string
    {
        return self::at($ms)->format('d.m.Y, H:i');
    }

    /** 14:03 today, otherwise 09.11., 14:03 */
    public static function when(int|float $ms): string
    {
        $at = self::at($ms);

        return $at->isToday() ? $at->format('H:i') : $at->format('d.m., H:i');
    }

    public static function plural(int $n, string $one, string $many): string
    {
        return self::num($n).' '.($n === 1 ? $one : $many);
    }

    /**
     * One entry per line (commas separate as well), trimmed, empty ones dropped.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $text)), fn ($v) => $v !== ''));
    }

    public static function status(string $status): string
    {
        return self::STATUSES[$status] ?? $status;
    }

    /** Badge classes for a query status, readable in light and dark mode. */
    public static function badge(string $status): string
    {
        return match ($status) {
            'blocked', 'blocked_service' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/70 dark:text-rose-100',
            'allowed' => 'bg-green-100 text-green-800 dark:bg-green-900/70 dark:text-green-100',
            'safesearch', 'rewrite' => 'bg-cerulean-100 text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-100',
            'refused', 'error' => 'bg-amber-100 text-amber-900 dark:bg-amber-900/70 dark:text-amber-100',
            default => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        };
    }

    public static function blocked(string $status): bool
    {
        return $status === 'blocked' || $status === 'blocked_service';
    }

    /** Dot colour for good / warn / bad / none. */
    public static function dot(string $tone): string
    {
        return match ($tone) {
            'good' => 'bg-green-500',
            'warn' => 'bg-amber-500',
            'bad' => 'bg-rose-500',
            default => 'bg-gray-400 dark:bg-gray-500',
        };
    }
}
