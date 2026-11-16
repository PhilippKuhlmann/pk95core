{{--
    Who visited $visitHost on $visitDate (Proxy → Aufrufe, a click on a number): one entry per
    IP with name (PK95 DNS), country, clients, page views, requests and time span; the paths
    open per visitor. Hints are observations, no verdicts.
--}}
@php
    use App\Http\Support\Format;
    use App\Modules\Proxy\Daily;
    use App\Modules\Proxy\Fmt;
    use App\Modules\Proxy\Visitors;
    $clock = fn (int $ms) => $ms > 0 ? Format::fromMs($ms)->format('H:i:s') : '–';
    $logUrl = route('apps.page', [$installedApp, 'log']);
    $badge = 'inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-medium';
    $people = $visit['visitors'];
    $hiddenCount = count($visit['hidden']);
    $switch = fn (bool $on) => $on ? 'bg-cerulean-600 text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700';
@endphp

<x-modal wire:key="visitors-dialog" submit="" close="closeVisitors" size="2xl">
    <x-slot:title>{{ $visitHost }} · {{ Daily::weekday($visitDate) }} {{ Daily::label($visitDate) }}</x-slot:title>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex rounded-lg border border-gray-200 bg-white p-0.5 dark:border-gray-700 dark:bg-gray-800" role="group" aria-label="Was gezählt wird">
            <button type="button" wire:click="$set('visitAll', false)" aria-pressed="{{ $visitAll ? 'false' : 'true' }}"
                @class(['whitespace-nowrap rounded-md px-3 py-1 text-sm max-sm:py-2', $switch(! $visitAll)])>Nur Seitenaufrufe</button>
            <button type="button" wire:click="$set('visitAll', true)" aria-pressed="{{ $visitAll ? 'true' : 'false' }}"
                @class(['whitespace-nowrap rounded-md px-3 py-1 text-sm max-sm:py-2', $switch($visitAll)])>Alle Zugriffe</button>
        </div>
        @if (! $visit['missing'] && $visit['error'] === null)
            <p class="text-sm text-gray-600 dark:text-gray-300" data-visitors-summary>
                {{ Fmt::plural(count($people), 'Besucher', 'Besucher') }} · {{ Fmt::num(array_sum(array_column($people, 'pages'))) }} Seitenaufrufe · {{ Fmt::num(array_sum(array_column($people, 'requests'))) }} Zugriffe
            </p>
        @endif
    </div>
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
        {{ $hiddenCount ? 'Ohne Bots und ohne '.($hiddenCount === 1 ? 'den ausgeblendeten Client ' : $hiddenCount.' ausgeblendete Clients: ').implode(', ', $visit['hidden']).'.' : 'Ohne Bots.' }}
        Die Hinweise an einem Besucher sind Beobachtungen, keine Bewertung.
    </p>
    @if ($visit['estimated'])
        <x-callout title="Seitenaufrufe an diesem Tag geschätzt" class="mt-3" data-visitors-estimated>
            Die Seitenzählung lief an diesem Tag noch nicht vollständig. Seitenaufrufe sind geschätzt, Zugriffe und Pfade sind genau.
        </x-callout>
    @endif

    @if ($visit['missing'])
        <x-callout title="Braucht PK95 Proxy {{ Visitors::MIN_VERSION }}" class="mt-3" data-visitors-missing>
            Wer an einem Tag zugegriffen hat, zeigt PK95 Proxy ab Version {{ Visitors::MIN_VERSION }}. Aktualisiere PK95 Proxy unter App-Details.
        </x-callout>
    @elseif ($visit['error'] !== null)
        <x-callout title="Besucher konnten nicht geladen werden" ton="gelb" class="mt-3">{{ $visit['error'] }}</x-callout>
    @elseif (! $people)
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">An diesem Tag gab es keine Besucher{{ $visitAll ? '' : ' mit Seitenaufrufen' }}.</p>
    @else
        <ul class="mt-3 divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-700 dark:border-gray-700" data-visitors>
            @foreach ($people as $i => $v)
                @php
                    $name = $visit['names'][$v['ip']] ?? null;
                    $hints = Visitors::hints($v);
                @endphp
                <li wire:key="visitor-{{ md5($v['ip']) }}" x-data="{ open: false }">
                    <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-controls="visitor-{{ $i }}-paths"
                        class="flex w-full items-start gap-3 px-3 py-3 text-left hover:bg-gray-50 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cerulean-500 dark:hover:bg-gray-700/40">
                        <x-icon name="chevron-down" class="mt-1 h-4 w-4 shrink-0 text-gray-400 transition-transform" ::class="open && 'rotate-180'" sw="2" />
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="break-all font-mono text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $v['ip'] }}</span>
                                @if ($name)
                                    <span class="text-sm text-gray-700 dark:text-gray-200" data-ip-name>· {{ $name }}</span>
                                @endif
                                @if (Visitors::isLan($v['ip']))
                                    <span class="{{ $badge }} bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200">Lokales Netz</span>
                                @elseif ($v['country'] !== '')
                                    <span class="text-xs text-gray-600 dark:text-gray-300"><span aria-hidden="true">{{ Fmt::flag($v['country']) }}</span> {{ Fmt::country($v['country']) }}</span>
                                @endif
                                @if ($visit['own'] === $v['ip'])
                                    <span class="{{ $badge }} bg-cerulean-100 text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-100">eigene öffentliche IP</span>
                                @endif
                                @foreach ($hints as $hint)
                                    <span class="{{ $badge }} bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-900/30 dark:text-amber-200 dark:ring-amber-800" data-visitor-hint>{{ $hint }}</span>
                                @endforeach
                            </span>
                            <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">
                                {{ $v['clients'] ? implode(', ', $v['clients']) : 'Client unbekannt' }} · {{ $clock($v['first']) }}–{{ $clock($v['last']) }} Uhr
                            </span>
                        </span>
                        <span class="shrink-0 text-right text-xs tabular-nums text-gray-500 dark:text-gray-400">
                            <span class="block"><span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ Fmt::num($v['pages']) }}</span> Seiten</span>
                            <span class="block">{{ Fmt::num($v['requests']) }} Zugriffe</span>
                        </span>
                    </button>
                    <div id="visitor-{{ $i }}-paths" x-show="open" x-cloak class="border-t border-gray-100 bg-gray-50/70 px-3 py-3 dark:border-gray-700 dark:bg-gray-900/30">
                        @if ($v['paths'])
                            <ol class="space-y-1 text-xs" aria-label="Aufrufe von {{ $v['ip'] }}">
                                @foreach ($v['paths'] as $p)
                                    <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                        <span class="font-mono tabular-nums text-gray-500 dark:text-gray-400">{{ $clock($p['ts']) }}</span>
                                        <span class="w-11 font-mono text-gray-600 dark:text-gray-300">{{ $p['method'] }}</span>
                                        <span class="w-8 font-mono font-semibold {{ Fmt::statusTone($p['status']) }}">{{ $p['status'] ?: '–' }}</span>
                                        <span class="min-w-0 flex-1 break-all font-mono text-gray-900 dark:text-gray-100">{{ $p['path'] }}</span>
                                        @if ($p['client'] !== '')
                                            <span class="text-gray-500 dark:text-gray-400">{{ $p['client'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        @else
                            <p class="text-xs text-gray-500 dark:text-gray-400">Keine Pfade übermittelt.</p>
                        @endif
                        @if ($v['paths_truncated'])
                            <p class="mt-2 text-xs text-amber-700 dark:text-amber-300" data-paths-truncated>Gekürzt: Nicht alle Aufrufe dieses Besuchers sind aufgeführt. Alle zeigt „In Zugriffe öffnen“.</p>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-input.linkbutton :href="$logUrl.'?'.http_build_query(['host' => $visitHost, 'ip' => $v['ip'], 'day' => $visitDate])" wire:navigate color="gray" size="sm" class="max-sm:min-h-11">In Zugriffe öffnen</x-input.linkbutton>
                            @foreach ($v['clients'] as $c)
                                <x-input.button type="button" size="sm" color="gray" wire:click="hideClient({{ Js::from($c) }})" class="max-sm:min-h-11">Client „{{ $c }}“ ausblenden</x-input.button>
                            @endforeach
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-modal>
