{{-- "Wohin gehen deine Daten?": countries of PK95 DNS (last 24 h), let through and blocked.
     No card without PK95 DNS or before 1.2.0 (no countries); before 1.3.0 only "durchgelassen". --}}
@php
    use App\Modules\Dns\CountrySplit;
    use App\Modules\Dns\Fmt;
    use App\Support\Countries;
    $rows = $countries ? array_slice($countries['rows'], 0, \App\Livewire\Dashboard\CountriesCard::LIMIT) : [];
    $max = collect($rows)->max('total') ?: 0;
    $split = (bool) ($countries['split'] ?? false);
@endphp
<div wire:poll.60s>
    @if ($app && $countries)
        <x-panel class="mt-4" data-countries-card>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <div class="flex items-center gap-2 font-DINPro-bold text-gray-900 dark:text-gray-100">
                    <x-icon name="globe" class="h-5 w-5 shrink-0 text-cerulean-600 dark:text-cerulean-400" />Wohin gehen deine Daten?
                </div>
                @if ($countries['available'] && $rows)
                    <div class="text-sm text-gray-600 dark:text-gray-300" data-countries-summary>
                        {{ $countries['reached'] >= 20 ? 'Mindestens 20' : $countries['reached'] }} {{ $countries['reached'] === 1 ? 'Land' : 'Länder' }} erreicht
                        @if ($split)
                            · <span class="font-medium text-rose-700 dark:text-rose-300">{{ Fmt::pct(CountrySplit::blockedPct($countries['rows'])) }} geblockt</span>
                        @endif
                    </div>
                @endif
            </div>

            @if (! $countries['available'])
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300" data-geo-loading>Die Länder-Datenbank wird geladen. Danach zeigt PK95 DNS hier, in welche Länder die Anfragen gehen.</p>
            @elseif (! $rows)
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">In den letzten 24 Stunden gab es keine Antworten mit Länderangabe.</p>
            @else
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                    <span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-sm bg-green-500"></i>durchgelassen</span>
                    @if ($split)
                        <span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-sm bg-rose-500"></i>geblockt</span>
                    @endif
                </div>
                <ol class="mt-3 grid grid-cols-1 gap-x-8 gap-y-2.5 text-sm lg:grid-cols-2">
                    @foreach ($rows as $row)
                        <li data-country-row="{{ $row['code'] }}">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="min-w-0 truncate text-gray-900 dark:text-gray-100"><span aria-hidden="true">{{ Countries::flag($row['code']) }}</span> {{ Countries::name($row['code']) }}</span>
                                <span class="shrink-0 text-xs tabular-nums text-gray-600 dark:text-gray-300">
                                    <span class="text-green-700 dark:text-green-300" data-passed-count>{{ Fmt::num($row['passed']) }}</span>
                                    @if ($split)<span class="text-gray-400"> · </span><span class="text-rose-700 dark:text-rose-300" data-blocked-count>{{ Fmt::num($row['blocked']) }}</span>@endif
                                </span>
                            </div>
                            @include('modules.dns.partials.country-bar', ['row' => $row, 'max' => $max, 'split' => $split])
                        </li>
                    @endforeach
                </ol>
            @endif

            <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-2 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                <span>
                    letzte 24 Stunden
                    @if ($split && $countries['unknownBlocked'] > 0) · {{ Fmt::num($countries['unknownBlocked']) }} geblockt ohne bekanntes Land @endif
                    @if (! $split) · Geblockte Länder ab PK95 DNS {{ CountrySplit::MIN_VERSION }} @endif
                </span>
                <a href="{{ route('apps.page', [$app, 'countries']) }}" wire:navigate class="font-medium text-cerulean-700 hover:underline dark:text-cerulean-300">Alle Länder</a>
            </div>
        </x-panel>
    @endif
</div>
