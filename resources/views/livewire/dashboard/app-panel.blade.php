{{--
    One app on the dashboard: figures of the last 24 h and a small chart (the same building
    blocks as the module pages). $f = AppFigures::for($app).
--}}
@php
    use App\Modules\Dns\Fmt as DnsFmt;
    use App\Modules\Dns\Overview as DnsOverview;
    use App\Modules\Proxy\Fmt as ProxyFmt;
    use App\Modules\Proxy\Overview as ProxyOverview;

    $home = ['dns' => 'overview', 'proxy' => 'overview', 'uptime' => 'monitors', 'proxapps' => 'dashboard', 'ad' => 'domain', 'file' => 'server'][$app?->app_id] ?? null;
    // PK95 AD and PK95 File show a state, not figures of 24 h: the realm / the server name stand there instead
    $corner = match ($appId) {
        'ad' => ($f['ok'] ?? false) ? ($f['realm'] ?? '') : '',
        'file' => ($f['ok'] ?? false) ? ($f['fqdn'] ?? '') : '',
        default => '24 h',
    };
    $href = $app ? ($home ? route('apps.page', [$app, $home]) : route('apps.show', $app)) : route('apps.index');
    $label = 'text-xs text-gray-500 dark:text-gray-400';
    $value = 'text-xl font-DINPro-bold text-gray-900 dark:text-gray-100';
@endphp

<div wire:poll.60s>
    <x-panel class="flex h-full flex-col" data-app-panel="{{ $appId }}">
        <a href="{{ $href }}" wire:navigate class="group flex items-center gap-2">
            <x-icon :name="$app?->manifest['icon'] ?? $app?->definition()?->icon ?? 'box'" class="h-5 w-5 shrink-0 text-cerulean-600 dark:text-cerulean-400" />
            <span class="min-w-0 flex-1 truncate font-DINPro-bold text-gray-900 group-hover:text-cerulean-700 dark:text-gray-100 dark:group-hover:text-cerulean-300">{{ $app?->displayName() ?? $appId }}</span>
            @if ($corner !== '')<span class="min-w-0 shrink truncate text-xs text-gray-500 dark:text-gray-400" @if ($appId === 'ad') data-realm @endif>{{ $corner }}</span>@endif
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
            @if ($app && ($w = $f['waf'] ?? null))
                {{-- web firewall of PK95 Proxy, today since midnight --}}
                <div class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900/40" data-waf-tile>
                    <a href="{{ route('apps.page', [$app, 'waf']) }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-gray-100">Web-Firewall heute</a>
                    <span class="text-gray-600 dark:text-gray-300">
                        @if ($w['hits'] === null)
                            · keine Zahlen
                        @else
                            · {{ ProxyFmt::num($w['hits']) }} Treffer · <span @class(['text-rose-700 dark:text-rose-300' => $w['blocks'] > 0])>{{ ProxyFmt::num($w['blocks']) }} blockiert</span>
                        @endif
                    </span>
                    @if ($w['rules'] || $w['hosts'])
                        <div class="mt-1 grid grid-cols-1 gap-x-4 gap-y-0.5 text-xs text-gray-500 sm:grid-cols-2 dark:text-gray-400">
                            @if ($w['rules'])
                                <div class="min-w-0 truncate" data-waf-tile-rules>Regeln: @foreach ($w['rules'] as $r)<span data-tip="{{ $r['msg'] ?? '' }}" class="font-mono">{{ $r['rule_id'] ?? '?' }}</span> ({{ ProxyFmt::num((int) ($r['count'] ?? 0)) }}){{ $loop->last ? '' : ', ' }}@endforeach</div>
                            @endif
                            @if ($w['hosts'])
                                <div class="min-w-0 truncate" data-waf-tile-hosts>Hosts: @foreach ($w['hosts'] as $h){{ $h['domain'] ?? '?' }} ({{ ProxyFmt::num((int) ($h['count'] ?? 0)) }}){{ $loop->last ? '' : ', ' }}@endforeach</div>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
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
        @elseif ($appId === 'ad' && ! ($f['ready'] ?? false))
            <p class="mt-3 flex items-start gap-2 text-sm text-amber-800 dark:text-amber-200" data-ad-unconfigured>
                <x-icon name="warn" class="mt-0.5 h-4 w-4 shrink-0" sw="2" />
                <span>{{ ($f['state'] ?? '') === 'provisioning' ? 'Die Domäne wird gerade eingerichtet.' : 'Die Domäne ist noch nicht eingerichtet.' }}
                    <a href="{{ $href }}" wire:navigate class="font-medium underline">Zum Einrichtungsassistenten</a></span>
            </p>
        @elseif ($appId === 'ad')
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div><div class="{{ $label }}">Benutzer</div><div class="{{ $value }}">{{ $f['users'] }}</div></div>
                <div><div class="{{ $label }}">Gruppen</div><div class="{{ $value }}">{{ $f['groups'] }}</div></div>
                <div><div class="{{ $label }}">Computer</div><div class="{{ $value }}">{{ $f['computers'] }}</div></div>
                <div><div class="{{ $label }}">Gruppenrichtlinien</div><div class="{{ $value }}">{{ $f['gpos'] }}</div></div>
            </div>
            {{-- the clock of the DC (PK95 AD 1.4.0), ticking in the browser; older versions: no line --}}
            @if ($t = $f['time'] ?? null)
                @php $tone = \App\Modules\Ad\AdTime::tone($t['status']); @endphp
                <div class="mt-3 flex flex-wrap items-baseline gap-x-3 gap-y-0.5" data-ad-time data-time-status="{{ $t['status'] }}">
                    <span class="{{ $label }}">Uhrzeit</span>
                    @include('modules.ad.partials.clock', ['skew' => $t['skew_ms'], 'class' => 'text-base font-DINPro-bold text-gray-900 dark:text-gray-100'])
                    <span @class(['min-w-0 text-xs', 'text-gray-500 dark:text-gray-400' => in_array($tone, ['ok', 'muted'], true),
                        'text-amber-800 dark:text-amber-300' => $tone === 'warn', 'text-rose-700 dark:text-rose-300' => $tone === 'bad'])>{{ \App\Modules\Ad\AdTime::line($t) }}</span>
                </div>
                @if (in_array($tone, ['warn', 'bad'], true) && $t['message'] !== '')
                    <p @class(['mt-0.5 text-xs', 'text-amber-800 dark:text-amber-300' => $tone === 'warn', 'text-rose-700 dark:text-rose-300' => $tone === 'bad']) data-time-message>{{ $t['message'] }}</p>
                @endif
            @endif
            {{-- status line: each check with a dot, the app's message as tooltip when it fails --}}
            <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-700 dark:text-gray-300" data-ad-checks>
                @foreach ($f['checks'] as $check)
                    <li class="inline-flex items-center gap-1.5" @unless ($check['ok']) data-tip="{{ $check['message'] }}" data-check-failed @endunless>
                        <span @class(['h-2 w-2 shrink-0 rounded-full', 'bg-green-500' => $check['ok'], 'bg-amber-500' => ! $check['ok'] && ($check['tone'] ?? 'warn') !== 'bad', 'bg-rose-500' => ($check['tone'] ?? '') === 'bad']) aria-hidden="true" data-tone="{{ $check['tone'] ?? ($check['ok'] ? 'ok' : 'warn') }}"></span>
                        {{ $check['label'] }}<span class="sr-only">: {{ $check['ok'] ? 'in Ordnung' : $check['message'] }}</span>
                    </li>
                @endforeach
                @if ($f['locked'] !== null)
                    <li @class(['inline-flex items-center gap-1.5', 'font-medium text-rose-700 dark:text-rose-300' => $f['locked'] > 0]) data-ad-locked>
                        <x-icon name="lock" class="h-3.5 w-3.5 shrink-0" />{{ $f['locked'] === 1 ? '1 gesperrtes Konto' : $f['locked'].' gesperrte Konten' }}
                    </li>
                @endif
            </ul>
        @elseif ($appId === 'file' && ! ($f['ready'] ?? false))
            <p class="mt-3 flex items-start gap-2 text-sm text-amber-800 dark:text-amber-200" data-file-unconfigured>
                <x-icon name="warn" class="mt-0.5 h-4 w-4 shrink-0" sw="2" />
                <span>{{ match ($f['state'] ?? '') { 'joining' => 'Der Dateiserver tritt gerade der Domäne bei.', 'failed' => 'Der Beitritt zur Domäne ist fehlgeschlagen.', default => 'Der Dateiserver ist noch nicht eingerichtet.' } }}
                    <a href="{{ $href }}" wire:navigate class="font-medium underline">Zum Einrichtungsassistenten</a></span>
            </p>
        @elseif ($appId === 'file')
            @php
                $usedPct = $f['total'] > 0 ? min(100, (int) round($f['used'] / $f['total'] * 100)) : 0;
                $barTone = ['ok' => 'bg-cerulean-500', 'warn' => 'bg-amber-500', 'bad' => 'bg-rose-500'][$f['diskTone']] ?? 'bg-cerulean-500';
                $freeTone = ['ok' => '', 'warn' => 'text-amber-800 dark:text-amber-300', 'bad' => 'text-rose-700 dark:text-rose-300'][$f['diskTone']] ?? '';
            @endphp
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div><div class="{{ $label }}">Freigaben</div><div class="{{ $value }}">{{ $f['shares'] }}</div></div>
                <div><div class="{{ $label }}">Sitzungen</div><div class="{{ $value }}">{{ $f['sessions'] }}</div></div>
                <div><div class="{{ $label }}">Offene Dateien</div><div class="{{ $value }}">{{ $f['openFiles'] }}</div></div>
                <div><div class="{{ $label }}">Frei</div><div class="{{ $value }} whitespace-nowrap {{ $freeTone }}" data-file-free>{{ \App\Http\Support\Format::bytes($f['free']) }}</div></div>
            </div>
            <div class="mt-3" data-file-storage data-tone="{{ $f['diskTone'] }}">
                <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="img" aria-label="{{ $usedPct }} % belegt">
                    <div class="h-full rounded-full {{ $barTone }}" style="width: {{ $usedPct }}%"></div>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ \App\Http\Support\Format::bytes($f['used']) }} von {{ \App\Http\Support\Format::bytes($f['total']) }} belegt{{ $f['freePercent'] !== null ? ', '.$f['freePercent'].' % frei' : '' }}
                    · {{ $f['snapshots'] === 1 ? '1 Vorgängerversion' : $f['snapshots'].' Vorgängerversionen' }}@unless ($f['snapshotsOk']) <span class="text-rose-700 dark:text-rose-300">(letzte fehlgeschlagen)</span>@endunless
                </p>
            </div>
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
