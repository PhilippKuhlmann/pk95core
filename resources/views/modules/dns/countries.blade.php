@php
    use App\Modules\Dns\Countries;
    use App\Modules\Dns\Fmt;
    use App\Modules\Dns\Overview;
    use App\Support\Countries as CountryNames;
    $muted = 'text-xs text-gray-500 dark:text-gray-400';
    $maxCount = collect($rows)->max('count') ?: 0;
    // allow mode: countries with traffic that are not checked would be cut off
    $cutOff = fn (array $r) => $mode === 'allow' && $r['count'] > 0 && ! in_array($r['code'], $selected, true);
    $cutCount = collect($rows)->filter($cutOff)->count();
@endphp

<div>
    <x-page-header title="Länder">
        <x-slot:sub>Mit welchen Ländern das Netz spricht und welche PK95 DNS blockiert.</x-slot:sub>
    </x-page-header>

    @if ($unsupported)
        <x-panel class="m-3" data-geo-unsupported>
            <p class="text-sm text-gray-600 dark:text-gray-300">Ab PK95 DNS {{ Countries::MIN_VERSION }} verfügbar: Länder der Antworten und ein Länderfilter.</p>
        </x-panel>
    @elseif ($loadError)
        <x-callout title="PK95 DNS antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @elseif ($geo)
        @if (($geo['available'] ?? true) === false)
            <x-callout title="Länder-Datenbank" ton="gelb" class="m-3" data-geo-loading>Die Länder-Datenbank wird noch geladen. Bis dahin kennt PK95 DNS keine Länder, der Filter greift noch nicht.</x-callout>
        @endif

        <form wire:submit="save">
            <x-panel class="m-3" data-geo-mode>
                <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Länderfilter</div>
                <fieldset class="mt-3">
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
                        Alle anderen Länder werden blockiert, auch CDNs und Updates. Erst die Tabelle unten prüfen.
                        @if ($cutCount > 0)
                            <span class="mt-1 block font-medium">{{ $cutCount === 1 ? '1 Land mit Anfragen wäre' : $cutCount.' Länder mit Anfragen wären' }} dann blockiert (rot markiert).</span>
                        @endif
                    </x-callout>
                @endif

                <p class="mt-3 flex gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <x-icon name="info" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <span>Das Land ist der Standort des antwortenden Servers. Große Dienste nutzen CDNs, dann steht oft Deutschland oder ein Nachbarland da, nicht das Herkunftsland des Anbieters. Private und unbekannte Adressen bleiben immer erlaubt.</span>
                </p>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                    <span class="text-sm text-gray-600 dark:text-gray-300" data-selected-count>
                        @if ($mode === 'off') Ausgewählt: {{ count($selected) === 1 ? '1 Land' : count($selected).' Länder' }} (Filter aus)
                        @else Länderfilter: {{ Countries::modeLine($mode, count($selected)) }}
                        @endif
                    </span>
                    <x-input.button type="submit" wire:loading.attr="disabled" wire:target="save">Speichern</x-input.button>
                </div>
            </x-panel>

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
                <div class="flex flex-wrap items-end gap-2 px-4 pt-3">
                    <x-input.search wire:model.live.debounce.250ms="q" placeholder="Länder durchsuchen …" class="w-full sm:w-64" />
                    <x-input.combobox wire:model.live="addCode" label="Land hinzufügen" :options="$addOptions" placeholder="Land hinzufügen …" :search="true" class="w-full sm:w-64" />
                </div>
                <x-input.fehler feld="countries" class="px-4 pt-2" />

                @if ($rows === [] && trim($q) !== '')
                    <div class="m-4 flex flex-col items-center gap-2 rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center dark:border-gray-600" data-search-empty>
                        <p class="text-sm text-gray-700 dark:text-gray-300">Keine Länder für „<span class="font-medium">{{ trim($q) }}</span>“ gefunden.</p>
                        <button type="button" wire:click="$set('q', '')" class="text-sm font-medium text-cerulean-700 hover:underline dark:text-cerulean-300">Suche zurücksetzen</button>
                    </div>
                @elseif ($rows === [])
                    <p class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400" data-no-countries>In diesem Zeitraum gab es keine Antworten mit Länderangabe. Länder ohne Anfragen lassen sich oben hinzufügen.</p>
                @else
                    <table class="mt-3 w-full text-left text-sm">
                        <thead class="border-y border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <tr>
                                <th class="w-10 px-4 py-2"><span class="sr-only">Ausgewählt</span></th>
                                <th class="px-2 py-2">Land</th>
                                <th class="px-2 py-2 text-right">Anfragen</th>
                                <th class="px-2 py-2 text-right">Blockiert</th>
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
                                    <td class="px-4 py-2 align-top">
                                        <input type="checkbox" value="{{ $r['code'] }}" wire:model.live="selected" aria-label="{{ CountryNames::name($r['code']) }} auswählen"
                                            class="h-4 w-4 rounded border-gray-300 text-cerulean-600 focus:ring-cerulean-500 dark:border-gray-600 dark:bg-gray-900">
                                    </td>
                                    <td class="px-2 py-2 align-top">
                                        <span class="font-medium text-gray-900 dark:text-gray-100"><span aria-hidden="true">{{ CountryNames::flag($r['code']) }}</span> {{ CountryNames::name($r['code']) }}</span>
                                        @if ($badge)
                                            <span @class(['ml-1 inline-block rounded px-1.5 py-0.5 text-[11px] font-semibold', 'bg-rose-100 text-rose-800 dark:bg-rose-900/70 dark:text-rose-100' => $badge === 'blockiert' || $cut,
                                                'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => $badge !== 'blockiert' && ! $cut]) data-badge>{{ $badge }}</span>
                                        @endif
                                        <x-input.fehler :feld="'country.'.$r['code']" class="mt-1" />
                                        <div class="mt-1 h-1.5 rounded-full bg-gray-100 dark:bg-gray-700"><div @class(['h-1.5 rounded-full', 'bg-rose-500' => $cut, 'bg-cerulean-500' => ! $cut]) style="width: {{ $maxCount ? round($r['count'] / $maxCount * 100, 1) : 0 }}%"></div></div>
                                    </td>
                                    <td @class(['px-2 py-2 text-right align-top tabular-nums', 'font-medium text-rose-700 dark:text-rose-300' => $cut])>{{ Fmt::num($r['count']) }}</td>
                                    <td class="px-2 py-2 text-right align-top tabular-nums text-gray-700 dark:text-gray-300">{{ Fmt::num($r['blocked']) }}</td>
                                    <td class="hidden px-4 py-2 text-right align-top tabular-nums text-gray-700 sm:table-cell dark:text-gray-300">{{ Fmt::num($r['removed']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                <p class="px-4 py-3 {{ $muted }}">
                    {{ $total === 1 ? '1 Land' : $total.' Länder' }}, sortiert nach Anfragen{{ $unknown > 0 ? ' · '.Fmt::num($unknown).' Antworten ohne Land (private oder unbekannte Adressen)' : '' }}.
                    „Entfernt“: einzelne Adressen, die aus sonst erlaubten Antworten genommen wurden.
                </p>
            </x-panel>
        </form>

        {{-- required by the licence of the database (CC BY 4.0) --}}
        @php $attribution = (string) ($geo['attribution'] ?? 'IP-Geolocation by DB-IP (https://db-ip.com)'); @endphp
        <p class="mx-3 mb-6 {{ $muted }}" data-geo-attribution>
            {{ preg_replace('#\s*\(https://db-ip\.com\)#', '', $attribution) }} (<a href="https://db-ip.com" target="_blank" rel="noopener" class="underline">db-ip.com</a>, CC BY 4.0){{ ($geo['db_date'] ?? '') !== '' ? ' · Stand '.\Illuminate\Support\Carbon::parse($geo['db_date'])->format('d.m.Y') : '' }}
        </p>
    @endif
</div>
