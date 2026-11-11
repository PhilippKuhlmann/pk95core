<?php

namespace App\Modules\Uptime;

use App\Http\Support\Format;

/**
 * The small 24 h bars of a monitor in the list. PK95 Uptime sends "bars24h" per monitor
 * (24 or 48 sections, oldest first, {at, end, up, warn, down} with UTC ms); all counts 0 =
 * no data or paused. Older Uptime versions send nothing: no strip then.
 */
final class Bars
{
    /** Same tones as the status dots of the list. */
    public const TONES = [
        'down' => 'bg-rose-500',
        'warn' => 'bg-amber-500',
        'up' => 'bg-green-500',
        'none' => 'bg-gray-200 dark:bg-gray-600',
    ];

    /** down beats warn beats up; nothing counted = no data. */
    public static function tone(array $bar): string
    {
        return match (true) {
            (int) ($bar['down'] ?? 0) > 0 => 'down',
            (int) ($bar['warn'] ?? 0) > 0 => 'warn',
            (int) ($bar['up'] ?? 0) > 0 => 'up',
            default => 'none',
        };
    }

    /**
     * Bars for the view, null without data from the app.
     *
     * @param  array<string, mixed>  $monitor
     * @return array{bars: list<array{tone: string, class: string, title: string}>, label: string}|null
     */
    public static function strip(array $monitor): ?array
    {
        $raw = $monitor['bars24h'] ?? null;
        if (! is_array($raw) || $raw === []) {
            return null;
        }
        $bars = [];
        foreach (array_values($raw) as $bar) {
            if (! is_array($bar) || ! isset($bar['at'], $bar['end'])) {
                continue;
            }
            $tone = self::tone($bar);
            $bars[] = ['tone' => $tone, 'class' => self::TONES[$tone], 'title' => self::title($bar)];
        }

        return $bars === [] ? null : ['bars' => $bars, 'label' => self::label($raw)];
    }

    /** "11.11. 14:00 bis 14:30" and what was counted. */
    public static function title(array $bar): string
    {
        $from = Format::fromMs((int) $bar['at']);
        $to = Format::fromMs((int) $bar['end']);
        $span = $from->format('d.m. H:i').' bis '.$to->format('H:i');
        [$up, $warn, $down] = [(int) ($bar['up'] ?? 0), (int) ($bar['warn'] ?? 0), (int) ($bar['down'] ?? 0)];
        if ($up + $warn + $down === 0) {
            return $span."\nKeine Daten oder pausiert";
        }
        $parts = array_filter([
            $up ? $up.' OK' : null,
            $warn ? $warn.' mit Warnung' : null,
            $down ? $down.' fehlgeschlagen' : null,
        ]);

        return $span."\n".implode(', ', $parts);
    }

    /**
     * Text for screen readers: "24 h: 99,3 % verfügbar, 1 Ausfall". Warnings count as
     * available (the service answered); consecutive failed sections are one outage.
     *
     * @param  list<array<string, mixed>>  $raw
     */
    public static function label(array $raw): string
    {
        $ok = $all = $outages = 0;
        $inOutage = false;
        foreach ($raw as $bar) {
            $down = (int) ($bar['down'] ?? 0);
            $ok += (int) ($bar['up'] ?? 0) + (int) ($bar['warn'] ?? 0);
            $all += (int) ($bar['up'] ?? 0) + (int) ($bar['warn'] ?? 0) + $down;
            if ($down > 0 && ! $inOutage) {
                $outages++;
            }
            $inOutage = $down > 0;
        }
        if ($all === 0) {
            return '24 h: keine Daten';
        }
        $pct = $ok / $all * 100;
        $pctText = number_format($pct, $pct >= 99.95 || $pct === 0.0 ? 0 : 1, ',', '.').' %';

        return '24 h: '.$pctText.' verfügbar, '.match ($outages) {
            0 => 'keine Ausfälle',
            1 => '1 Ausfall',
            default => $outages.' Ausfälle',
        };
    }
}
