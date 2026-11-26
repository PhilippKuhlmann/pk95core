<?php

namespace App\Modules\Concerns;

/**
 * For module pages that wait on their app for a while: with #[Defer] on the class the page opens
 * at once with a skeleton in its shape and a spinner, the data follow in a second request.
 * Only for slow pages: a fast one would just flash the skeleton.
 *
 * The class defines PLACEHOLDER = [title, text, panels].
 */
trait LoadsLazily
{
    public function placeholder()
    {
        [$title, $text, $panels] = static::PLACEHOLDER + [2 => 2];

        return view('modules.partials.page-placeholder', ['title' => $title, 'text' => $text, 'panels' => $panels]);
    }
}
