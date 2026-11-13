{{-- Shown until a lazy dashboard tile has loaded. --}}
<div>
    <x-panel aria-busy="true">
        <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $title }}</div>
        <div class="mt-3 space-y-2" aria-hidden="true">
            <div class="h-4 w-2/3 animate-pulse rounded bg-gray-100 dark:bg-gray-700"></div>
            <div class="h-4 w-1/2 animate-pulse rounded bg-gray-100 dark:bg-gray-700"></div>
        </div>
        <span class="sr-only">Wird geladen …</span>
    </x-panel>
</div>
