{{-- Top list of the PK95 DNS overview. $rows: [{label, sub?, count, note?, href?}].
     Own names: @include shares the variables of the including view. --}}
@php $topMax = collect($rows)->max('count') ?: 0; @endphp
<x-panel class="m-3">
    <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $title }}</div>
    @if (! $rows)
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Keine Daten in diesem Zeitraum</p>
    @else
        <ol class="mt-3 flex flex-col gap-2 text-sm">
            @foreach ($rows as $row)
                <li>
                    <div class="flex items-baseline justify-between gap-3">
                        <div class="min-w-0 truncate">
                            @if (! empty($row['href']))
                                <a href="{{ $row['href'] }}" wire:navigate title="{{ $row['label'] }}" class="text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $row['label'] }}</a>
                            @else
                                <span class="text-gray-900 dark:text-gray-100" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                            @endif
                            @if (! empty($row['sub']))
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $row['sub'] }}</span>
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
</x-panel>
