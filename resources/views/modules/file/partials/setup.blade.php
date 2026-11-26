{{--
    PK95 File → Dateiserver while the server is not in the domain: needs PK95 AD set up, shows the
    suggested address and the preflight of the data folder, joins through Core (no admin password).
    Shares the variables of the server page ($setup, $state, $ad).
--}}
@php
    use App\Modules\File\Server;
    $defaults = (array) ($setup['defaults'] ?? []);
    $storage = (array) ($setup['storage'] ?? []);
    $problems = array_values((array) ($storage['problems'] ?? []));
    $warnings = array_values((array) ($storage['warnings'] ?? []));
    $adReady = ($ad['state'] ?? '') === 'ready';
    $fqdn = ($setupForm['hostname'] !== '' ? $setupForm['hostname'] : 'files').'.'.(($ad['realm'] ?? '') !== '' ? $ad['realm'] : '<Domäne>');
    $existingIps = Server::existingIps($defaults, (string) ($ad['dc']['ip'] ?? ''));
    $hostHelp = 'Bis 15 Zeichen. Die Freigaben heißen dann \\\\'.$fqdn.'\\…';
    $adStateName = (string) ($ad['state'] ?? '');
    $adTitle = $adStateName === 'missing' ? 'PK95 AD ist nicht installiert' : 'PK95 AD ist noch nicht bereit';
    $blocked = $problems !== [];
    $ok = 'text-green-700 dark:text-green-300';
    $bad = 'text-rose-700 dark:text-rose-300';
@endphp

<x-panel class="m-3 max-w-3xl" data-setup>
    <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">Dateiserver einrichten</div>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        PK95 File tritt der Domäne von PK95 AD als Dateiserver bei. PK95 Core legt dafür in PK95 AD das Computerkonto und den
        DNS-Eintrag an; ein Administrator-Kennwort ist nicht nötig. Der Server bekommt eine eigene IP-Adresse im LAN.
    </p>

    @if (! $adReady)
        {{-- the domain comes first --}}
        @php $adApp = $ad['app'] ?? null; @endphp
        <x-callout :title="$adTitle" ton="gelb" class="mt-4" :data-ad-required="$adStateName">
            @if ($adStateName === 'missing')
                Der Dateiserver gehört zu einer Active-Directory-Domäne. Aktiviere zuerst PK95 AD unter Apps und richte die Domäne ein.
            @elseif ($adStateName === 'stopped')
                PK95 AD läuft gerade nicht. Starte die App, dann geht es hier weiter.
            @elseif ($adStateName === 'unconfigured')
                Die Domäne in PK95 AD ist noch nicht eingerichtet. Richte sie zuerst ein, dann tritt der Dateiserver ihr bei.
            @else
                PK95 AD antwortet nicht: {{ $ad['error'] ?? '' }}
            @endif
            <x-slot:aktionen>
                @if ($adApp && $adStateName === 'unconfigured')
                    <x-input.linkbutton :href="route('apps.page', [$adApp, 'domain'])" wire:navigate size="sm">Domäne einrichten</x-input.linkbutton>
                @elseif ($adApp)
                    <x-input.linkbutton :href="route('apps.show', $adApp)" wire:navigate size="sm">Zu PK95 AD</x-input.linkbutton>
                @else
                    <x-input.linkbutton :href="route('apps.index')" wire:navigate size="sm">Zu den Apps</x-input.linkbutton>
                @endif
            </x-slot:aktionen>
        </x-callout>
    @elseif ($state === 'joining')
        <div class="mt-4 flex items-center gap-2 text-sm text-cerulean-700 dark:text-cerulean-300" data-setup-running>
            <span class="h-2 w-2 animate-pulse rounded-full bg-cerulean-500"></span>Der Dateiserver tritt der Domäne bei (etwa 10 bis 30 Sekunden) …
        </div>
    @else
        @if ($state === 'failed')
            <x-callout title="Der Beitritt ist fehlgeschlagen" ton="gelb" class="mt-4" data-setup-failed>
                {{ ($setup['error'] ?? '') !== '' ? $setup['error'] : 'Ohne Fehlermeldung.' }} Prüfe die Angaben und starte erneut; die halbe Einrichtung wird vorher entfernt, die Daten bleiben.
            </x-callout>
        @endif

        {{-- the data folder (Proxmox mount point) --}}
        <div class="mt-4 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700" data-storage-check>
            <div class="font-medium text-gray-900 dark:text-gray-100">Datenordner <span class="font-mono text-gray-600 dark:text-gray-300">{{ $storage['path'] ?? '/srv/files' }}</span></div>
            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-gray-700 dark:text-gray-300">
                <span>Dateisystem <span class="font-mono">{{ $storage['fs_type'] ?? '–' }}</span></span>
                <span>{{ Server::bytes($storage['free_bytes'] ?? null) }} frei von {{ Server::bytes($storage['total_bytes'] ?? null) }}</span>
                <span class="{{ ($storage['xattr'] ?? false) ? $ok : $bad }}">Attribute (ACLs) {{ ($storage['xattr'] ?? false) ? 'ja' : 'nein' }}</span>
                <span class="{{ ($storage['hardlinks'] ?? false) ? $ok : $bad }}">Hardlinks {{ ($storage['hardlinks'] ?? false) ? 'ja' : 'nein' }}</span>
                @if ($storage['on_system_disk'] ?? false)<span class="{{ $bad }}">auf der Systemplatte des LXC</span>@endif
            </div>
            @foreach ($problems as $p)
                <p class="mt-1 flex items-start gap-1.5 {{ $bad }}" data-storage-problem><x-icon name="x" class="mt-0.5 h-4 w-4 shrink-0" sw="2" />{{ $p }}</p>
            @endforeach
            @foreach ($warnings as $w)
                <p class="mt-1 flex items-start gap-1.5 text-amber-800 dark:text-amber-300" data-storage-warning><x-icon name="warn" class="mt-0.5 h-4 w-4 shrink-0" sw="2" />{{ $w }}</p>
            @endforeach
        </div>

        <form wire:submit="startSetup" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-field label="Servername" for="fs-host" feld="setupForm.hostname" :hilfe="$hostHelp">
                <x-input.text id="fs-host" feld="setupForm.hostname" wire:model.blur="setupForm.hostname" maxlength="15" class="mt-1 w-full font-mono" />
            </x-field>
            <x-field label="Adresse" for="fs-ipmode" feld="setupForm.ip_mode">
                <x-input.select id="fs-ipmode" feld="setupForm.ip_mode" wire:model.live="setupForm.ip_mode" class="mt-1">
                    @foreach (Server::IP_MODES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                </x-input.select>
            </x-field>
            <x-field label="IP-Adresse des Dateiservers" for="fs-ip" feld="setupForm.ip" class="sm:col-span-2">
                <div class="mt-1 flex flex-wrap gap-2">
                    @if ($setupForm['ip_mode'] === 'existing')
                        <x-input.select id="fs-ip" feld="setupForm.ip" wire:model.live="setupForm.ip" class="w-full font-mono sm:w-60">
                            @forelse ($existingIps as $ip)<option value="{{ $ip }}">{{ $ip }}</option>@empty<option value="">Keine weitere Adresse im LXC</option>@endforelse
                        </x-input.select>
                    @else
                        <x-input.text id="fs-ip" feld="setupForm.ip" wire:model.blur="setupForm.ip" placeholder="192.168.1.20" class="w-full font-mono sm:w-60" />
                    @endif
                    <x-input.button type="button" size="feld" color="gray" wire:click="checkIp" wire:loading.attr="disabled" wire:target="checkIp" data-ip-check>IP prüfen</x-input.button>
                </div>
                <x-slot:unten>
                    @if ($ipCheck)
                        <p @class(['mt-1 flex items-start gap-1.5 text-sm', $ok => $ipCheck['ok'], $bad => ! $ipCheck['ok']]) data-ip-result="{{ $ipCheck['ok'] ? 'ok' : 'taken' }}">
                            <x-icon :name="$ipCheck['ok'] ? 'check' : 'x'" class="mt-0.5 h-4 w-4 shrink-0" sw="2" />{{ $ipCheck['message'] !== '' ? $ipCheck['message'] : ($ipCheck['ok'] ? 'Die Adresse '.$ipCheck['ip'].' ist frei.' : 'Die Adresse '.$ipCheck['ip'].' ist nicht frei.') }}
                        </p>
                    @endif
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-ip-source>
                        @if ($setupForm['ip_mode'] === 'existing')
                            Eine Adresse, die in Proxmox schon am LXC eingerichtet ist (etwa auf net1).
                        @elseif (($defaults['ip_source'] ?? '') === 'env')
                            Vorschlag aus der Einstellung PK95FILE_DEFAULT_IP von PK95 Core.
                        @elseif (($defaults['ip_source'] ?? '') === 'probe')
                            Vorschlag: die erste freie Adresse nach der des LXC. Sie muss außerhalb des DHCP-Bereichs des Routers liegen.
                        @else
                            Keine freie Adresse gefunden. Trage eine Adresse außerhalb des DHCP-Bereichs ein.
                        @endif
                        @if (($defaults['subnet'] ?? '') !== '') Netz {{ $defaults['subnet'] }}{{ ($defaults['interface'] ?? '') !== '' ? ' auf '.$defaults['interface'] : '' }}. @endif
                    </p>
                </x-slot:unten>
            </x-field>
            @if ($storage['on_system_disk'] ?? false)
                <div class="sm:col-span-2">
                    <x-input.checkbox wire:model="setupForm.allow_system_disk" label="Trotzdem auf der Systemplatte des LXC ablegen"
                        hilfe="Nur zum Ausprobieren: Die Systemplatte ist klein, und die Daten liegen dann in der Sicherung des LXC." />
                    <x-input.fehler feld="setupForm.allow_system_disk" />
                </div>
            @endif
            <x-input.fehler feld="setupForm.odj" class="sm:col-span-2" />
            <div class="flex justify-end sm:col-span-2">
                <x-input.button type="submit" :disabled="$blocked" wire:loading.attr="disabled" wire:target="startSetup" data-start-setup>{{ $state === 'failed' ? 'Erneut einrichten' : 'Dateiserver einrichten' }}</x-input.button>
            </div>
        </form>
    @endif

    @if ($setup['log'] ?? [])
        <details class="mt-4" @if ($state === 'joining') open @endif>
            <summary class="cursor-pointer text-sm text-cerulean-700 dark:text-cerulean-300">Protokoll der Einrichtung</summary>
            <pre class="mt-2 max-h-72 overflow-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-100" data-setup-log>{{ implode("\n", (array) $setup['log']) }}</pre>
        </details>
    @endif
</x-panel>

@if ($adReady && $state !== 'joining')
    @include('modules.file.partials.join-problems')
@endif
