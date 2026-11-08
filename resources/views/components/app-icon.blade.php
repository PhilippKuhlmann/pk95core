@props(['app', 'installed'])

{{-- Icon of an app from the ProxApps catalog, fetched through Core (the app API needs the token). --}}
<span {{ $attributes->merge(['class' => 'flex shrink-0 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-600 dark:bg-gray-900']) }}>
    @if ($app->iconPath)
        <img src="{{ route('apps.remote-icon', [$installed, $app->id]) }}" alt="" class="h-full w-full object-contain" loading="lazy" />
    @else
        <x-icon name="box" class="h-2/3 w-2/3 text-cerulean-600 dark:text-cerulean-400" />
    @endif
</span>
