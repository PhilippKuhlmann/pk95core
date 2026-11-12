@php
    use App\Modules\Proxy\Fmt;
    $input = 'rounded-lg border-gray-300 bg-white py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300';
@endphp

<div @if ($live) wire:poll.2s @endif>
    <x-page-header title="Zugriffe">
        <button type="button" wire:click="toggleLive" aria-pressed="{{ $live ? 'true' : 'false' }}"
            class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
            <span @class(['h-2 w-2 rounded-full', Fmt::dot($live ? 'good' : 'none'), 'animate-pulse' => $live])></span>{{ $live ? 'Live' : 'Angehalten' }}
        </button>
    </x-page-header>

    <div class="mx-3 mt-2 flex flex-wrap items-center gap-2">
        @if ($country)
            <button type="button" wire:click="clearCountry" title="Filter entfernen" class="rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs text-gray-700 hover:border-rose-300 hover:bg-rose-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:border-rose-800 dark:hover:bg-rose-950">
                {{ Fmt::flag($country) }} Land: {{ Fmt::country($country) }} ×
            </button>
        @endif
        <select wire:model.live="host" aria-label="Host" class="{{ $input }} w-52">
            <option value="">Alle Hosts</option>
            @foreach ($domains as $dm)<option value="{{ $dm }}">{{ $dm }}</option>@endforeach
        </select>
        <select wire:model.live="status" aria-label="Status" class="{{ $input }}">
            @foreach ($statuses as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
        </select>
        <input type="search" wire:model.live.debounce.300ms="ip" placeholder="IP-Adresse" aria-label="IP-Adresse" class="{{ $input }} w-40">
        <input type="search" wire:model.live.debounce.300ms="q" placeholder="Pfad enthält …" aria-label="Pfad" class="{{ $input }} w-48">
        <x-input.checkbox label="Bots ausblenden" wire:model.live="hideBots" />
        @if ($clientFilter)
            {{-- picking a name hides it at once; the select goes back to its prompt --}}
            <select aria-label="Client ausblenden" class="{{ $input }} w-48" data-client-hide
                x-on:change="if ($event.target.value !== '') { $wire.hideClient($event.target.value); $event.target.value = '' }">
                <option value="">Client ausblenden …</option>
                @foreach ($clientNames as $name)
                    <option value="{{ $name }}">{{ $name.(isset($clientCounts[$name]) ? ' ('.Fmt::num($clientCounts[$name]).')' : '') }}</option>
                @endforeach
            </select>
            <select wire:model.live="client" aria-label="Nur Client" class="{{ $input }} w-44">
                <option value="">Alle Clients</option>
                @if ($client !== '' && ! in_array($client, $clientNames, true))<option value="{{ $client }}">{{ $client }}</option>@endif
                @foreach ($clientNames as $name)<option value="{{ $name }}">Nur {{ $name }}</option>@endforeach
            </select>
        @else
            <span class="text-xs text-gray-500 dark:text-gray-400" title="Die installierte Version von PK95 Proxy kann noch nicht nach Client filtern." data-client-filter-missing>
                Clients ausblenden: braucht PK95 Proxy 1.3.0
            </span>
        @endif
    </div>

    @if ($clientFilter && ($hidden || $client !== ''))
        <div class="mx-3 mt-2 flex flex-wrap items-center gap-2 text-xs" data-client-chips>
            @if ($client !== '')
                <button type="button" wire:click="clearClient" title="Filter entfernen"
                    class="rounded-full border border-cerulean-200 bg-cerulean-50 px-2.5 py-1 text-cerulean-800 hover:border-rose-300 hover:bg-rose-50 dark:border-cerulean-800 dark:bg-cerulean-950 dark:text-cerulean-200 dark:hover:border-rose-800 dark:hover:bg-rose-950">
                    Nur Client: {{ $client }} ×
                </button>
            @endif
            @if ($hidden)
                <span class="text-gray-600 dark:text-gray-300">{{ count($hidden) === 1 ? '1 Client ausgeblendet' : count($hidden).' Clients ausgeblendet' }}:</span>
                @foreach ($hidden as $name)
                    <button type="button" wire:click="showClient({{ Js::from($name) }})" title="Wieder zeigen" wire:key="hidden-{{ md5($name) }}"
                        class="max-w-56 truncate rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-gray-700 hover:border-rose-300 hover:bg-rose-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:border-rose-800 dark:hover:bg-rose-950">
                        {{ $name }} ×
                    </button>
                @endforeach
                <button type="button" wire:click="showAllClients" class="text-cerulean-700 underline hover:no-underline dark:text-cerulean-300">Alle zeigen</button>
            @endif
        </div>
    @elseif (! $clientFilter && $hidden)
        <p class="mx-3 mt-2 text-xs text-amber-800 dark:text-amber-200" data-client-filter-inactive>
            {{ count($hidden) === 1 ? '1 ausgeblendeter Client wirkt' : count($hidden).' ausgeblendete Clients wirken' }} erst mit PK95 Proxy 1.3.0, bis dahin sind alle Zugriffe zu sehen.
        </p>
    @endif

    @if ($loadError)
        <x-callout title="Zugriffe konnten nicht geladen werden" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    @if (! $rows && ! $loadError)
        <x-panel class="m-3" polster="weit">
            <div class="text-center">
                <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Keine passenden Zugriffe</div>
                <p class="mt-2 text-sm text-gray-500">Hier erscheinen Anfragen, sobald sie über den Proxy laufen.</p>
            </div>
        </x-panel>
    @elseif ($rows)
        <x-panel class="m-3 overflow-x-auto" polster="keins">
            <table class="w-full text-left text-xs">
                <thead class="border-b border-gray-200 uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr><th class="px-3 py-2">Zeit</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Methode</th><th class="px-3 py-2">Host</th><th class="px-3 py-2">Pfad</th>
                        <th class="px-3 py-2">IP-Adresse</th><th class="hidden px-3 py-2 lg:table-cell">Client</th><th class="px-3 py-2 text-right">Größe</th><th class="px-3 py-2 text-right">Dauer</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($rows as $r)
                        @php $isOpen = in_array($r['id'], $open, true); @endphp
                        <tr wire:key="req-{{ $r['id'] }}" wire:click="toggle({{ $r['id'] }})" tabindex="0" aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                            x-on:keydown.enter.prevent="$wire.toggle({{ $r['id'] }})" x-on:keydown.space.prevent="$wire.toggle({{ $r['id'] }})"
                            @class(['cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50', 'bg-cerulean-50/60 dark:bg-cerulean-900/20' => $fresh && $r['id'] > $fresh])>
                            <td class="whitespace-nowrap px-3 py-1.5 tabular-nums">{{ Fmt::stamp($r['ts']) }}</td>
                            <td class="whitespace-nowrap px-3 py-1.5 font-medium {{ Fmt::statusTone($r['status']) }}">{{ $r['status'] ?: '–' }}</td>
                            <td class="px-3 py-1.5">{{ $r['method'] }}</td>
                            <td class="max-w-48 truncate px-3 py-1.5" title="{{ $r['host'] }}">{{ $r['host'] ?: '–' }}</td>
                            <td class="max-w-md truncate px-3 py-1.5 font-mono" title="{{ $r['path'] }}">{{ $r['path'] }}</td>
                            <td class="whitespace-nowrap px-3 py-1.5 font-mono"><span title="{{ $r['country'] ? Fmt::country($r['country']) : '' }}">{{ Fmt::flag($r['country']) }} {{ $r['ip'] }}
                                @if ($r['country'] && $r['country'] !== 'LAN')<span class="text-gray-500 dark:text-gray-400">{{ $r['country'] }}</span>@endif</span></td>
                            <td class="group/client hidden max-w-56 px-3 py-1.5 lg:table-cell" title="{{ $r['client'] }}">
                                <span class="flex items-center gap-1">
                                    <span class="truncate">{{ $r['client'] }}</span>
                                    @if ($r['is_bot'])<span class="shrink-0 rounded bg-gray-100 px-1 py-0.5 text-[10px] text-gray-600 dark:bg-gray-700 dark:text-gray-300">Bot</span>@endif
                                    @if ($clientFilter && $r['client'] !== '')
                                        {{-- quick action: hide this client (visible on hover/focus) --}}
                                        <button type="button" wire:click.stop="hideClient({{ Js::from($r['client']) }})" title="Client „{{ $r['client'] }}“ ausblenden" aria-label="Client {{ $r['client'] }} ausblenden"
                                            class="shrink-0 rounded p-0.5 text-gray-400 opacity-0 hover:bg-gray-100 hover:text-gray-700 focus:opacity-100 group-hover/client:opacity-100 dark:hover:bg-gray-700 dark:hover:text-gray-200">
                                            <x-icon name="eye-off" class="h-3.5 w-3.5" />
                                        </button>
                                    @endif
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{{ Fmt::bytes($r['bytes']) }}</td>
                            <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{{ Fmt::ms($r['duration_ms']) }}</td>
                        </tr>
                        @if ($isOpen)
                            <tr wire:key="req-d-{{ $r['id'] }}" class="bg-gray-50 dark:bg-gray-900/40">
                                <td colspan="9" class="px-3 py-3">
                                    <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1">
                                        <dt class="text-gray-500">Pfad</dt><dd class="break-all font-mono">{{ $r['path'] }}</dd>
                                        <dt class="text-gray-500">Protokoll</dt><dd>{{ $r['proto'] }}</dd>
                                        <dt class="text-gray-500">Download</dt><dd>{{ Fmt::bytes($r['bytes']) }}</dd>
                                        <dt class="text-gray-500">Upload</dt><dd>{{ Fmt::bytes($r['bytes_in']) }}</dd>
                                        <dt class="text-gray-500">Land</dt><dd>{{ Fmt::flag($r['country']) }} {{ Fmt::country($r['country']) }}</dd>
                                        <dt class="text-gray-500">Client</dt><dd>{{ $r['client'] ?: '–' }}{{ $r['is_bot'] ? ' (Bot)' : '' }}</dd>
                                        <dt class="text-gray-500">User-Agent</dt><dd class="break-all">{{ $r['ua'] ?: '–' }}</dd>
                                        <dt class="text-gray-500">Verweis</dt><dd class="break-all">{{ $r['referer'] ?: '–' }}</dd>
                                    </dl>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <x-input.button type="button" size="sm" color="gray" wire:click.stop="filterBy('ip', {{ Js::from($r['ip']) }})">Nur diese IP-Adresse</x-input.button>
                                        @if ($r['host'])
                                            <x-input.button type="button" size="sm" color="gray" wire:click.stop="filterBy('host', {{ Js::from($r['host']) }})">Nur dieser Host</x-input.button>
                                        @endif
                                        @if ($clientFilter && $r['client'] !== '')
                                            <x-input.button type="button" size="sm" color="gray" wire:click.stop="onlyClient({{ Js::from($r['client']) }})">Nur dieser Client</x-input.button>
                                            <x-input.button type="button" size="sm" color="gray" wire:click.stop="hideClient({{ Js::from($r['client']) }})">
                                                <x-icon name="eye-off" class="h-3.5 w-3.5" />Client „{{ $r['client'] }}“ ausblenden
                                            </x-input.button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
            @if ($more)
                <div class="border-t border-gray-100 p-3 text-center dark:border-gray-700">
                    <x-input.button type="button" size="sm" color="gray" wire:click="older">Ältere laden</x-input.button>
                </div>
            @endif
        </x-panel>
    @endif
</div>
