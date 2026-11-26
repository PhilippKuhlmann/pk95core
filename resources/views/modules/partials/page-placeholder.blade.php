{{-- Shown until a lazy module page has loaded (App\Modules\Concerns\LoadsLazily): the page title,
     a spinner with what is loading and skeleton panels in the shape of the page. --}}
<div aria-busy="true" data-page-placeholder>
    <x-page-header :title="$title" />

    <div class="mx-3 mt-2 flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300" role="status">
        <span class="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-cerulean-200 border-t-cerulean-600 dark:border-cerulean-900 dark:border-t-cerulean-400" aria-hidden="true"></span>
        {{ $text }}
    </div>

    @for ($i = 0; $i < $panels; $i++)
        <x-panel class="m-3" aria-hidden="true">
            <div class="h-4 w-40 animate-pulse rounded bg-gray-100 dark:bg-gray-700"></div>
            <div class="mt-3 h-3 w-full max-w-2xl animate-pulse rounded bg-gray-100 dark:bg-gray-700"></div>
            <div class="mt-2 h-3 w-2/3 max-w-xl animate-pulse rounded bg-gray-100 dark:bg-gray-700"></div>
            <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                @for ($j = 0; $j < 4; $j++)
                    <div class="h-8 animate-pulse rounded-md bg-gray-100 dark:bg-gray-700"></div>
                @endfor
            </div>
        </x-panel>
    @endfor
</div>
