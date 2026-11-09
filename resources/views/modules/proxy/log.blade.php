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
    </div>

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
                            <td class="hidden max-w-56 truncate px-3 py-1.5 lg:table-cell" title="{{ $r['client'] }}">{{ $r['client'] }}
                                @if ($r['is_bot'])<span class="ml-1 rounded bg-gray-100 px-1 py-0.5 text-[10px] text-gray-600 dark:bg-gray-700 dark:text-gray-300">Bot</span>@endif</td>
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
                                        <dt class="text-gray-500">User-Agent</dt><dd class="break-all">{{ $r['ua'] ?: '–' }}</dd>
                                        <dt class="text-gray-500">Verweis</dt><dd class="break-all">{{ $r['referer'] ?: '–' }}</dd>
                                    </dl>
                                    <div class="mt-2 flex gap-2">
                                        <x-input.button type="button" size="sm" color="gray" wire:click.stop="filterBy('ip', {{ Js::from($r['ip']) }})">Nur diese IP-Adresse</x-input.button>
                                        @if ($r['host'])
                                            <x-input.button type="button" size="sm" color="gray" wire:click.stop="filterBy('host', {{ Js::from($r['host']) }})">Nur dieser Host</x-input.button>
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
