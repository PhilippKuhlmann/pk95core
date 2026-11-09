@php
    use App\Modules\Dns\Fmt;
    use App\Modules\Dns\QueryLog;
    $input = 'rounded-lg border-gray-300 bg-white py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100';
    $filtered = $search !== '' || $client !== '' || $status !== 'all';
@endphp

<div @if ($auto) wire:poll.5s="reload" @endif>
    <x-page-header title="Abfragen">
        <button type="button" wire:click="toggleAuto" aria-pressed="{{ $auto ? 'true' : 'false' }}"
            class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
            <span @class(['h-2 w-2 rounded-full', Fmt::dot($auto ? 'good' : 'none'), 'animate-pulse' => $auto])></span>{{ $auto ? 'Automatisch aktualisieren: an' : 'Automatisch aktualisieren: aus' }}
        </button>
        <x-input.button type="button" color="gray" wire:click="reload"><x-icon name="refresh" class="h-4 w-4" />Aktualisieren</x-input.button>
        <x-input.button type="button" color="gray"
            x-on:click="$store.confirm.ask({ title: 'Abfragelog leeren?', text: 'Alle gespeicherten Abfragen werden gelöscht. Die Statistik bleibt erhalten.', button: 'Leeren', ok: () => $wire.clear() })">
            <x-icon name="trash" class="h-4 w-4" />Log leeren
        </x-input.button>
    </x-page-header>

    <div class="mx-3 mt-2 flex flex-wrap items-center gap-2">
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Domain enthält …" aria-label="Domain" class="{{ $input }} w-56">
        <input type="search" wire:model.live.debounce.400ms="client" placeholder="Client (IP oder Name)" aria-label="Client" class="{{ $input }} w-52">
        <select wire:model.live="status" aria-label="Status" class="{{ $input }}">
            @foreach (QueryLog::FILTERS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
        </select>
        @if ($filtered)
            <button type="button" wire:click="resetFilters" class="text-sm text-cerulean-700 hover:underline dark:text-cerulean-300">Filter zurücksetzen</button>
        @endif
    </div>

    @if ($loadError)
        <x-callout title="Abfragen konnten nicht geladen werden" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    @if (! $rows && ! $loadError)
        <x-panel class="m-3" polster="weit">
            <div class="text-center">
                <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $filtered ? 'Keine passenden Abfragen' : 'Noch keine Abfragen' }}</div>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    {{ $filtered ? 'Ändere die Filter oder setze sie zurück.' : 'Sobald Geräte diesen DNS-Server nutzen, erscheinen ihre Anfragen hier. Ist das Abfragelog in den Einstellungen ausgeschaltet, bleibt die Liste leer.' }}
                </p>
            </div>
        </x-panel>
    @elseif ($rows)
        <x-panel class="m-3 overflow-x-auto" polster="keins">
            <table class="w-full text-left text-xs text-gray-900 dark:text-gray-100">
                <thead class="border-b border-gray-200 uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr><th class="px-3 py-2">Zeit</th><th class="px-3 py-2">Client</th><th class="px-3 py-2">Domain</th><th class="px-3 py-2">Status</th>
                        <th class="hidden px-3 py-2 lg:table-cell">Antwort</th><th class="px-3 py-2 text-right">Dauer</th><th class="px-3 py-2"><span class="sr-only">Aktionen</span></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($rows as $r)
                        @php
                            $own = $quickRules[$r['name']] ?? null;
                            $detail = array_filter([
                                $r['reason'] !== '' ? (Fmt::REASONS[$r['reason']] ?? $r['reason']) : null,
                                $r['list_name'] !== '' ? $r['list_name'] : null,
                                $r['service'] !== '' ? $r['service'] : null,
                            ]);
                        @endphp
                        <tr wire:key="q-{{ $r['id'] }}" class="align-top hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ Fmt::stamp($r['ts']) }}</td>
                            <td class="px-3 py-2">
                                <button type="button" wire:click="filterClient({{ Js::from($r['client_ip']) }})" title="Nur diesen Client zeigen" class="text-left hover:underline">
                                    @if ($r['client_name'] !== '')
                                        <span class="block">{{ $r['client_name'] }}</span><span class="block font-mono text-gray-500 dark:text-gray-400">{{ $r['client_ip'] }}</span>
                                    @else
                                        <span class="font-mono">{{ $r['client_ip'] }}</span>
                                    @endif
                                </button>
                            </td>
                            <td class="max-w-xs px-3 py-2">
                                <span class="block break-all font-mono">{{ $r['name'] }}</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ $r['qtype'] }}{{ $r['cached'] ? ' · aus dem Cache' : '' }}</span>
                            </td>
                            <td class="px-3 py-2">
                                <span class="inline-block whitespace-nowrap rounded px-1.5 py-0.5 text-[11px] font-semibold {{ Fmt::badge($r['status']) }}">{{ Fmt::status($r['status']) }}</span>
                                @if ($detail)<span class="mt-0.5 block text-gray-500 dark:text-gray-400">{{ implode(' · ', $detail) }}</span>@endif
                                @if ($r['rule'] !== '')<span class="block break-all font-mono text-gray-500 dark:text-gray-400" title="Regel">{{ $r['rule'] }}</span>@endif
                            </td>
                            <td class="hidden max-w-xs px-3 py-2 lg:table-cell">
                                <span class="block break-all font-mono">{{ $r['answer'] !== '' ? $r['answer'] : '–' }}</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ $r['rcode'] }}{{ $r['upstream'] !== '' ? ' · '.$r['upstream'] : '' }}</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ Fmt::ms($r['elapsed_ms']) }}</td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-1">
                                    @if ($own)
                                        <x-input.button type="button" size="sm" color="gray" wire:click="quick({{ Js::from($r['name']) }}, 'remove')" aria-label="Eigene Regel für {{ $r['name'] }} entfernen"
                                            title="Eigene Regel ({{ $own === 'block' ? 'blockiert' : 'erlaubt' }}) entfernen">Regel entfernen</x-input.button>
                                    @endif
                                    @if (! QueryLog::quickable($r['name']))
                                        {{-- e.g. root queries: the app accepts no rule for this name --}}
                                    @elseif (Fmt::blocked($r['status']) || $own === 'block')
                                        @if ($own !== 'allow')
                                            <x-input.button type="button" size="sm" color="gray" wire:click="quick({{ Js::from($r['name']) }}, 'allow')" aria-label="{{ $r['name'] }} erlauben">Erlauben</x-input.button>
                                        @endif
                                    @elseif ($own !== 'block')
                                        <x-input.button type="button" size="sm" color="gray" wire:click="quick({{ Js::from($r['name']) }}, 'block')" aria-label="{{ $r['name'] }} blockieren">Blockieren</x-input.button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 p-3 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                <span>{{ Fmt::plural(count($rows), 'Abfrage', 'Abfragen') }} geladen</span>
                @if ($nextBefore > 0)
                    <x-input.button type="button" size="sm" color="gray" wire:click="more">Mehr laden</x-input.button>
                @endif
            </div>
        </x-panel>
    @endif
</div>
