<?php

namespace App\Modules\Proxy;

use Illuminate\Support\Carbon;

/**
 * Number and date formats of the PK95 Proxy interface (web/app.js), for the Core views.
 * Timestamps of the panel are milliseconds unless the name says otherwise.
 */
final class Fmt
{
    public static function num(int|float $n, int $decimals = 0): string
    {
        return number_format($n, $decimals, ',', '.');
    }

    /** Up to $max decimals, trailing zeros dropped (like toLocaleString maximumFractionDigits). */
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

    public static function bytes(int|float $b): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1000 && $i < count($units) - 1) {
            $b /= 1000;
            $i++;
        }

        return self::dec($b, $i === 0 ? 0 : ($b < 100 ? 1 : 0)).' '.$units[$i];
    }

    public static function ms(int|float|null $ms): string
    {
        return match (true) {
            $ms === null => '–',
            $ms < 1 => '<1 ms',
            $ms < 1000 => round($ms).' ms',
            default => self::dec($ms / 1000, 2).' s',
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

    /** Rounds down so an outage never shows as 100 %. */
    public static function uptime(?float $v): string
    {
        return match (true) {
            $v === null => '–',
            $v >= 100 => '100 %',
            default => self::dec(floor($v * 100) / 100, 2).' %',
        };
    }

    public static function duration(int|float $ms): string
    {
        $min = max(1, (int) round($ms / 60000));
        if ($min < 60) {
            return "{$min} Min.";
        }
        $hours = intdiv($min, 60);
        if ($hours < 48) {
            return "{$hours} Std. ".($min % 60).' Min.';
        }

        return intdiv($hours, 24).' Tage '.($hours % 24).' Std.';
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

    /** 08.11.2026 */
    public static function date(int|float $ms): string
    {
        return self::at($ms)->format('d.m.Y');
    }

    /** 08.11.2026, 14:03 */
    public static function dateTime(int|float $ms): string
    {
        return self::at($ms)->format('d.m.Y, H:i');
    }

    public static function time(int|float $ms): string
    {
        return self::at($ms)->format('H:i');
    }

    public static function plural(int $n, string $one, string $many): string
    {
        return self::num($n).' '.($n === 1 ? $one : $many);
    }

    /** German country name; LAN = local network. */
    public static function country(?string $code): string
    {
        if (! $code) {
            return 'Unbekannt';
        }
        if ($code === 'LAN') {
            return 'Lokales Netz';
        }

        return self::countries()[$code] ?? $code;
    }

    /**
     * @return array<string, string> code → German name, all countries the panel knows
     */
    public static function countries(): array
    {
        static $names = null;

        return $names ??= require __DIR__.'/countries.php';
    }

    /** Flag as emoji (regional indicators); decorative, always next to the name. */
    public static function flag(?string $code): string
    {
        if (! is_string($code) || ! preg_match('/^[A-Z]{2}$/', $code)) {
            return '';
        }

        return mb_chr(0x1F1E6 + ord($code[0]) - 65).mb_chr(0x1F1E6 + ord($code[1]) - 65);
    }

    /** Tailwind classes for an HTTP status. */
    public static function statusTone(int $code): string
    {
        return match (true) {
            $code >= 500 => 'text-rose-700 dark:text-rose-300',
            $code >= 400 => 'text-amber-700 dark:text-amber-300',
            $code >= 300 => 'text-cerulean-700 dark:text-cerulean-300',
            $code >= 200 => 'text-green-700 dark:text-green-300',
            default => 'text-gray-500',
        };
    }

    /** Dot colour for good / warn / bad / maint / none. */
    public static function dot(string $tone): string
    {
        return match ($tone) {
            'good' => 'bg-green-500',
            'warn' => 'bg-amber-500',
            'bad' => 'bg-rose-500',
            'maint' => 'bg-cerulean-500',
            default => 'bg-gray-300 dark:bg-gray-600',
        };
    }
}
