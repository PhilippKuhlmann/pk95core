@php
    use App\Modules\Dns\Fmt;
    use App\Modules\Dns\Overview;
    $card = 'font-DINPro-bold text-gray-900 dark:text-gray-100';
    $tile = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
    $muted = 'text-xs text-gray-500 dark:text-gray-400';
    $log = fn (array $p) => $logBase.'?'.http_build_query($p);
    $prot = $status ? Overview::protectionState($status['protection'] ?? []) : null;
    $failing = collect($status['upstreams'] ?? [])->reject(fn ($u) => ! empty($u['ok']) || (($u['queries'] ?? 0) === 0 && ($u['last_error'] ?? '') === ''))->values();
@endphp

<div wire:poll.10s>
    <x-page-header title="Übersicht">
        @if ($status)
            <x-slot:sub>PK95 DNS {{ $status['version'] }} · DNS auf {{ $status['dns']['listen'] ?? '–' }}</x-slot:sub>
        @endif
        <div class="flex rounded-lg border border-gray-200 bg-white p-0.5 dark:border-gray-700 dark:bg-gray-800" role="group" aria-label="Zeitraum">
            @foreach (Overview::RANGES as $h => $label)
                <button type="button" wire:click="setRange({{ $h }})" aria-pressed="{{ $hours === $h ? 'true' : 'false' }}"
                    @class(['rounded-md px-3 py-1 text-sm', 'bg-cerulean-600 text-white' => $hours === $h, 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $hours !== $h])>{{ $label }}</button>
            @endforeach
        </div>
    </x-page-header>

    @if ($loadError)
        <x-callout title="PK95 DNS antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    @if ($status)
        @if (($status['dns']['error'] ?? '') !== '')
            <x-callout title="Der DNS-Server läuft nicht" ton="gelb" class="m-3">
                {{ $status['dns']['error'] }}
                <span class="block">Port 53 ist im LXC wahrscheinlich schon belegt, etwa durch systemd-resolved. PK95 DNS versucht es alle 30 Sekunden erneut.</span>
            </x-callout>
        @endif
        @if ($failing->isNotEmpty())
            <x-callout :title="$failing->count() === count($status['upstreams']) ? 'Kein Upstream erreichbar' : 'Upstream nicht erreichbar'" ton="gelb" class="m-3">
                @foreach ($failing as $u)
                    <span class="block"><span class="font-mono">{{ $u['address'] }}</span>{{ ($u['last_error'] ?? '') !== '' ? ': '.$u['last_error'] : '' }}</span>
                @endforeach
                <x-slot:aktionen>
                    <x-input.linkbutton :href="route('apps.page', [$installedApp, 'settings'])" color="gray" size="sm" wire:navigate>Upstreams prüfen</x-input.linkbutton>
                </x-slot:aktionen>
            </x-callout>
        @endif

        {{-- protection --}}
        <x-panel class="m-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <span @class(['flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                        'bg-green-100 text-green-700 dark:bg-green-900/60 dark:text-green-200' => $prot['state'] === 'on',
                        'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-100' => $prot['state'] === 'paused',
                        'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $prot['state'] === 'off'])>
                        <x-icon name="shield" class="h-6 w-6" />
                    </span>
                    <div>
                        <div class="{{ $card }}">
                            @switch($prot['state'])
                                @case('on') Schutz aktiv @break
                                @case('paused') Schutz pausiert bis {{ Fmt::when($prot['until']) }} @break
                                @default Schutz ausgeschaltet
                            @endswitch
                        </div>
                        <div class="text-sm text-gray-600 dark:text-gray-300">
                            @if ($prot['state'] === 'on')
                                {{ Fmt::num($status['rules']['list_rules'] + $status['rules']['custom_rules']) }} Regeln aus {{ Fmt::plural($status['rules']['lists'], 'Liste', 'Listen') }} und eigenen Regeln
                            @else
                                Nichts wird blockiert. Eigene DNS-Einträge gelten weiter.
                            @endif
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($prot['state'] === 'on')
                        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                            <x-input.button type="button" color="gray" x-on:click="open = ! open" ::aria-expanded="open.toString()" aria-haspopup="true">
                                <x-icon name="clock" class="h-4 w-4" />Pausieren<x-icon name="chevron-down" class="h-4 w-4" />
                            </x-input.button>
                            <div x-show="open" x-cloak x-transition.opacity class="absolute right-0 z-20 mt-1 w-52 rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800" role="menu">
                                @foreach (Overview::PAUSES as $min => $label)
                                    <button type="button" role="menuitem" wire:click="pause({{ $min }})" x-on:click="open = false"
                                        @class(['block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700', 'border-t border-gray-100 dark:border-gray-700' => $min === 0])>{{ $label }}</button>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <x-input.button type="button" wire:click="enable"><x-icon name="power" class="h-4 w-4" />{{ $prot['state'] === 'paused' ? 'Jetzt einschalten' : 'Einschalten' }}</x-input.button>
                    @endif
                </div>
            </div>
        </x-panel>
    @endif

    @if ($stats)
        @php $t = $stats['totals']; @endphp
        <div class="m-3 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            <a href="{{ $log([]) }}" wire:navigate class="{{ $tile }} hover:border-cerulean-400 dark:hover:border-cerulean-500">
                <div class="{{ $muted }}">Anfragen</div><div class="mt-1 text-2xl font-DINPro-bold text-gray-900 dark:text-gray-100">{{ Fmt::compact($t['queries']) }}</div>
                <div class="{{ $muted }}">{{ Overview::RANGES[$hours] }}</div>
            </a>
            <a href="{{ $log(['status' => 'blocked']) }}" wire:navigate class="{{ $tile }} hover:border-cerulean-400 dark:hover:border-cerulean-500">
                <div class="{{ $muted }}">Blockiert</div><div class="mt-1 text-2xl font-DINPro-bold text-rose-700 dark:text-rose-300">{{ Fmt::compact($t['blocked']) }}</div>
                <div class="{{ $muted }}">{{ Fmt::pct((float) $t['blocked_pct']) }} aller Anfragen</div>
            </a>
            <div class="{{ $tile }}">
                <div class="{{ $muted }}">Aus dem Cache</div><div class="mt-1 text-2xl font-DINPro-bold text-gray-900 dark:text-gray-100">{{ Fmt::share($t['cached'], $t['queries']) }}</div>
                <div class="{{ $muted }}">{{ Fmt::num($t['cached']) }} Antworten</div>
            </div>
            <div class="{{ $tile }}">
                <div class="{{ $muted }}">Antwortzeit</div><div class="mt-1 text-2xl font-DINPro-bold text-gray-900 dark:text-gray-100">{{ Fmt::ms($t['avg_ms']) }}</div>
                <div class="{{ $muted }}">im Schnitt</div>
            </div>
            <div class="{{ $tile }}">
                <div class="{{ $muted }}">Umgeschrieben</div><div class="mt-1 text-2xl font-DINPro-bold text-gray-900 dark:text-gray-100">{{ Fmt::compact($t['safesearch']) }}</div>
                <div class="{{ $muted }}">SafeSearch · {{ Fmt::num($t['services']) }} × Dienste blockiert</div>
            </div>
        </div>

        @php $c = Overview::chart($stats['series'], ($stats['granularity'] ?? 'hour') === 'day'); @endphp
        <x-panel class="m-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="{{ $card }}">Anfragen im Zeitverlauf</div>
                <div class="flex gap-3 {{ $muted }}">
                    {{-- "cached" only when PK95 DNS reports it; "Beantwortet" then splits into upstream and cache --}}
                    <span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-sm bg-cerulean-500"></i>{{ $c['cached'] ? 'Vom Upstream' : 'Beantwortet' }}</span>
                    @if ($c['cached'])
                        <span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-sm bg-emerald-400"></i>Aus dem Cache</span>
                    @endif
                    <span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-sm bg-rose-500"></i>Blockiert</span>
                </div>
            </div>
            @if (! $stats['series'] || $t['queries'] === 0)
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">In diesem Zeitraum gab es keine Anfragen. Trage die Adresse dieses LXC als DNS-Server im Router oder auf den Geräten ein.</p>
            @else
                <svg viewBox="0 0 {{ $c['width'] }} {{ $c['height'] }}" class="mt-3 w-full text-gray-500 dark:text-gray-400" role="img" aria-label="{{ $c['cached'] ? 'Anfragen im Zeitverlauf: vom Upstream, aus dem Cache und blockiert' : 'Anfragen im Zeitverlauf, beantwortet und blockiert' }}">
                    @foreach ($c['grid'] as $g)
                        <line x1="{{ $c['m']['left'] }}" x2="{{ $c['width'] - $c['m']['right'] }}" y1="{{ $g['y'] }}" y2="{{ $g['y'] }}" @class(['stroke-gray-300 dark:stroke-gray-600' => $g['base'], 'stroke-gray-100 dark:stroke-gray-700' => ! $g['base']]) />
                        <text x="{{ $c['m']['left'] - 8 }}" y="{{ $g['y'] + 4 }}" text-anchor="end" class="fill-current text-[11px]">{{ $g['label'] }}</text>
                    @endforeach
                    @foreach ($c['labels'] as $l)
                        <text x="{{ $l['x'] }}" y="{{ $c['height'] - 6 }}" text-anchor="middle" class="fill-current text-[11px]">{{ $l['text'] }}</text>
                    @endforeach
                    @foreach ($c['bars'] as $bar)
                        <g class="group">
                            <rect x="{{ $bar['x'] }}" y="{{ $c['m']['top'] }}" width="{{ $bar['w'] }}" height="{{ $c['plotH'] + 1 }}" class="fill-transparent group-hover:fill-gray-100 dark:group-hover:fill-gray-700/50"><title>{{ $bar['title'] }}</title></rect>
                            @foreach ($bar['segs'] as $s)
                                <rect x="{{ $s['x'] }}" y="{{ $s['y'] }}" width="{{ $bar['bw'] }}" height="{{ $s['h'] }}" class="{{ $s['class'] }}" pointer-events="none" />
                            @endforeach
                        </g>
                    @endforeach
                </svg>
            @endif
        </x-panel>

        <div class="grid grid-cols-1 md:grid-cols-2">
            @include('modules.dns.partials.top-card', ['title' => 'Häufigste Domains', 'rows' => array_map(fn ($r) => [
                'label' => $r['name'], 'count' => $r['count'], 'href' => $log(['search' => $r['name']])], $stats['top_domains'])])
            @include('modules.dns.partials.top-card', ['title' => 'Am häufigsten blockiert', 'barClass' => 'bg-rose-500', 'rows' => array_map(fn ($r) => [
                'label' => $r['name'], 'count' => $r['count'], 'href' => $log(['search' => $r['name'], 'status' => 'blocked'])], $stats['top_blocked'])])
            @include('modules.dns.partials.top-card', ['title' => 'Clients', 'rows' => array_map(fn ($r) => [
                'label' => $r['name'] !== '' ? $r['name'] : $r['ip'], 'sub' => $r['name'] !== '' ? $r['ip'] : null, 'count' => $r['count'],
                'note' => Fmt::num($r['blocked']).' blockiert', 'href' => $log(['client' => $r['ip']])], $stats['top_clients'])])
            @include('modules.dns.partials.top-card', ['title' => 'Upstreams', 'rows' => array_map(fn ($r) => [
                'label' => $r['address'], 'count' => $r['count'], 'note' => Fmt::ms($r['avg_ms'])], $stats['top_upstreams'])])
        </div>
    @endif
</div>
