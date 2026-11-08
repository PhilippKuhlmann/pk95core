@php
    use App\Modules\Proxy\Fmt;
    use App\Modules\Proxy\Maintenance;
    $card = 'font-DINPro-bold text-gray-900 dark:text-gray-100';
@endphp

<div @if ($busy) wire:poll.3s @else wire:poll.30s @endif>
    <x-page-header title="Wartung">
        <x-slot:sub>Caddy, Panel und Betriebssystem kommen mit dem Image. Updates dafür laufen über PK95 Core.</x-slot:sub>
        <a href="{{ route('apps.show', $installedApp) }}" wire:navigate class="text-sm text-cerulean-700 hover:underline dark:text-cerulean-300">Image und Updates in Core</a>
    </x-page-header>

    @if ($loadError)
        <x-callout title="Wartung konnte nicht geladen werden" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    @if ($d)
        @if ($d['agent_error'] ?? null)
            <x-callout title="Update-Dienst" ton="gelb" class="m-3">{{ $d['agent_error'] }}</x-callout>
        @endif
        @if ($busy)
            <x-callout title="Läuft" class="m-3">
                <span class="flex items-center gap-2"><span class="h-2 w-2 animate-pulse rounded-full {{ Fmt::dot('warn') }}"></span>
                    {{ ! empty($agent['running']) ? (Maintenance::ACTIONS[$agent['running']] ?? $agent['running']).' läuft …' : 'Auftrag wartet auf den Update-Dienst …' }}</span>
            </x-callout>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2">
            <x-panel class="m-3 overflow-x-auto">
                <div class="flex items-center justify-between gap-2">
                    <div class="{{ $card }}">Komponenten</div>
                    <x-input.button type="button" size="sm" color="gray" wire:click="request('check')" :disabled="$busy">Jetzt prüfen</x-input.button>
                </div>
                @if ($agent && $agent['checked_at'])
                    <p class="mt-1 text-xs text-gray-500">Zuletzt geprüft: {{ Fmt::stamp($agent['checked_at']) }} · Debian {{ $agent['debian'] }} · Kernel {{ $agent['kernel'] }} (kommt vom Proxmox-Host)</p>
                @endif
                <table class="mt-3 w-full text-left text-sm">
                    <thead class="text-xs text-gray-500"><tr><th class="py-1 pr-3">Name</th><th class="py-1 pr-3">Version</th><th class="py-1 pr-3">Status</th><th class="py-1"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($agent['components'] ?? [] as $c)
                            <tr><td class="py-2 pr-3">{{ $c['name'] }}</td><td class="py-2 pr-3 font-mono text-xs">{{ $c['installed'] }}</td>
                                <td class="py-2 pr-3 text-xs text-gray-500">Mit dem Image</td><td></td></tr>
                        @endforeach
                        <tr>
                            <td class="py-2 pr-3">Länder-Datenbank</td>
                            <td class="py-2 pr-3 font-mono text-xs">{{ $d['geoip_date'] ? Fmt::date($d['geoip_date']) : '–' }}</td>
                            <td class="py-2 pr-3"><span class="flex items-center gap-2 text-xs"><span class="h-2 w-2 rounded-full {{ Fmt::dot($d['geoip_date'] ? 'good' : 'warn') }}"></span>{{ $d['geoip_date'] ? 'Monatlich automatisch' : 'Fehlt' }}</span></td>
                            <td class="py-2 text-right">
                                <x-input.button type="button" size="sm" color="gray" :disabled="$busy"
                                    x-on:click="$store.confirm.ask({ title: 'Länder-Datenbank aktualisieren?', tone: 'blue', button: 'Aktualisieren', text: 'Die aktuelle Länder-Datenbank wird geladen, das Panel startet dafür kurz neu.', ok: () => $wire.request('geoip') })">Aktualisieren</x-input.button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </x-panel>

            <x-panel class="m-3">
                <div class="{{ $card }}">PK95 Proxy</div>
                @php $v = $d['vulns']; @endphp
                <div class="mt-2 flex items-center gap-2 text-sm">
                    <span class="h-2 w-2 rounded-full {{ Fmt::dot(($v['error'] ?? '') ? 'warn' : ($vulns ? 'bad' : (($v['checked_at'] ?? 0) ? 'good' : 'warn'))) }}"></span>
                    {{ ($v['error'] ?? '') ?: (! ($v['checked_at'] ?? 0) ? 'Schwachstellenprüfung steht noch aus'
                        : ($vulns ? Fmt::plural(count($vulns), 'bekannte Schwachstelle', 'bekannte Schwachstellen').' laut OSV'
                        : 'Keine bekannten Schwachstellen laut OSV ('.Fmt::stamp($v['checked_at']).')')) }}
                </div>
                @if ($vulns)
                    <ul class="mt-2 list-disc pl-5 text-xs">
                        @foreach ($vulns as $x)
                            <li><a href="https://osv.dev/vulnerability/{{ rawurlencode($x['id']) }}" target="_blank" rel="noopener noreferrer" class="text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $x['id'] }}</a> in {{ $x['module'] }} {{ $x['version'] }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-gray-500">Behoben wird das mit einem neuen Image. PK95 Core installiert es nach deiner Update-Regel.</p>
                @endif
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm text-cerulean-700 dark:text-cerulean-300">Gebaut mit {{ $d['go_version'] }}, {{ count($d['modules']) }} Bibliotheken</summary>
                    <table class="mt-2 w-full text-left text-xs">
                        @foreach ($d['modules'] as $m)
                            <tr><td class="py-0.5 pr-3">{{ $m['path'] }}</td><td class="py-0.5 font-mono">{{ $m['version'] }}</td></tr>
                        @endforeach
                    </table>
                </details>
            </x-panel>
        </div>

        <x-panel class="m-3">
            <div class="{{ $card }}">Verlauf</div>
            @php $runs = $agent['runs'] ?? []; @endphp
            @if (! $runs)
                <p class="mt-2 text-sm text-gray-500">Noch keine Läufe.</p>
            @else
                <ol class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @foreach ($runs as $r)
                        <li class="py-2" wire:key="run-{{ $r['id'] }}">
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                <span class="whitespace-nowrap text-xs text-gray-500 tabular-nums">{{ Fmt::stamp($r['started']) }}</span>
                                <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot($r['ok'] ? 'good' : 'bad') }}"></span>{{ Maintenance::ACTIONS[$r['action']] ?? $r['action'] }}</span>
                                <span class="text-xs text-gray-500">{{ $r['trigger'] }}</span>
                                <span class="text-xs">{{ $r['ok'] ? ($r['changes'] ? Fmt::plural(count($r['changes']), 'Paket', 'Pakete') : 'Erledigt') : 'Fehlgeschlagen' }}</span>
                            </div>
                            @if ($r['error'] ?? '')<div class="mt-1 text-xs text-rose-700 dark:text-rose-300">{{ $r['error'] }}</div>@endif
                            @if ($r['log'] ?? '')
                                <details class="mt-1"><summary class="cursor-pointer text-xs text-cerulean-700 dark:text-cerulean-300">Details</summary>
                                    <pre class="mt-1 max-h-64 overflow-auto rounded bg-gray-50 p-2 text-xs dark:bg-gray-900">{{ $r['log'] }}</pre></details>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-panel>
    @endif
</div>
