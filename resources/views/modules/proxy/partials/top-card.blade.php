{{-- Top list of the PK95 Proxy overview: name (optionally linked), count and a bar.
     $items: [{key, count}], $topLabel/$topHref: optional closures, $topUnknown: configured hostnames.
     Own names: @include shares the variables of the including view. --}}
@php
    $max = collect($items)->max('count') ?: 0;
    $topLabel = $topLabel ?? fn ($k) => $k;
    $topHref = $topHref ?? null;
    $topUnknown = $topUnknown ?? null;
@endphp
<x-panel class="m-3">
    <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $title }}</div>
    @if (! $items)
        <p class="mt-2 text-sm text-gray-500">Keine Daten</p>
    @else
        <ol class="mt-3 flex flex-col gap-2 text-sm">
            @foreach ($items as $it)
                @php $url = $topHref && $it['key'] !== '' ? $topHref($it['key']) : null; @endphp
                <li>
                    <div class="flex items-baseline justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-2">
                            @if ($url)
                                <a href="{{ $url }}" wire:navigate data-tip="{{ $it['key'] }}" class="truncate text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $topLabel($it['key']) }}</a>
                            @else
                                <span class="truncate" data-tip="{{ $it['key'] }}">{{ $topLabel($it['key']) }}</span>
                            @endif
                            @if ($topUnknown && $it['key'] !== '' && ! in_array($it['key'], $topUnknown, true))
                                <span class="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300" data-tip="Dieser Hostname ist nicht eingerichtet. Solche Anfragen erhalten eine 404.">unbekannt<span class="sr-only">: Dieser Hostname ist nicht eingerichtet. Solche Anfragen erhalten eine 404.</span></span>
                            @endif
                        </div>
                        <span class="shrink-0 tabular-nums text-gray-500">{{ \App\Modules\Proxy\Fmt::num($it['count']) }}</span>
                    </div>
                    <div class="mt-1 h-1.5 rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-1.5 rounded-full bg-cerulean-500" style="width: {{ $max ? round($it['count'] / $max * 100, 1) : 0 }}%"></div></div>
                </li>
            @endforeach
        </ol>
    @endif
</x-panel>
