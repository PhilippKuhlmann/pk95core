<?php

namespace App\Modules\Ad;

use App\Http\Support\Format;
use Illuminate\Support\Carbon;

/**
 * The clock of PK95 AD (1.4.0): GET /api/time and the compact "ntp.time" in /api/status and
 * /api/domain. PK95 AD only measures its clock against the reference servers (chronyd -x in the
 * LXC); the Proxmox host sets it. Older versions have no "time": everything here is null then.
 */
final class AdTime
{
    public const STATUSES = ['ok', 'warn', 'error', 'unknown'];

    /**
     * Help line where the servers are configured. chronyd -x: PK95 AD never sets the clock; the
     * DC (Kerberos too) runs on the clock of the Proxmox host, the members get AD's estimate.
     */
    public const HELP = 'Die Uhr stellt der Proxmox-Host. PK95 AD vergleicht sie mit diesen Servern und warnt bei Abweichung. Ab 5 Minuten scheitern Windows-Anmeldungen.';

    /**
     * The time object in a fixed shape, with "skew_ms" = AD clock minus Core's clock when it was
     * read (so a cached answer still ticks right), null without a usable "now".
     *
     * @return array{skew_ms: int, offset_ms: ?float, source: string, synchronized: bool, status: string, message: string}|null
     */
    public static function normalize(mixed $time, ?int $readAtMs = null): ?array
    {
        if (! is_array($time) || ! is_string($time['now'] ?? null)) {
            return null;
        }
        try {
            $now = Carbon::parse($time['now'])->getTimestampMs();
        } catch (\Throwable) {
            return null;
        }
        $status = in_array($time['status'] ?? null, self::STATUSES, true) ? $time['status'] : 'unknown';

        return [
            'skew_ms' => $now - ($readAtMs ?? now()->getTimestampMs()),
            'offset_ms' => is_numeric($time['offset_ms'] ?? null) ? (float) $time['offset_ms'] : null,
            'source' => (string) ($time['source'] ?? ''),
            'synchronized' => (bool) ($time['synchronized'] ?? false),
            'status' => $status,
            'message' => (string) ($time['message'] ?? ''),
        ];
    }

    /** "2 ms", "0,4 ms", "1,5 s", "3 min 20 s" (without sign) */
    public static function offset(float $ms): string
    {
        $abs = abs($ms);

        return match (true) {
            $abs < 1 => number_format($abs, 1, ',', '.').' ms',
            $abs < 1000 => number_format($abs, 0, ',', '.').' ms',
            $abs < 60000 => number_format($abs / 1000, 1, ',', '.').' s',
            default => intdiv((int) round($abs / 1000), 60).' min '.((int) round($abs / 1000) % 60).' s',
        };
    }

    /**
     * "synchron mit de.pool.ntp.org · Abweichung 2 ms", "keine Referenz", "wird geprüft", ...
     * With $servers (the full answer of /api/time) an unknown state says which of the two it is.
     *
     * @param  array<string, mixed>  $t  normalize()
     * @param  list<string>|null  $servers
     */
    public static function line(array $t, ?array $servers = null): string
    {
        if ($t['source'] !== '') {
            $line = ($t['synchronized'] ? 'synchron mit ' : 'Referenz ').$t['source'];
            if ($t['offset_ms'] !== null) {
                $line .= ' · Abweichung '.self::offset($t['offset_ms']);
                if (abs($t['offset_ms']) >= 1000) {
                    $line .= $t['offset_ms'] > 0 ? ' (Uhr geht vor)' : ' (Uhr geht nach)';
                }
            }

            return $line;
        }
        if ($t['status'] === 'unknown') {
            return $servers === [] ? 'keine Referenz' : 'wird geprüft';
        }

        return 'keine Referenz erreichbar';
    }

    /** "33 s", "4 min", "2 h" for the last answer of a source */
    public static function ago(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => $seconds.' s',
            $seconds < 7200 => intdiv($seconds, 60).' min',
            default => intdiv($seconds, 3600).' h',
        };
    }

    /** ok | warn | bad | muted (unknown) */
    public static function tone(string $status): string
    {
        return ['ok' => 'ok', 'warn' => 'warn', 'error' => 'bad'][$status] ?? 'muted';
    }

    /** the AD clock as HH:MM:SS in the display timezone, $skewMs = AD minus Core */
    public static function clock(int $skewMs): string
    {
        return Carbon::createFromTimestampMs(now()->getTimestampMs() + $skewMs)->setTimezone(Format::timezone())->format('H:i:s');
    }
}
