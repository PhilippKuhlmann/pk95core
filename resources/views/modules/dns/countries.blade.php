@php
    use App\Modules\Dns\Countries;
    use App\Modules\Dns\CountrySplit;
    use App\Modules\Dns\Fmt;
    use App\Modules\Dns\Overview;
    use App\Support\Countries as CountryNames;
    $muted = 'text-xs text-gray-500 dark:text-gray-400';
    // allow mode: countries with traffic that are not checked would be cut off
    $cutOff = fn (array $r) => $mode === 'allow' && $r['passed'] > 0 && ! in_array($r['code'], $selected, true);
    $cutCount = collect($rows)->filter($cutOff)->count();
    $num = 'px-2 py-2 text-right align-top tabular-nums';
@endphp

<div>
    <x-page-header title="Länder">
        <x-slot:sub>In welche Länder die Anfragen im Netz gehen: durchgelassen oder von den Blocklisten gestoppt.</x-slot:sub>
    </x-page-header>

    @if ($unsupported)
        <x-panel class="m-3" data-geo-unsupported>
            <p class="text-sm text-gray-600 dark:text-gray-300">Ab PK95 DNS {{ Countries::MIN_VERSION }} verfügbar: Länder der Antworten und ein Länderfilter.</p>
        </x-panel>
    @elseif ($loadError)
        <x-callout title="PK95 DNS antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @elseif ($geo)
        @if (($geo['available'] ?? true) === false)
            <x-callout title="Länder-Datenbank" ton="gelb" class="m-3" data-geo-loading>Die Länder-Datenbank wird noch geladen. Bis dahin kennt PK95 DNS keine Länder.</x-callout>
        @endif

        {{-- the overview first: where the queries go --}}
        <x-panel class="m-3" polster="keins" data-country-table>
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
                <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Länder im Netz</div>
                <div class="flex flex-wrap gap-1" role="group" aria-label="Zeitraum">
                    @foreach (Overview::RANGES as $h => $label)
                        <button type="button" wire:click="$set('hours', {{ $h }})" aria-pressed="{{ $hours === $h ? 'true' : 'false' }}"
                            @class(['rounded-lg px-3 py-1.5 text-sm transition-colors', 'bg-cerulean-600 text-white' => $hours === $h,
                                'border border-gray-200 bg-white text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700' => $hours !== $h])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 pt-3">
                <x-input.search wire:model.live.debounce.250ms="q" placeholder="Länder durchsuchen …" class="w-full sm:w-64" />
                @if ($split)
                    <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 text-sm text-gray-700 sm:min-h-0 dark:text-gray-300">
                        <input type="checkbox" wire:model.live="onlyBlocked" class="h-4 w-4 rounded border-gray-300 text-cerulean-600 focus:ring-cerulean-500 dark:border-gray-600 dark:bg-gray-900">
                        Nur geblockte
                    </label>
                @endif
                <div class="flex gap-3 {{ $muted }}">
                    <span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-sm bg-green-500"></i>durchgelassen</span>
                    @if ($split)<span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-sm bg-rose-500"></i>geblockt</span>@endif
                </div>
            </div>
            <x-input.fehler feld="countries" class="px-4 pt-2" />

            @if ($rows === [] && (trim($q) !== '' || $onlyBlocked))
                <div class="m-4 flex flex-col items-center gap-2 rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center dark:border-gray-600" data-search-empty>
                    <p class="text-sm text-gray-700 dark:text-gray-300">
                        @if (trim($q) !== '') Keine Länder für „<span class="font-medium">{{ trim($q) }}</span>“ gefunden. @else Keine geblockten Anfragen in diesem Zeitraum. @endif
                    </p>
                    <button type="button" wire:click="resetSearch" class="text-sm font-medium text-cerulean-700 hover:underline dark:text-cerulean-300">Suche zurücksetzen</button>
                </div>
            @elseif ($rows === [])
                <p class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400" data-no-countries>In diesem Zeitraum gab es keine Antworten mit Länderangabe.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-y border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <tr>
                                @if ($showFilter)<th class="w-10 px-4 py-2"><span class="sr-only">Für den Länderfilter ausgewählt</span></th>@endif
                                <th @class(['py-2', 'px-2' => $showFilter, 'px-4' => ! $showFilter])>Land</th>
                                <th class="px-2 py-2 text-right">Durchgelassen</th>
                                @if ($split)<th class="px-2 py-2 text-right" data-col-list-blocked>Geblockt <span class="normal-case">(Listen)</span></th>@endif
                                @if ($filterColumn)<th class="px-2 py-2 text-right" data-col-filter>Länderfilter</th>@endif
                                <th class="hidden px-4 py-2 text-right sm:table-cell">Entfernt</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($rows as $r)
                                @php
                                    $cut = $cutOff($r);
                                    $badge = Countries::badge($r['code'], ['mode' => $mode], $selected);
                                @endphp
                                <tr wire:key="geo-{{ $r['code'] }}" data-country="{{ $r['code'] }}" @class(['bg-rose-50 dark:bg-rose-900/20' => $cut]) @if ($cut) data-cut-off @endif>
                                    @if ($showFilter)
                                        <td class="px-4 py-2 align-top">
                                            <input type="checkbox" value="{{ $r['code'] }}" wire:model.live="selected" aria-label="{{ CountryNames::name($r['code']) }} für den Länderfilter auswählen"
                                                class="h-4 w-4 rounded border-gray-300 text-cerulean-600 focus:ring-cerulean-500 dark:border-gray-600 dark:bg-gray-900">
                                        </td>
                                    @endif
                                    <td @class(['min-w-36 py-2 align-top', 'px-2' => $showFilter, 'px-4' => ! $showFilter])>
                                        <span class="font-medium text-gray-900 dark:text-gray-100"><span aria-hidden="true">{{ CountryNames::flag($r['code']) }}</span> {{ CountryNames::name($r['code']) }}</span>
                                        @if ($badge)
                                            <span @class(['ml-1 inline-block rounded px-1.5 py-0.5 text-[11px] font-semibold', 'bg-rose-100 text-rose-800 dark:bg-rose-900/70 dark:text-rose-100' => $badge === 'blockiert' || $cut,
                                                'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => $badge !== 'blockiert' && ! $cut]) data-badge>{{ $badge }}</span>
                                        @endif
                                        <x-input.fehler :feld="'country.'.$r['code']" class="mt-1" />
                                        @include('modules.dns.partials.country-bar', ['row' => $r, 'max' => $max, 'split' => $split])
                                    </td>
                                    <td @class([$num, 'text-green-700 dark:text-green-300' => ! $cut, 'font-medium text-rose-700 dark:text-rose-300' => $cut]) data-passed>{{ Fmt::num($r['passed']) }}</td>
                                    @if ($split)<td class="{{ $num }} text-rose-700 dark:text-rose-300" data-list-blocked>{{ Fmt::num($r['list_blocked']) }}</td>@endif
                                    @if ($filterColumn)<td class="{{ $num }} text-gray-700 dark:text-gray-300" data-filter-blocked>{{ Fmt::num($r['filter_blocked']) }}</td>@endif
                                    <td class="hidden px-4 py-2 text-right align-top tabular-nums text-gray-700 sm:table-cell dark:text-gray-300">{{ Fmt::num($r['removed']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p class="px-4 py-3 {{ $muted }}">
                {{ $total === 1 ? '1 Land' : $total.' Länder' }}, sortiert nach allen Anfragen{{ $unknown > 0 ? ' · '.Fmt::num($unknown).' Antworten ohne Land (private oder unbekannte Adressen)' : '' }}{{ $unknownBlocked > 0 ? ' · '.Fmt::num($unknownBlocked).' geblockt ohne bekanntes Land' : '' }}.
                @if (! $split) Geblockte Länder ab PK95 DNS {{ CountrySplit::MIN_VERSION }}. @endif
                „Entfernt“: einzelne Adressen, die aus sonst erlaubten Antworten genommen wurden.
            </p>
            <p class="flex gap-2 px-4 pb-4 {{ $muted }}">
                <x-icon name="info" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>Das Land ist der Standort des antwortenden Servers. Große Dienste nutzen CDNs, dann steht oft Deutschland oder ein Nachbarland da, nicht das Herkunftsland des Anbieters.</span>
            </p>
        </x-panel>

        {{-- PK95 DNS 1.3.0: the country of blocked domains needs a lookup in the background --}}
        @if ($resolveBlocked !== null)
            <x-panel class="m-3" data-resolve-blocked>
                <x-input.checkbox label="Länder geblockter Domains ermitteln" wire:model.live="resolveBlocked"
                    hilfe="PK95 DNS fragt geblockte Domains im Hintergrund beim Upstream nach, um ihr Land zu bestimmen. Es wird keine Verbindung zu diesen Servern aufgebaut." />
            </x-panel>
        @endif

        {{-- the country filter is an option, not the point of the page --}}
        <form wire:submit="save">
            <x-panel class="m-3" data-geo-mode>
                <button type="button" wire:click="toggleFilter" aria-expanded="{{ $showFilter ? 'true' : 'false' }}" class="flex w-full items-center justify-between gap-3 text-left" data-filter-toggle>
                    <span>
                        <span class="block font-DINPro-bold text-gray-900 dark:text-gray-100">Länderfilter (optional)</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">Länder ganz blockieren oder nur bestimmte erlauben · zurzeit {{ Countries::modeLine($saved['mode'], count($saved['countries'])) }}</span>
                    </span>
                    <x-icon name="chevron-down" :class="'h-4 w-4 shrink-0 text-gray-400 transition-transform'.($showFilter ? ' rotate-180' : '')" />
                </button>

                @if ($showFilter)
                    <fieldset class="mt-4">
                        <legend class="sr-only">Modus</legend>
                        <div class="inline-flex flex-wrap rounded-lg border border-gray-300 bg-white p-0.5 max-sm:flex max-sm:w-full dark:border-gray-600 dark:bg-gray-800">
                            @foreach (Countries::MODES as $value => $label)
                                <label class="cursor-pointer max-sm:w-full">
                                    <input type="radio" class="peer sr-only" name="geo-mode" value="{{ $value }}" wire:model.live="mode">
                                    <span class="flex min-h-9 items-center justify-center rounded-md px-3 py-1.5 text-sm text-gray-600 transition-colors peer-checked:bg-cerulean-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-cerulean-500 max-sm:min-h-11 dark:text-gray-300">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <x-input.fehler feld="mode" class="mt-1" />

                    @if ($mode === 'allow')
                        <x-callout title="Achtung" ton="gelb" class="mt-3" data-allow-warning>
                            Alle anderen Länder werden blockiert, auch CDNs und Updates. Erst die Tabelle oben prüfen.
                            @if ($cutCount > 0)
                                <span class="mt-1 block font-medium">{{ $cutCount === 1 ? '1 Land mit Anfragen wäre' : $cutCount.' Länder mit Anfragen wären' }} dann blockiert (rot markiert).</span>
                            @endif
                        </x-callout>
                    @endif

                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Die Länder wählst du oben in der Tabelle aus. Private und unbekannte Adressen bleiben immer erlaubt.</p>
                    <div class="mt-3 flex flex-wrap items-end justify-between gap-2">
                        <x-input.combobox wire:model.live="addCode" label="Land ohne Anfragen hinzufügen" :options="$addOptions" placeholder="Land hinzufügen …" :search="true" class="w-full sm:w-64" />
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-sm text-gray-600 dark:text-gray-300" data-selected-count>
                                @if ($mode === 'off') Ausgewählt: {{ count($selected) === 1 ? '1 Land' : count($selected).' Länder' }} (Filter aus)
                                @else Länderfilter: {{ Countries::modeLine($mode, count($selected)) }}
                                @endif
                            </span>
                            <x-input.button type="submit" wire:loading.attr="disabled" wire:target="save">Speichern</x-input.button>
                        </div>
                    </div>
                @endif
            </x-panel>
        </form>

        {{-- required by the licence of the database (CC BY 4.0) --}}
        @php $attribution = (string) ($geo['attribution'] ?? 'IP-Geolocation by DB-IP (https://db-ip.com)'); @endphp
        <p class="mx-3 mb-6 {{ $muted }}" data-geo-attribution>
            {{ preg_replace('#\s*\(https://db-ip\.com\)#', '', $attribution) }} (<a href="https://db-ip.com" target="_blank" rel="noopener" class="underline">db-ip.com</a>, CC BY 4.0){{ ($geo['db_date'] ?? '') !== '' ? ' · Stand '.\Illuminate\Support\Carbon::parse($geo['db_date'])->format('d.m.Y') : '' }}
        </p>
    @endif
</div>
