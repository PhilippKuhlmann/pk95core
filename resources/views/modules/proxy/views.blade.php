@php
    use App\Modules\Proxy\Daily;
    use App\Modules\Proxy\Fmt;
    $logUrl = route('apps.page', [$installedApp, 'log']);
    $switch = fn (bool $on) => $on ? 'bg-cerulean-600 text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700';
    $ariaSort = fn (string $key) => $sort === $key ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $arrow = fn (string $key) => $sort === $key ? ($dir === 'asc' ? '↑' : '↓') : '';
    $sortBtn = 'inline-flex items-center gap-1 rounded hover:text-gray-900 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-cerulean-500 dark:hover:text-white';
    $sticky = 'sticky left-0 z-10 bg-white dark:bg-gray-800';
    $metricLabel = Daily::METRICS[$metric];
    $hiddenCount = count($data['hidden']);
@endphp

<div wire:poll.60s>
    <x-page-header title="Aufrufe">
        <x-slot:sub>
            Echte Seitenaufrufe pro Host und Tag{{ $data['computed_at'] ? ' · Stand '.Fmt::time($data['computed_at']) : '' }}
        </x-slot:sub>
        <div class="flex rounded-lg border border-gray-200 bg-white p-0.5 dark:border-gray-700 dark:bg-gray-800" role="group" aria-label="Zeitraum">
            @foreach (Daily::RANGES as $n => $label)
                <button type="button" wire:click="setDays({{ $n }})" aria-pressed="{{ $days === $n ? 'true' : 'false' }}"
                    @class(['rounded-md px-3 py-1 text-sm', $switch($days === $n)])>{{ $label }}</button>
            @endforeach
        </div>
    </x-page-header>

    <div class="mx-3 mt-2 flex flex-wrap items-center gap-x-4 gap-y-2">
        <x-input.combobox wire:model.live="host" label="Host" :options="$hostOptions" placeholder="Alle Hosts" reset="Alle Hosts" :search="true" class="w-full sm:w-72" />
        <div class="flex max-w-full overflow-x-auto rounded-lg border border-gray-200 bg-white p-0.5 dark:border-gray-700 dark:bg-gray-800" role="group" aria-label="Kennzahl">
            @foreach (Daily::METRICS as $key => $label)
                <button type="button" wire:click="setMetric('{{ $key }}')" aria-pressed="{{ $metric === $key ? 'true' : 'false' }}"
                    @class(['whitespace-nowrap rounded-md px-3 py-1 text-sm', $switch($metric === $key)])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- what is left out, so the numbers are not mistaken for all requests --}}
    <p class="mx-3 mt-2 text-xs text-gray-500 dark:text-gray-400" data-left-out>
        @if ($hiddenCount)
            Ohne Bots und ohne {{ $hiddenCount === 1 ? 'den ausgeblendeten Client' : $hiddenCount.' ausgeblendete Clients' }}
            <span class="text-gray-700 dark:text-gray-300">{{ implode(', ', $data['hidden']) }}</span>
            (<a href="{{ $logUrl }}" wire:navigate class="text-cerulean-700 hover:underline dark:text-cerulean-300">ändern unter Zugriffe</a>).
        @else
            Ohne Bots.
        @endif
        @if ($data['since'])
            Seitenaufrufe werden seit {{ \Illuminate\Support\Carbon::parse($data['since'])->format('d.m.Y') }} gezählt, frühere Tage sind geschätzt (<i>kursiv</i>).
        @endif
    </p>

    @if ($data['missing'])
        <x-callout title="Braucht PK95 Proxy {{ Daily::MIN_VERSION }}" class="m-3" data-daily-missing>
            Die Aufrufe pro Host und Tag zählt PK95 Proxy ab Version {{ Daily::MIN_VERSION }}. Aktualisiere PK95 Proxy unter App-Details.
        </x-callout>
    @elseif ($data['error'] !== null)
        <x-callout title="Aufrufe konnten nicht geladen werden" ton="gelb" class="m-3">{{ $data['error'] }}</x-callout>
    @elseif (! $rows)
        <x-panel class="m-3">
            <p class="text-center text-sm text-gray-500 dark:text-gray-400">{{ $host !== '' ? 'Für '.$host.' gibt es in diesem Zeitraum keine Aufrufe.' : 'In diesem Zeitraum gibt es keine Aufrufe.' }}</p>
        </x-panel>
    @else
        <x-panel class="relative m-3 overflow-x-auto" polster="keins">
            <table class="w-full border-separate border-spacing-0 text-right text-sm tabular-nums" data-views-table>
                <caption class="sr-only">{{ $metricLabel }} pro Host und Tag, letzte {{ $days }} Tage</caption>
                <thead class="text-xs text-gray-500 dark:text-gray-400">
                    <tr>
                        <th scope="col" aria-sort="{{ $ariaSort('host') }}" class="{{ $sticky }} border-b border-gray-200 px-3 py-2 text-left font-medium dark:border-gray-700">
                            <button type="button" wire:click="sortBy('host')" class="{{ $sortBtn }}">Host <span aria-hidden="true">{{ $arrow('host') }}</span></button>
                        </th>
                        @foreach ($dates as $day)
                            <th scope="col" aria-sort="{{ $ariaSort($day) }}" @class(['border-b border-gray-200 px-2 py-2 font-medium dark:border-gray-700', 'italic' => $estimated[$day]])
                                @if ($estimated[$day]) data-tip="{{ ucfirst(Daily::ESTIMATED) }}" @endif>
                                <button type="button" wire:click="sortBy('{{ $day }}')" class="inline-flex flex-col items-end rounded leading-tight hover:text-gray-900 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-cerulean-500 dark:hover:text-white">
                                    <span>{{ Daily::weekday($day) }}</span><span class="whitespace-nowrap">{{ Daily::label($day) }}<span aria-hidden="true">{{ $arrow($day) }}</span></span>
                                    @if ($estimated[$day])<span class="sr-only">, {{ Daily::ESTIMATED }}</span>@endif
                                </button>
                            </th>
                        @endforeach
                        <th scope="col" aria-sort="{{ $ariaSort('total') }}" class="border-b border-gray-200 px-3 py-2 font-medium dark:border-gray-700">
                            <button type="button" wire:click="sortBy('total')" class="{{ $sortBtn }}">Summe <span aria-hidden="true">{{ $arrow('total') }}</span></button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr wire:key="views-{{ md5($r['host']) }}">
                            <th scope="row" class="{{ $sticky }} max-w-40 truncate border-b border-gray-100 px-3 py-1.5 text-left font-medium sm:max-w-64 dark:border-gray-700/60">
                                <a href="{{ $logUrl }}?host={{ urlencode($r['host']) }}" wire:navigate data-tip="{{ $r['host'] }}: Zugriffe ansehen"
                                    class="text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $r['host'] }}</a>
                            </th>
                            @foreach ($r['values'] as $day => $n)
                                @php $p = $r['series'][$day] ?? null; @endphp
                                <td @class(['border-b border-gray-100 px-2 py-1.5 dark:border-gray-700/60', Daily::heat($n, $max), 'italic' => $p['estimated'] ?? false])
                                    data-tip="{{ $p ? Daily::tip($day, $p) : Daily::label($day).': keine Aufrufe' }}">
                                    @if ($n > 0){{ Fmt::num($n) }}@else<span class="text-gray-300 dark:text-gray-600">–</span>@endif
                                </td>
                            @endforeach
                            <td class="border-b border-gray-100 px-3 py-1.5 font-semibold text-gray-900 dark:border-gray-700/60 dark:text-gray-100">{{ Fmt::num($r['total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="font-semibold text-gray-900 dark:text-gray-100">
                    <tr>
                        <th scope="row" class="{{ $sticky }} px-3 py-2 text-left">Summe</th>
                        @foreach ($dayTotals as $day => $n)
                            <td @class(['px-2 py-2', 'italic' => $estimated[$day]])>{{ Fmt::num($n) }}</td>
                        @endforeach
                        <td class="px-3 py-2">{{ Fmt::num($total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </x-panel>
        <div class="mx-3 mb-4 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400" aria-hidden="true">
            wenig
            @foreach (array_slice(Daily::HEAT, 1) as $heat)
                <span class="h-3 w-5 rounded-sm border border-gray-200 dark:border-gray-700 {{ $heat }}"></span>
            @endforeach
            viel {{ $metricLabel }} pro Tag
        </div>
    @endif
</div>
