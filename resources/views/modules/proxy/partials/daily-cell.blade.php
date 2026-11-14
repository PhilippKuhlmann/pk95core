{{--
    Page views of one host on Proxy → Hosts: "today / 7 days" and seven small bars with a
    tooltip per day. $v = Daily::forDomains(…); estimated days are lighter.
--}}
@php
    use App\Modules\Proxy\Daily;
    use App\Modules\Proxy\Fmt;
    $days = array_keys($v['series']);
    $today = $v['series'][end($days)];
    $max = max(1, ...array_map(fn ($p) => $p['pages'], array_values($v['series'])));
    $spoken = implode(', ', array_map(fn ($d) => Daily::label($d).' '.Fmt::num($v['series'][$d]['pages']), $days));
@endphp
<div class="flex items-center justify-between gap-3 md:block">
    {{-- one line on the card, two in the table (narrow column) --}}
    <div class="whitespace-nowrap">
        <span class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ Fmt::num($today['pages']) }}</span> heute
        <span class="text-gray-500 md:block dark:text-gray-400"><span class="md:hidden">/ </span><span class="tabular-nums">{{ Fmt::num($v['total']['pages']) }}</span> in 7 Tagen</span>
    </div>
    <div class="flex h-6 items-end gap-0.5 md:mt-1" role="img" aria-label="Seitenaufrufe pro Tag: {{ $spoken }}" data-daily-bars>
        @foreach ($v['series'] as $day => $p)
            <span class="flex h-full w-2 items-end max-md:w-3" data-tip="{{ Daily::tip($day, $p) }}">
                <span @class(['w-full rounded-sm', 'bg-cerulean-500' => $p['pages'] > 0, 'bg-gray-200 dark:bg-gray-700' => $p['pages'] === 0, 'opacity-50' => $p['estimated']])
                    style="height: {{ $p['pages'] > 0 ? max(12, (int) round(100 * $p['pages'] / $max)) : 8 }}%"></span>
            </span>
        @endforeach
    </div>
</div>
