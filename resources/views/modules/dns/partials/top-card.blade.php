{{-- Top list of the PK95 DNS overview. $rows: [{label, sub?, count, note?, href?, badge?}],
     optional $footer {text, href?, link?} and $dataAttr (a data attribute on the title).
     Own names: @include shares the variables of the including view. --}}
@php $topMax = collect($rows)->max('count') ?: 0; @endphp
<x-panel class="m-3">
    <div class="font-DINPro-bold text-gray-900 dark:text-gray-100" @if (! empty($dataAttr)) {{ $dataAttr }} @endif>{{ $title }}</div>
    @if (! $rows)
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Keine Daten in diesem Zeitraum</p>
    @else
        <ol class="mt-3 flex flex-col gap-2 text-sm">
            @foreach ($rows as $row)
                <li>
                    <div class="flex items-baseline justify-between gap-3">
                        <div class="min-w-0 truncate">
                            @if (! empty($row['href']))
                                <a href="{{ $row['href'] }}" wire:navigate data-tip="{{ $row['label'] }}" class="text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $row['label'] }}</a>
                            @else
                                <span class="text-gray-900 dark:text-gray-100" data-tip="{{ $row['label'] }}">{{ $row['label'] }}</span>
                            @endif
                            @if (! empty($row['sub']))
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $row['sub'] }}</span>
                            @endif
                            @if (! empty($row['badge']))
                                <span class="ml-1 inline-block rounded bg-rose-100 px-1.5 py-0.5 text-[11px] font-semibold text-rose-800 dark:bg-rose-900/70 dark:text-rose-100" data-badge>{{ $row['badge'] }}</span>
                            @endif
                        </div>
                        <span class="shrink-0 tabular-nums text-gray-600 dark:text-gray-300">
                            {{ \App\Modules\Dns\Fmt::num($row['count']) }}
                            @if (! empty($row['note']))<span class="text-xs text-gray-500 dark:text-gray-400">· {{ $row['note'] }}</span>@endif
                        </span>
                    </div>
                    <div class="mt-1 h-1.5 rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-1.5 rounded-full {{ $barClass ?? 'bg-cerulean-500' }}" style="width: {{ $topMax ? round($row['count'] / $topMax * 100, 1) : 0 }}%"></div></div>
                </li>
            @endforeach
        </ol>
    @endif
    @if (! empty($footer))
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-2 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
            <span>{{ $footer['text'] }}</span>
            @if (! empty($footer['href']))
                <a href="{{ $footer['href'] }}" wire:navigate class="font-medium text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $footer['link'] ?? 'Mehr' }}</a>
            @endif
        </div>
    @endif
</x-panel>
