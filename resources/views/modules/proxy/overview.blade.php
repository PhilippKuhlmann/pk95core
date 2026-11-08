@php
    use App\Modules\Proxy\Fmt;
    use App\Modules\Proxy\Overview;
    $card = 'font-DINPro-bold text-gray-900 dark:text-gray-100';
    $tile = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
    $link = fn (array $p) => $this->logLink($logBase, $p);
@endphp

<div wire:poll.{{ $range === '1h' ? '10s' : '30s' }}>
    <style>
        .proxy-map { --proxy-map-line: #f9fafb; --proxy-map-0: #e5e7eb; --proxy-map-1: #cfe5fb; --proxy-map-2: #9ccbf5; --proxy-map-3: #5fa9ec; --proxy-map-4: #2283d9; --proxy-map-5: #0b5cab; }
        .dark .proxy-map { --proxy-map-line: #1f2937; --proxy-map-0: #374151; --proxy-map-1: #1e3a5f; --proxy-map-2: #1f5a92; --proxy-map-3: #2b7bc4; --proxy-map-4: #4f9de6; --proxy-map-5: #8cc4f7; }
    </style>
    <x-page-header title="Übersicht">
        <x-slot:sub>
            @if ($d) Stand {{ Fmt::time($d['computed_at']) }} <span title="Lange Zeiträume werden seltener neu berechnet, um den Server zu schonen.">ⓘ</span> @endif
        </x-slot:sub>
        <div class="flex rounded-lg border border-gray-200 bg-white p-0.5 dark:border-gray-700 dark:bg-gray-800" role="group" aria-label="Zeitraum">
            @foreach (Overview::RANGES as $id => $label)
                <button type="button" wire:click="setRange('{{ $id }}')" aria-pressed="{{ $range === $id ? 'true' : 'false' }}"
                    @class(['rounded-md px-3 py-1 text-sm', 'bg-cerulean-600 text-white' => $range === $id, 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $range !== $id])>{{ $label }}</button>
            @endforeach
        </div>
        <x-input.select wire:model.live="host" aria-label="Host" class="w-56">
            <option value="">Alle Hosts</option>
            @foreach ($configured as $dm)<option value="{{ $dm }}">{{ $dm }}</option>@endforeach
        </x-input.select>
        <x-input.checkbox label="Bots ausblenden" wire:model.live="hideBots" />
    </x-page-header>

    @if ($loadError)
        <x-callout title="Statistik konnte nicht geladen werden" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    @if ($d && $d['requests'] === 0)
        <x-panel class="m-3" polster="weit">
            <div class="text-center">
                <div class="{{ $card }}">In diesem Zeitraum gab es keine Zugriffe</div>
                @if (! $configured)
                    <p class="mt-2 text-sm text-gray-500">Sobald ein Host eingerichtet ist und Anfragen eingehen, erscheinen sie hier.</p>
                    <a href="{{ route('apps.page', [$installedApp, 'hosts']) }}" wire:navigate class="mt-3 inline-block text-sm text-cerulean-700 hover:underline dark:text-cerulean-300">Host einrichten</a>
                @else
                    <p class="mt-2 text-sm text-gray-500">Wähle einen längeren Zeitraum oder entferne die Filter.</p>
                @endif
            </div>
        </x-panel>
    @elseif ($d)
        @php
            $pctServer = $d['requests'] ? $d['errors_5xx'] / $d['requests'] * 100 : 0;
            $pctBots = $d['requests'] ? $d['bots'] / $d['requests'] * 100 : 0;
            $uploadMissing = $d['upload_since'] > $d['from'];
        @endphp
        {{-- tiles --}}
        <div class="m-3 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <div class="{{ $tile }}"><div class="text-xs text-gray-500">Anfragen</div><div class="mt-1 text-2xl font-DINPro-bold">{{ Fmt::compact($d['requests']) }}</div>
                <div class="text-xs text-gray-500">{{ $hideBots ? 'ohne Bots' : 'davon '.Fmt::pct($pctBots).' Bots' }}</div></div>
            <div class="{{ $tile }}"><div class="text-xs text-gray-500">Besucher</div><div class="mt-1 text-2xl font-DINPro-bold">{{ Fmt::compact($d['visitors']) }}</div>
                <div class="text-xs text-gray-500">verschiedene IP-Adressen</div></div>
            <div class="{{ $tile }}" title="Download: von deinen Diensten zu den Besuchern. Upload: von den Besuchern zu deinen Diensten.">
                <div class="text-xs text-gray-500">Datenmenge</div>
                <div class="mt-1 flex gap-4"><div><div class="text-lg font-DINPro-bold">{{ Fmt::bytes($d['bytes']) }}</div><div class="text-xs text-gray-500">↓ Download</div></div>
                    <div><div class="text-lg font-DINPro-bold">{{ Fmt::bytes($d['bytes_in']) }}</div><div class="text-xs text-gray-500">↑ Upload</div></div></div>
                @if ($uploadMissing)<div class="text-xs text-gray-500">Upload seit {{ Fmt::at($d['upload_since'])->format('d.m., H:i') }}</div>@endif
            </div>
            <div class="{{ $tile }}"><div class="text-xs text-gray-500">Serverfehler</div><div class="mt-1 text-2xl font-DINPro-bold">{{ Fmt::pct($pctServer) }}</div>
                <div class="text-xs text-gray-500">{{ Fmt::num($d['errors_5xx']) }} × 5xx, {{ Fmt::num($d['errors_4xx']) }} × 4xx</div></div>
            <div class="{{ $tile }}"><div class="text-xs text-gray-500">Antwortzeit (P95)</div><div class="mt-1 text-2xl font-DINPro-bold">{{ Fmt::ms($d['p95_ms']) }}</div>
                <div class="text-xs text-gray-500">im Schnitt {{ Fmt::ms($d['avg_ms']) }}</div></div>
            @if ($security)
                @php
                    $sc = $security['mode'] === 'allow' ? $security['allowed'] : $security['blocked'];
                    $parts = [];
                    if ($sc) $parts[] = $security['mode'] === 'allow' ? 'nur '.Fmt::plural(count($sc), 'Land', 'Länder').' frei' : 'dazu '.Fmt::plural(count($sc), 'Land', 'Länder');
                    if ($security['blocklist_networks'] ?? 0) $parts[] = Fmt::num($security['blocklist_networks']).' Netze aus Blocklisten';
                    if (! $security['guard']['enabled']) $parts[] = 'Automatik aus';
                @endphp
                <a href="{{ route('apps.page', [$installedApp, 'security']) }}" wire:navigate class="{{ $tile }} hover:border-cerulean-400">
                    <div class="text-xs text-gray-500">Gesperrte Adressen</div><div class="mt-1 text-2xl font-DINPro-bold">{{ Fmt::num($security['bans_total']) }}</div>
                    <div class="text-xs text-gray-500">{{ $parts ? implode(', ', $parts) : 'aktuell, alle Hosts' }}</div>
                </a>
            @endif
        </div>

        {{-- requests over time --}}
        @php $c = Overview::bars($d['series'], array_keys(Overview::SERIES), $d['bucket_ms'], 1000, 240, 46, 4, fn ($v) => $v >= 1e6 ? Fmt::dec($v / 1e6).' M' : ($v >= 10000 ? Fmt::dec($v / 1e3).' T' : Fmt::num($v))); @endphp
        <x-panel class="m-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="{{ $card }}">Anfragen im Zeitverlauf</div>
                <div class="flex flex-wrap gap-3 text-xs text-gray-500">
                    @foreach (Overview::SERIES as [$label, , $swatch])<span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-sm {{ $swatch }}"></i>{{ $label }}</span>@endforeach
                </div>
            </div>
            <svg viewBox="0 0 {{ $c['width'] }} {{ $c['height'] }}" class="mt-3 w-full text-gray-400" role="img" aria-label="Anfragen im Zeitverlauf nach Statusklasse">
                @foreach ($c['grid'] as $g)
                    <line x1="{{ $c['m']['left'] }}" x2="{{ $c['width'] - $c['m']['right'] }}" y1="{{ $g['y'] }}" y2="{{ $g['y'] }}" @class(['stroke-gray-300 dark:stroke-gray-600' => $g['base'], 'stroke-gray-100 dark:stroke-gray-700' => ! $g['base']]) />
                    <text x="{{ $c['m']['left'] - 8 }}" y="{{ $g['y'] + 4 }}" text-anchor="end" class="fill-current text-[11px]">{{ $g['label'] }}</text>
                @endforeach
                @foreach ($c['labels'] as $l)
                    <text x="{{ $l['x'] }}" y="{{ $c['height'] - 6 }}" text-anchor="middle" class="fill-current text-[11px]">{{ $l['text'] }}</text>
                @endforeach
                @foreach ($c['bars'] as $bar)
                    @php $b = $bar['b']; @endphp
                    <g class="group">
                        <rect x="{{ $bar['band']['x'] }}" y="{{ $c['m']['top'] }}" width="{{ $bar['band']['w'] }}" height="{{ $c['plotH'] + 1 }}" class="fill-transparent group-hover:fill-gray-100 dark:group-hover:fill-gray-700/50">
                            <title>{{ Overview::bucketLabel($b['ts'], $d['bucket_ms']) }}&#10;{{ Fmt::num($b['requests']) }} gesamt&#10;@foreach (Overview::SERIES as $k => [$label]){{ Fmt::num($b[$k]) }} {{ $label }}&#10;@endforeach{{ Fmt::bytes($b['bytes']) }} Download</title>
                        </rect>
                        @foreach ($bar['segs'] as $s)
                            <rect x="{{ $s['x'] }}" y="{{ $s['y'] }}" width="{{ $s['w'] }}" height="{{ $s['h'] }}" rx="{{ $loop->last ? min(4, $s['w'] / 2) : 0 }}" class="{{ Overview::SERIES[$s['key']][1] }}" pointer-events="none" />
                        @endforeach
                    </g>
                @endforeach
            </svg>
            <details class="mt-2">
                <summary class="cursor-pointer text-sm text-cerulean-700 dark:text-cerulean-300">Als Tabelle anzeigen</summary>
                <div class="mt-2 max-h-72 overflow-auto">
                    <table class="w-full text-right text-xs tabular-nums">
                        <thead class="text-gray-500"><tr><th class="py-1 text-left">Zeitraum</th><th>Gesamt</th>@foreach (array_keys(Overview::SERIES) as $k)<th>{{ substr($k, 1) }}</th>@endforeach<th>Download</th><th>Upload</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach (array_reverse(array_filter($d['series'], fn ($b) => $b['requests'] > 0)) as $b)
                                <tr><td class="whitespace-nowrap py-1 text-left">{{ Overview::bucketLabel($b['ts'], $d['bucket_ms']) }}</td><td>{{ Fmt::num($b['requests']) }}</td>
                                    @foreach (array_keys(Overview::SERIES) as $k)<td>{{ Fmt::num($b[$k]) }}</td>@endforeach
                                    <td>{{ Fmt::bytes($b['bytes']) }}</td><td>{{ $b['ts'] + $d['bucket_ms'] <= $d['upload_since'] ? '–' : Fmt::bytes($b['bytes_in']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </x-panel>

        {{-- data over time: own scale per chart, upload would vanish next to download --}}
        <x-panel class="m-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="{{ $card }}">Datenmenge im Zeitverlauf</div>
                <span class="text-xs text-gray-500">Jedes Diagramm hat eine eigene Skala</span>
            </div>
            <div class="mt-3 grid grid-cols-1 gap-6 lg:grid-cols-2">
                @foreach (['bytes' => 'Download', 'bytes_in' => 'Upload'] as $key => $title)
                    @php $t = Overview::bars($d['series'], [$key], $d['bucket_ms'], 600, 150, 58, 3, fn ($v) => Fmt::bytes($v)); @endphp
                    <figure>
                        <figcaption class="flex justify-between text-sm"><span class="font-medium">{{ $title }}</span><span class="text-gray-500">{{ Fmt::bytes($d[$key]) }}</span></figcaption>
                        <svg viewBox="0 0 {{ $t['width'] }} {{ $t['height'] }}" class="mt-2 w-full text-gray-400" role="img" aria-label="{{ $title }} im Zeitverlauf">
                            @foreach ($t['grid'] as $g)
                                <line x1="{{ $t['m']['left'] }}" x2="{{ $t['width'] - $t['m']['right'] }}" y1="{{ $g['y'] }}" y2="{{ $g['y'] }}" @class(['stroke-gray-300 dark:stroke-gray-600' => $g['base'], 'stroke-gray-100 dark:stroke-gray-700' => ! $g['base']]) />
                                <text x="{{ $t['m']['left'] - 8 }}" y="{{ $g['y'] + 4 }}" text-anchor="end" class="fill-current text-[11px]">{{ $g['label'] }}</text>
                            @endforeach
                            @foreach ($t['labels'] as $l)
                                <text x="{{ $l['x'] }}" y="{{ $t['height'] - 5 }}" text-anchor="middle" class="fill-current text-[11px]">{{ $l['text'] }}</text>
                            @endforeach
                            @foreach ($t['bars'] as $bar)
                                @php $missing = $key === 'bytes_in' && $bar['b']['ts'] + $d['bucket_ms'] <= $d['upload_since']; @endphp
                                <g class="group">
                                    <rect x="{{ $bar['band']['x'] }}" y="{{ $t['m']['top'] }}" width="{{ $bar['band']['w'] }}" height="{{ $t['plotH'] + 1 }}" class="fill-transparent group-hover:fill-gray-100 dark:group-hover:fill-gray-700/50">
                                        <title>{{ Overview::bucketLabel($bar['b']['ts'], $d['bucket_ms']) }}&#10;{{ $missing ? '– noch nicht erfasst' : Fmt::bytes($bar['b'][$key]).' '.$title }}</title>
                                    </rect>
                                    @foreach ($bar['segs'] as $s)
                                        <rect x="{{ $s['x'] }}" y="{{ $s['y'] }}" width="{{ min(16, $s['w']) }}" height="{{ $s['h'] }}" rx="{{ min(4, $s['w'] / 2) }}" class="fill-cerulean-500" pointer-events="none" />
                                    @endforeach
                                </g>
                            @endforeach
                        </svg>
                    </figure>
                @endforeach
            </div>
        </x-panel>

        {{-- origin --}}
        @php
            $counts = collect($d['countries'])->pluck('count', 'key')->all();
            $mapKey = md5(json_encode($counts));
        @endphp
        <div class="grid grid-cols-1 lg:grid-cols-[2fr_1fr]">
            <x-panel class="m-3">
                <div class="proxy-map" wire:key="map-{{ $mapKey }}" x-data="proxyWorldMap(@js($counts), @js(collect(Fmt::countries())->only(array_keys($counts))->all()), {{ (int) $d['requests'] }}, @js($link(['country' => '__C__'])))">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="{{ $card }}">Herkunft der Anfragen</div>
                        <div class="flex rounded-lg border border-gray-200 p-0.5 dark:border-gray-700" role="group" aria-label="Kartenausschnitt">
                            <button type="button" x-on:click="setView('world')" :aria-pressed="view === 'world'" :class="view === 'world' ? 'bg-cerulean-600 text-white' : 'text-gray-600 dark:text-gray-300'" class="rounded-md px-3 py-1 text-xs">Welt</button>
                            <button type="button" x-on:click="setView('europe')" :aria-pressed="view === 'europe'" :class="view === 'europe' ? 'bg-cerulean-600 text-white' : 'text-gray-600 dark:text-gray-300'" class="rounded-md px-3 py-1 text-xs">Europa</button>
                        </div>
                    </div>
                    <div class="relative mt-3" x-ref="box" x-on:pointerleave="tip = null">
                        <svg x-ref="svg" class="h-auto w-full" preserveAspectRatio="xMidYMid slice" role="img" aria-label="Weltkarte der Anfragen nach Land. Die Werte stehen auch in der Tabelle darunter."></svg>
                        <div x-show="tip" x-cloak class="pointer-events-none absolute rounded-md bg-gray-900/90 px-2 py-1 text-xs text-white shadow" :style="tip && `left:${tip.x}px;top:${tip.y}px`" x-text="tip && tip.text"></div>
                        <p x-show="error" x-text="error" class="text-sm text-gray-500"></p>
                    </div>
                    <div class="mt-2 flex items-center gap-1 text-xs text-gray-500">
                        <span>wenige</span>
                        @foreach ([1, 2, 3, 4, 5] as $i)<i class="h-2.5 w-4 rounded-sm" style="background: var(--proxy-map-{{ $i }})"></i>@endforeach
                        <span>{{ Fmt::num(max($counts ?: [0])) }} Anfragen</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500" x-show="offMap" x-text="offMap"></p>
                </div>
                <details class="mt-2">
                    <summary class="cursor-pointer text-sm text-cerulean-700 dark:text-cerulean-300">Alle Länder als Tabelle</summary>
                    <div class="mt-2 max-h-72 overflow-auto">
                        <table class="w-full text-xs tabular-nums">
                            <thead class="text-gray-500"><tr><th class="py-1 text-left">Land</th><th class="text-right">Anfragen</th><th class="text-right">Download</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($d['countries'] as $ct)
                                    <tr><td class="py-1">{{ Fmt::flag($ct['key']) }} {{ Fmt::country($ct['key']) }}</td><td class="text-right">{{ Fmt::num($ct['count']) }}</td><td class="text-right">{{ Fmt::bytes($ct['bytes'] ?? 0) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </x-panel>
            @include('modules.proxy.partials.top-card', ['title' => 'Länder', 'items' => array_slice($d['countries'], 0, 10),
                'topLabel' => fn ($k) => Fmt::flag($k).' '.Fmt::country($k), 'topHref' => fn ($k) => $k ? $link(['country' => $k]) : null])
        </div>

        {{-- top lists --}}
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3">
            @unless ($host)
                @include('modules.proxy.partials.top-card', ['title' => 'Hosts', 'items' => $d['hosts'], 'topLabel' => fn ($k) => $k ?: '(ohne Hostname)',
                    'topHref' => fn ($k) => $link(['host' => $k]), 'topUnknown' => $configured])
            @endunless
            @include('modules.proxy.partials.top-card', ['title' => 'Pfade', 'items' => $d['paths'], 'topHref' => fn ($k) => $link(['q' => $k])])
            @include('modules.proxy.partials.top-card', ['title' => 'IP-Adressen', 'items' => $d['ips'], 'topHref' => fn ($k) => $link(['ip' => $k])])
            @include('modules.proxy.partials.top-card', ['title' => 'Clients', 'items' => $d['clients']])
            @include('modules.proxy.partials.top-card', ['title' => 'HTTP-Versionen', 'items' => $d['protos']])
            @include('modules.proxy.partials.top-card', ['title' => 'Statuscodes', 'items' => $d['statuses'], 'topHref' => fn ($k) => $link(['status' => $k])])
            @include('modules.proxy.partials.top-card', ['title' => 'Verweise', 'items' => $d['referers']])
        </div>
    @endif
</div>
