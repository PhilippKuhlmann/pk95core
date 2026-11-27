<?php

namespace App\Support;

use Livewire\Livewire;

/**
 * The query string of the page the browser shows. Module pages load deferred: their mount()
 * runs in a Livewire request, whose own query string is empty. Then the page URL is the
 * Referer (same origin, Referrer-Policy same-origin), as Livewire itself reads #[Url] values.
 */
class PageQuery
{
    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (! Livewire::isLivewireRequest()) {
            return request()->query();
        }
        parse_str((string) parse_url((string) request()->header('Referer'), PHP_URL_QUERY), $query);

        return $query;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }
}
