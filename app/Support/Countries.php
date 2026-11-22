<?php

namespace App\Support;

/**
 * Countries by ISO code, shared by the modules (PK95 Proxy, PK95 DNS): German names from
 * countries.php (generated with PHP intl, the Core image has none) and flags as emoji.
 */
final class Countries
{
    /**
     * @return array<string, string> code → German name
     */
    public static function all(): array
    {
        static $names = null;

        return $names ??= require __DIR__.'/country-names.php';
    }

    /** German name; "" / null = Unbekannt, LAN = local network, an unknown code stays the code. */
    public static function name(?string $code): string
    {
        if (! $code) {
            return 'Unbekannt';
        }
        if ($code === 'LAN') {
            return 'Lokales Netz';
        }

        return self::all()[strtoupper($code)] ?? $code;
    }

    /** Flag as emoji (regional indicators); decorative, always next to the name. */
    public static function flag(?string $code): string
    {
        $code = is_string($code) ? strtoupper($code) : '';
        if (! preg_match('/^[A-Z]{2}$/', $code)) {
            return '';
        }

        return mb_chr(0x1F1E6 + ord($code[0]) - 65).mb_chr(0x1F1E6 + ord($code[1]) - 65);
    }

    /** "🇩🇪 Deutschland" */
    public static function label(?string $code): string
    {
        return trim(self::flag($code).' '.self::name($code));
    }
}
