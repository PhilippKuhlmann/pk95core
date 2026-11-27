<?php

namespace App\Modules\Concerns;

/**
 * A page that loads deferred: the first request answers at once with a skeleton in the shape
 * of the page (static::PLACEHOLDER, drawn by partials/skeleton.blade.php), the data follow in
 * a Livewire request. Module pages are deferred centrally (apps/module-page.blade.php);
 * Core pages add #[Defer] or ->defer() themselves.
 *
 * PLACEHOLDER: list of parts, e.g. [['header', 'Hosts', 1], ['filters', 2], ['table', 8, 5]].
 */
trait LoadsLazily
{
    public function placeholder(array $params = [])
    {
        return view('partials.skeleton', ['parts' => static::PLACEHOLDER]);
    }
}
