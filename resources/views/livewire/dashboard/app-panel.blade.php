{{--
    One app on the dashboard: figures of the last 24 h and a small chart (the same building
    blocks as the module pages). $f = AppFigures::for($app).
--}}
@php
    use App\Modules\Dns\Fmt as DnsFmt;
    use App\Modules\Dns\Overview as DnsOverview;
    use App\Modules\Proxy\Fmt as ProxyFmt;
    use App\Modules\Proxy\Overview as ProxyOverview;

    $home = ['dns' => 'overview', 'proxy' => 'overview', 'uptime' => 'monitors', 'proxapps' => 'dashboard'][$app?->app_id] ?? null;
    $href = $app ? ($home ? route('apps.page', [$app, $home]) : route('apps.show', $app)) : route('apps.index');
    $label = 'text-xs text-gray-500 dark:text-gray-400';
    $value = 'text-xl font-DINPro-bold text-gray-900 dark:text-gray-100';
@endphp

<div wire:poll.60s>
    <x-panel class="flex h-full flex-col" data-app-panel="{{ $appId }}">
        <a href="{{ $href }}" wire:navigate class="group flex items-center gap-2">
            <x-icon :name="$app?->manifest['icon'] ?? $app?->definition()?->icon ?? 'box'" class="h-5 w-5 shrink-0 text-cerulean-600 dark:text-cerulean-400" />
            <span class="min-w-0 flex-1 truncate font-DINPro-bold text-gray-900 group-hover:text-cerulean-700 dark:text-gray-100 dark:group-hover:text-cerulean-300">{{ $app?->displayName() ?? $appId }}</span>
            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">24 h</span>
        </a>

        @if (! $f['ok'])
            <p class="mt-3 flex items-start gap-2 text-sm text-amber-800 dark:text-amber-200" data-panel-error>
                <x-icon name="warn" class="mt-0.5 h-4 w-4 shrink-0" sw="2" /><span>{{ $f['error'] }}</span>
            </p>
        @elseif ($appId === 'dns')
            @php $c = DnsOverview::chart($f['series'], $f['daily'], 600, 140); @endphp
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div><div class="{{ $label }}">Anfragen</div><div class="{{ $value }}">{{ DnsFmt::compact($f['queries']) }}</div></div>
                <div><div class="{{ $label }}">Blockiert</div><div class="{{ $value }} text-rose-700 dark:text-rose-300">{{ DnsFmt::pct($f['blockedPct']) }}</div></div>
                <div><div class="{{ $label }}">Aus dem Cache</div><div class="{{ $value }}">{{ DnsFmt::pct($f['cachedPct']) }}</div></div>
                <div><div class="{{ $label }}">Antwortzeit</div><div class="{{ $value }}">{{ $f['avgMs'] !== null ? DnsFmt::ms($f['avgMs']) : '–' }}</div></div>
            </div>
            @if ($f['series'])
                <svg viewBox="0 0 {{ $c['width'] }} {{ $c['height'] }}" class="mt-3 w-full text-gray-500 dark:text-gray-400" role="img" aria-label="DNS-Anfragen der letzten 24 Stunden">
                    @foreach ($c['labels'] as $l)
                        <text x="{{ $l['x'] }}" y="{{ $c['height'] - 6 }}" text-anchor="middle" class="fill-current text-[11px]">{{ $l['text'] }}</text>
                    @endforeach
                    @foreach ($c['bars'] as $bar)
                        <g data-tip="{{ $bar['title'] }}">
                            @foreach ($bar['segs'] as $s)<rect x="{{ $s['x'] }}" y="{{ $s['y'] }}" width="{{ $bar['bw'] }}" height="{{ $s['h'] }}" class="{{ $s['class'] }}" />@endforeach
                        </g>
                    @endforeach
                </svg>
            @endif
        @elseif ($appId === 'proxy')
            @php $c = ProxyOverview::bars($f['series'], array_keys(ProxyOverview::SERIES), $f['bucketMs'], 600, 140, 40, 3, fn ($v) => ProxyFmt::compact($v)); @endphp
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div><div class="{{ $label }}">Anfragen</div><div class="{{ $value }}">{{ ProxyFmt::compact($f['requests']) }}</div></div>
                <div><div class="{{ $label }}">Serverfehler</div><div class="{{ $value }}">{{ ProxyFmt::pct($f['errorPct']) }}</div></div>
                <div><div class="{{ $label }}">Datenmenge</div><div class="{{ $value }} whitespace-nowrap">{{ ProxyFmt::bytes($f['bytes']) }}</div></div>
                <div><div class="{{ $label }}">Gesperrte Adressen</div><div class="{{ $value }}">{{ $f['bans'] ?? '–' }}</div></div>
            </div>
            @if ($f['hidden'])
                {{-- the remembered hidden clients of Proxy → Zugriffe are left out here too --}}
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400" data-tip="{{ implode(', ', $f['hidden']) }}" data-hidden-clients>
                    Ohne {{ count($f['hidden']) === 1 ? 'den ausgeblendeten Client' : count($f['hidden']).' ausgeblendete Clients' }}: <span aria-hidden="true">{{ \Illuminate\Support\Str::limit(implode(', ', $f['hidden']), 60) }}</span><span class="sr-only">{{ implode(', ', $f['hidden']) }}</span>
                </p>
            @endif
            @if ($f['series'])
                <svg viewBox="0 0 {{ $c['width'] }} {{ $c['height'] }}" class="mt-3 w-full text-gray-400" role="img" aria-label="Proxy-Zugriffe der letzten 24 Stunden nach Statusklasse">
                    @foreach ($c['labels'] as $l)
                        <text x="{{ $l['x'] }}" y="{{ $c['height'] - 6 }}" text-anchor="middle" class="fill-current text-[11px]">{{ $l['text'] }}</text>
                    @endforeach
                    @foreach ($c['bars'] as $bar)
                        <g data-tip="{{ ProxyOverview::bucketLabel((int) $bar['b']['ts'], $f['bucketMs'], $bar['end']) }}&#10;{{ ProxyFmt::num($bar['b']['requests'] ?? 0) }} Anfragen">
                            @foreach ($bar['segs'] as $s)<rect x="{{ $s['x'] }}" y="{{ $s['y'] }}" width="{{ $s['w'] }}" height="{{ $s['h'] }}" class="{{ ProxyOverview::SERIES[$s['key']][1] }}" />@endforeach
                        </g>
                    @endforeach
                </svg>
            @endif
        @elseif ($appId === 'uptime')
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div><div class="{{ $label }}">Monitore auf</div><div class="{{ $value }}">{{ $f['up'] }} <span class="text-sm font-normal text-gray-500">/ {{ $f['total'] }}</span></div></div>
                <div><div class="{{ $label }}">Ausgefallen</div><div @class([$value, 'text-rose-700 dark:text-rose-300' => $f['down'] > 0])>{{ $f['down'] }}</div></div>
                <div><div class="{{ $label }}">Verfügbarkeit</div><div class="{{ $value }}">{{ $f['availability'] !== null ? number_format($f['availability'], $f['availability'] >= 99.995 ? 0 : 2, ',', '.').' %' : '–' }}</div></div>
                <div><div class="{{ $label }}">Ausfälle</div><div class="{{ $value }}">{{ $f['outages'] ?? '–' }}</div></div>
            </div>
            @if ($f['strips'])
                <ul class="mt-3 space-y-1.5">
                    @foreach ($f['strips'] as $m)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="w-28 min-w-0 flex-1 truncate text-xs text-gray-700 dark:text-gray-200" data-tip="{{ $m['name'] }}">{{ $m['name'] }}</span>
                            <span class="flex items-end gap-px" role="img" aria-label="{{ $m['name'] }}: {{ $m['strip']['label'] }}">
                                @foreach ($m['strip']['bars'] as $bar)
                                    <span @class(['h-4 w-1 shrink-0 rounded-sm', $bar['class'], 'max-sm:hidden' => $loop->index < intdiv($loop->count, 2)]) data-tip="{{ $bar['title'] }}"></span>
                                @endforeach
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        @elseif ($appId === 'proxapps')
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div><div class="{{ $label }}">Apps (LXC)</div><div class="{{ $value }}">{{ $f['deployed'] }}</div></div>
                <div><div class="{{ $label }}">Laufen gerade</div><div class="{{ $value }}">{{ $f['active'] }}</div></div>
                <div><div class="{{ $label }}">Fehlgeschlagen</div><div @class([$value, 'text-rose-700 dark:text-rose-300' => $f['failed'] > 0])>{{ $f['failed'] }}</div></div>
                <div><div class="{{ $label }}">Verbindungen</div><div class="{{ $value }}">{{ $f['connections'] }}</div></div>
            </div>
        @endif
    </x-panel>
</div>
