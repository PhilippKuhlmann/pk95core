<?php

namespace App\Modules\Concerns;

use Livewire\Attributes\Locked;

/**
 * A page that loads deferred: the first request answers at once with a skeleton in the shape
 * of the page (static::PLACEHOLDER, drawn by partials/skeleton.blade.php), the data follow in
 * a Livewire request. Module pages are deferred centrally (apps/module-page.blade.php);
 * Core pages add #[Defer] themselves.
 *
 * PLACEHOLDER: list of parts, e.g. [['header', 'Hosts', 1], ['filters', 2], ['table', 8, 5]].
 *
 * Query values a page reads in mount() without #[Url] are named in static::QUERY: the first
 * request reads them from the page URL and hands them over as $pageQuery (ModulePageController),
 * because mount() runs later, in a Livewire request with its own URL. #[Url] properties need
 * nothing: Livewire takes them from the first request into the snapshot.
 */
trait LoadsLazily
{
    /** @var array<string, mixed> the values of static::QUERY from the page URL */
    #[Locked]
    public array $pageQuery = [];

    public function placeholder(array $params = [])
    {
        return view('partials.skeleton', ['parts' => static::PLACEHOLDER]);
    }

    /** A value of the page URL named in static::QUERY. */
    protected function fromUrl(string $key, mixed $default = null): mixed
    {
        return $this->pageQuery[$key] ?? $default;
    }

    protected function inUrl(string $key): bool
    {
        return array_key_exists($key, $this->pageQuery);
    }

    /**
     * The values of static::QUERY in a query string (strings and lists of strings only).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public static function pageQueryFrom(array $query): array
    {
        if (! defined(static::class.'::QUERY')) {
            return [];
        }
        $plain = fn ($v) => is_string($v) || (is_array($v) && array_is_list($v) && array_filter($v, 'is_string') === $v);

        return array_filter(array_intersect_key($query, array_flip(static::QUERY)), $plain);
    }
}
