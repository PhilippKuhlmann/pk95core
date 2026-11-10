<?php

namespace App\Http\Support;

use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Display helpers shared by the Blade views (dates in the display timezone, sizes).
 *
 * Times are stored and compared in UTC (app.timezone); only what is shown to the user is
 * converted to the display timezone, so summer/winter time never shifts stored data.
 */
class Format
{
    /** @var array<string, string> configured value → usable timezone */
    private static array $checked = [];

    /** Display timezone: pk95.timezone (PK95_TIMEZONE, else TZ), Europe/Berlin if unusable. */
    public static function timezone(): string
    {
        $tz = trim((string) config('pk95.timezone')) ?: 'Europe/Berlin';

        return self::$checked[$tz] ??= rescue(fn () => (new DateTimeZone($tz))->getName(), 'Europe/Berlin', false);
    }

    /** The moment in the display timezone, null when it cannot be parsed. */
    public static function local(CarbonInterface|string|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $date = $value instanceof CarbonInterface ? Carbon::instance($value) : Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }

        return $date->setTimezone(self::timezone());
    }

    /** A Unix timestamp in milliseconds (as the apps send it) in the display timezone. */
    public static function fromMs(int|float $ms): Carbon
    {
        return Carbon::createFromTimestampMs($ms)->setTimezone(self::timezone());
    }

    public static function dt(CarbonInterface|string|null $value, bool $seconds = false): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return self::local($value)?->format($seconds ? 'd.m.Y H:i:s' : 'd.m.Y H:i') ?? (string) $value;
    }

    public static function time(CarbonInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::local($value)?->format('H:i:s') ?? (string) $value;
    }

    public static function bytes(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '—';
        }
        $n = (float) $value;
        foreach (['B', 'KB', 'MB', 'GB', 'TB', 'PB'] as $unit) {
            if ($n < 1024 || $unit === 'PB') {
                return in_array($unit, ['B', 'KB'], true)
                    ? number_format($n, 0, ',', '.').' '.$unit
                    : number_format($n, 1, ',', '.').' '.$unit;
            }
            $n /= 1024;
        }

        return '—';
    }

    /** Percentage 0–100 of $part in $total, or null when unknown. */
    public static function percent(mixed $part, mixed $total): ?int
    {
        if (! is_numeric($part) || ! is_numeric($total) || (float) $total <= 0) {
            return null;
        }

        return (int) max(0, min(100, round((float) $part / (float) $total * 100)));
    }

    public static function uptime(mixed $seconds): string
    {
        if (! is_numeric($seconds)) {
            return '—';
        }
        $s = (int) $seconds;
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);

        return $d > 0 ? "{$d} T {$h} Std" : ($h > 0 ? "{$h} Std {$m} Min" : "{$m} Min");
    }
}
