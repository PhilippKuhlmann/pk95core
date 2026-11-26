@php
    use App\Modules\File\Server;
    use Illuminate\Support\Js;
    $card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
    $label = 'text-xs text-gray-500 dark:text-gray-400';
    $value = 'text-sm text-gray-900 dark:text-gray-100';
    $server = is_array($setup['server'] ?? null) ? $setup['server'] : (is_array($status['server'] ?? null) ? $status['server'] : null);
@endphp

{{-- poll while the server joins (progress and log), otherwise every 30 s --}}
<div wire:poll.{{ $state === 'joining' ? '2s' : '30s' }}>
    <x-page-header title="Dateiserver">
        <x-slot:sub>
            @if ($state === 'ready' && $server) {{ $server['fqdn'] ?? '' }} · {{ $server['ip'] ?? ($status['ip']['address'] ?? '') }} @elseif ($state === 'joining') Tritt der Domäne bei … @else Noch nicht eingerichtet @endif
        </x-slot:sub>
    </x-page-header>

    @if ($loadError)
        <x-callout title="PK95 File antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @elseif ($state !== 'ready')
        @include('modules.file.partials.setup')
    @else
        @include('modules.file.partials.join-problems')

        {{-- dashboard: storage first, the snapshots are the largest consumer on a small mount point --}}
        @if ($dashboardError)
            <x-callout title="Übersicht nicht verfügbar" ton="gelb" class="m-3">{{ $dashboardError }}</x-callout>
        @elseif ($dashboard)
            @php
                $bar = Server::storageBar($dashboard, $settings);
                $storage = (array) ($dashboard['storage'] ?? []);
                $snap = (array) ($dashboard['snapshots'] ?? []);
                $segClass = ['share_bytes' => 'bg-cerulean-500', 'snapshot_bytes' => 'bg-violet-500', 'recycle_bytes' => 'bg-amber-400', 'other' => 'bg-gray-400 dark:bg-gray-500',
                    'free' => ['ok' => 'bg-gray-100 dark:bg-gray-700', 'warn' => 'bg-amber-200 dark:bg-amber-900/60', 'bad' => 'bg-rose-200 dark:bg-rose-900/60'][$bar['tone']]];
                $freeText = ['ok' => 'text-gray-700 dark:text-gray-300', 'warn' => 'text-amber-800 dark:text-amber-300', 'bad' => 'text-rose-700 dark:text-rose-300'][$bar['tone']];
            @endphp
            <x-panel class="m-3" data-storage>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Speicherplatz</div>
                    <div class="text-sm {{ $freeText }}" data-storage-free>{{ Server::bytes($storage['free_bytes'] ?? 0) }} frei von {{ Server::bytes($storage['total_bytes'] ?? 0) }} ({{ $bar['free_percent'] }} %)</div>
                </div>
                <div class="mt-3 flex h-4 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="img" data-storage-bar data-tone="{{ $bar['tone'] }}"
                    aria-label="Belegung: {{ collect($bar['segments'])->map(fn ($s) => $s['label'].' '.Server::bytes($s['bytes']))->implode(', ') }}">
                    @foreach ($bar['segments'] as $seg)
                        @if ($seg['percent'] > 0)
                            <span class="h-full {{ $segClass[$seg['key']] }}" style="width: {{ round($seg['percent'], 2) }}%" data-tip="{{ $seg['label'] }}: {{ Server::bytes($seg['bytes']) }}"></span>
                        @endif
                    @endforeach
                </div>
                <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                    @foreach ($bar['segments'] as $seg)
                        @if ($seg['key'] !== 'other' || $seg['bytes'] > 0)
                            <li class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm {{ $segClass[$seg['key']] }}"></span>{{ $seg['label'] }} {{ Server::bytes($seg['bytes']) }}</li>
                        @endif
                    @endforeach
                </ul>
                @if ($storage['scanned_at'] ?? 0)
                    <p class="mt-1 {{ $label }}">Belegung der Freigaben vom {{ Server::when($storage['scanned_at']) }}, freier Platz aktuell.</p>
                @endif
            </x-panel>

            <div class="m-3 grid grid-cols-2 gap-3 md:grid-cols-4">
                <a href="{{ route('apps.page', [$installedApp, 'shares']) }}" wire:navigate class="{{ $card }} hover:border-cerulean-300"><div class="{{ $label }}">Freigaben</div><div class="text-xl font-DINPro-bold text-gray-900 dark:text-gray-100">{{ (int) ($dashboard['shares'] ?? 0) }}</div></a>
                <a href="{{ route('apps.page', [$installedApp, 'sessions']) }}" wire:navigate class="{{ $card }} hover:border-cerulean-300"><div class="{{ $label }}">Sitzungen</div><div class="text-xl font-DINPro-bold text-gray-900 dark:text-gray-100">{{ (int) ($dashboard['sessions'] ?? 0) }}</div></a>
                <a href="{{ route('apps.page', [$installedApp, 'sessions']) }}" wire:navigate class="{{ $card }} hover:border-cerulean-300"><div class="{{ $label }}">Offene Dateien</div><div class="text-xl font-DINPro-bold text-gray-900 dark:text-gray-100">{{ (int) ($dashboard['open_files'] ?? 0) }}</div></a>
                <div class="{{ $card }}" data-snapshots-status>
                    <div class="{{ $label }}">Vorgängerversionen</div>
                    <div class="text-xl font-DINPro-bold text-gray-900 dark:text-gray-100">{{ (int) ($snap['count'] ?? 0) }}</div>
                    <div class="{{ $label }} mt-1">
                        @if ($snap['last_run_at'] ?? 0)
                            Zuletzt {{ Server::when($snap['last_run_at']) }}
                            @if (! ($snap['last_ok'] ?? true))<span class="font-medium text-rose-700 dark:text-rose-300">(fehlgeschlagen)</span>@endif
                        @else
                            Noch keine erstellt
                        @endif
                        @if ($snap['next_run_at'] ?? 0)<span class="block">Nächste {{ Server::when($snap['next_run_at']) }}</span>@endif
                    </div>
                </div>
            </div>

            @if ($dashboard['warnings'] ?? [])
                <x-panel class="m-3" data-file-warnings>
                    <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Warnungen</div>
                    <ul class="mt-2 space-y-1.5 text-sm">
                        @foreach ((array) $dashboard['warnings'] as $w)
                            <li @class(['flex items-start gap-2', 'text-rose-700 dark:text-rose-300' => ($w['level'] ?? '') === 'critical', 'text-amber-800 dark:text-amber-300' => ($w['level'] ?? '') !== 'critical'])>
                                <x-icon name="warn" class="mt-0.5 h-4 w-4 shrink-0" sw="2" /><span>{{ $w['message'] ?? '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-panel>
            @endif
        @endif

        {{-- services, trust, address --}}
        @if ($status)
            @php
                $dot = fn (bool $ok) => $ok ? 'bg-green-500' : 'bg-rose-500';
                $ipInfo = (array) ($status['ip'] ?? []);
                $idmap = (array) ($status['idmap'] ?? []);
            @endphp
            <x-panel class="m-3" data-status>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Dienste</div>
                    @if (Server::runningAd())
                        <x-input.button type="button" size="sm" color="gray" data-rejoin
                            x-on:click="$store.confirm.ask({ title: 'Neu beitreten?', text: 'PK95 AD setzt das Computerkonto zurück und der Dateiserver tritt mit neuen Beitrittsdaten bei. Das hilft, wenn die Vertrauensstellung gestört ist oder eine alte Sicherung zurückgespielt wurde. Laufende Sitzungen werden kurz getrennt.', button: 'Neu beitreten', tone: 'blue', ok: () => $wire.rejoin() })">
                            <x-icon name="refresh" class="h-4 w-4" sw="2" />Neu beitreten
                        </x-input.button>
                    @endif
                </div>
                <div class="mt-2 flex flex-wrap gap-x-5 gap-y-2 text-sm">
                    @foreach (['smbd' => 'Samba (smbd)', 'winbindd' => 'Winbind'] as $key => $name)
                        @php $st = (string) ($status[$key]['state'] ?? ''); @endphp
                        <span>{{ $name }}: <span @class(['font-medium', 'text-green-700 dark:text-green-300' => $st === 'running', 'text-rose-700 dark:text-rose-300' => $st !== 'running'])>{{ Server::SERVICE_STATES[$st] ?? ($st ?: '–') }}</span></span>
                    @endforeach
                    <span class="inline-flex items-center gap-1.5" @if (($status['trust']['message'] ?? '') !== '') data-tip="{{ $status['trust']['message'] }}" @endif data-trust>
                        <span class="h-2 w-2 rounded-full {{ $dot((bool) ($status['trust']['ok'] ?? false)) }}"></span>Vertrauensstellung {{ ($status['trust']['ok'] ?? false) ? 'in Ordnung' : 'gestört' }}
                    </span>
                    <span class="inline-flex items-center gap-1.5" data-ip>
                        <span class="h-2 w-2 rounded-full {{ $dot(($ipInfo['present'] ?? false) && ! ($ipInfo['conflict'] ?? false)) }}"></span>
                        <span class="font-mono">{{ $ipInfo['address'] ?? '–' }}</span>
                        <span class="{{ $label }}">{{ ($ipInfo['mode'] ?? '') === 'existing' ? 'im LXC eingerichtet' : 'von PK95 File' }}{{ ($ipInfo['interface'] ?? '') !== '' ? ' auf '.$ipInfo['interface'] : '' }}{{ ($ipInfo['conflict'] ?? false) ? ', im Netz doppelt vergeben' : (($ipInfo['present'] ?? true) ? '' : ', fehlt') }}</span>
                    </span>
                </div>
                @foreach (['smbd', 'winbindd'] as $key)
                    @if (($status[$key]['last_exit'] ?? '') !== '')
                        <p class="mt-2 text-xs text-rose-700 dark:text-rose-300">{{ $key }} zuletzt beendet: {{ $status[$key]['last_exit'] }}</p>
                    @endif
                @endforeach
                @if (($idmap['warning'] ?? '') !== '')
                    <x-callout title="Unix-IDs" ton="gelb" class="mt-3" data-idmap-warning>{{ $idmap['warning'] }}</x-callout>
                @endif
                @if ($server)
                    <table class="mt-3 w-full text-sm">
                        <x-kv label="Server" :value="$server['fqdn'] ?? null" mono />
                        <x-kv label="Domäne" :value="trim(($server['realm'] ?? '').' · '.($server['workgroup'] ?? ''), ' ·')" />
                        <x-kv label="Domänencontroller" :value="is_array($server['dc'] ?? null) ? ($server['dc']['hostname'] ?? '') : ($server['dc'] ?? null)" mono />
                    </table>
                @endif
            </x-panel>
        @endif

        {{-- settings --}}
        <x-panel class="m-3" data-settings>
            <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Einstellungen</div>
            @if ($settingsError)
                <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">{{ $settingsError }}</p>
            @elseif ($settings)
                <form wire:submit="saveSettings" class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @foreach (Server::SETTINGS as $key => [$title, $hint])
                        <x-field :label="$title" :for="'fs-'.$key" :feld="'settings.'.$key" :hilfe="$hint">
                            <x-input.text :id="'fs-'.$key" :feld="'settings.'.$key" type="number" min="0" wire:model="settings.{{ $key }}" class="mt-1 w-full" />
                        </x-field>
                    @endforeach
                    <x-field label="SMB-Verschlüsselung" for="fs-enc" feld="settings.smb_encryption">
                        <x-input.select id="fs-enc" feld="settings.smb_encryption" wire:model="settings.smb_encryption" class="mt-1">
                            @foreach (Server::SMB_ENCRYPTION as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </x-input.select>
                    </x-field>
                    <x-field label="Mindestens" for="fs-proto" feld="settings.min_protocol">
                        <x-input.select id="fs-proto" feld="settings.min_protocol" wire:model="settings.min_protocol" class="mt-1">
                            @foreach (Server::MIN_PROTOCOL as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </x-input.select>
                    </x-field>
                    @if (array_key_exists('macos', $settings))
                        <div class="sm:col-span-2" data-macos>
                            <x-input.checkbox wire:model="settings.macos" label="macOS-Unterstützung"
                                hilfe="Finder-Infos und Tags werden an der Datei gespeichert, keine ._-Dateien mehr in den Ordnern. Gilt für alle Freigaben." />
                        </div>
                    @endif
                    @if ($settingsNotice)
                        <x-callout title="Hinweis" class="sm:col-span-2" data-settings-notice>{{ $settingsNotice }}</x-callout>
                    @endif
                    <div class="flex justify-end sm:col-span-2">
                        <x-input.button type="submit" wire:loading.attr="disabled" wire:target="saveSettings">Speichern</x-input.button>
                    </div>
                </form>
            @endif
        </x-panel>
    @endif
</div>
