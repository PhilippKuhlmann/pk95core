@php
    use App\Modules\Ad\Domain;
    $card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
    $label = 'text-xs text-gray-500 dark:text-gray-400';
    $value = 'text-sm text-gray-900 dark:text-gray-100';
@endphp

{{-- poll while the domain is being set up (progress and log), otherwise every 30 s --}}
<div wire:poll.{{ $state === 'provisioning' ? '2s' : '30s' }}>
    <x-page-header title="Domäne">
        <x-slot:sub>
            @if ($domain) {{ $domain['realm'] }} · {{ $domain['dc']['fqdn'] ?? '' }} @elseif ($state === 'provisioning') Wird eingerichtet … @else Noch nicht eingerichtet @endif
        </x-slot:sub>
    </x-page-header>

    @if ($loadError)
        <x-callout title="PK95 AD antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @elseif ($state !== 'ready')
        {{-- the setup wizard --}}
        <x-panel class="m-3 max-w-3xl" data-setup>
            <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">Domäne einrichten</div>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                PK95 AD wird zum ersten Domänencontroller einer neuen Active-Directory-Domäne. Windows-Rechner treten ihr später bei.
                Die AD-Zone löst PK95 DNS über eine Weiterleitung auf, die PK95 Core danach selbst einträgt.
            </p>

            @if ($state === 'provisioning')
                <div class="mt-4 flex items-center gap-2 text-sm text-cerulean-700 dark:text-cerulean-300" data-setup-running>
                    <span class="h-2 w-2 animate-pulse rounded-full bg-cerulean-500"></span>Die Domäne wird eingerichtet (etwa 30 bis 90 Sekunden) …
                </div>
            @elseif ($state === 'failed')
                <x-callout title="Die Einrichtung ist fehlgeschlagen" ton="gelb" class="mt-4" data-setup-failed>
                    {{ $setup['error'] ?: 'Ohne Fehlermeldung.' }} Prüfe die Angaben und starte die Einrichtung erneut, die halbe Einrichtung wird vorher entfernt.
                </x-callout>
            @endif

            @if ($state !== 'provisioning')
                <form wire:submit="startSetup" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-field label="Domäne (Realm)" for="ad-realm" feld="setupForm.realm" hilfe="Eine Subdomain einer eigenen Domain, z. B. ad.example.com. Nicht .local.">
                        <x-input.text id="ad-realm" feld="setupForm.realm" wire:model.blur="setupForm.realm" placeholder="ad.example.com" class="mt-1 w-full font-mono" />
                    </x-field>
                    <x-field label="NetBIOS-Name" for="ad-netbios" feld="setupForm.netbios_domain" hilfe="Kurzname der Domäne, bis 15 Zeichen, anders als der DC-Name.">
                        <x-input.text id="ad-netbios" feld="setupForm.netbios_domain" wire:model="setupForm.netbios_domain" placeholder="EXAMPLE" class="mt-1 w-full font-mono uppercase" />
                    </x-field>
                    <x-field label="Name des Domänencontrollers" for="ad-host" feld="setupForm.hostname" hilfe="Der DC heißt dann <Name>.<Domäne>.">
                        <x-input.text id="ad-host" feld="setupForm.hostname" wire:model="setupForm.hostname" class="mt-1 w-full font-mono" />
                    </x-field>
                    <x-field label="IP-Adresse des DC" for="ad-ip" feld="setupForm.host_ip">
                        <x-input.select id="ad-ip" feld="setupForm.host_ip" wire:model="setupForm.host_ip" class="mt-1">
                            @foreach ((array) ($setup['defaults']['host_ips'] ?? []) as $ip)
                                <option value="{{ $ip }}">{{ $ip }}</option>
                            @endforeach
                        </x-input.select>
                    </x-field>
                    <x-field label="DNS-Weiterleitung für alles außerhalb der AD" for="ad-fwd" feld="setupForm.dns_forwarder" hilfe="127.0.0.1 = PK95 DNS auf diesem LXC.">
                        <x-input.text id="ad-fwd" feld="setupForm.dns_forwarder" wire:model="setupForm.dns_forwarder" class="mt-1 w-full font-mono" />
                    </x-field>
                    <x-field label="Kennwort für Administrator" for="ad-pw" feld="setupForm.admin_password" hilfe="8 bis 127 Zeichen, drei von: Groß, klein, Ziffer, Sonderzeichen. PK95 AD speichert es nicht.">
                        <x-input.text id="ad-pw" type="password" autocomplete="new-password" feld="setupForm.admin_password" wire:model="setupForm.admin_password" class="mt-1 w-full" />
                    </x-field>
                    <div class="sm:col-span-2 flex justify-end">
                        <x-input.button type="submit" wire:loading.attr="disabled" wire:target="startSetup">{{ $state === 'failed' ? 'Erneut einrichten' : 'Domäne einrichten' }}</x-input.button>
                    </div>
                </form>
            @endif

            @if ($setup['log'] ?? [])
                <details class="mt-4" @if ($state === 'provisioning') open @endif>
                    <summary class="cursor-pointer text-sm text-cerulean-700 dark:text-cerulean-300">Protokoll der Einrichtung</summary>
                    <pre class="mt-2 max-h-72 overflow-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-100" data-setup-log>{{ implode("\n", (array) $setup['log']) }}</pre>
                </details>
            @endif
        </x-panel>
    @elseif ($domain)
        <div class="m-3 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div class="{{ $card }}"><div class="{{ $label }}">Domäne</div><div class="{{ $value }} font-mono">{{ $domain['realm'] }}</div><div class="{{ $label }} mt-1">Kerberos {{ $domain['kerberos_realm'] }} · NetBIOS {{ $domain['netbios_domain'] }}</div></div>
            <div class="{{ $card }}"><div class="{{ $label }}">Domänencontroller</div><div class="{{ $value }} font-mono">{{ $domain['dc']['fqdn'] ?? '' }}</div><div class="{{ $label }} mt-1">{{ $domain['dc']['ip'] ?? '' }} · {{ $domain['samba_version'] ?? '' }}</div></div>
            <div class="{{ $card }}"><div class="{{ $label }}">Funktionsebene</div><div class="{{ $value }}">Domäne {{ $domain['domain_level'] ?? '–' }} · Gesamtstruktur {{ $domain['forest_level'] ?? '–' }}</div></div>
            <div class="{{ $card }}"><div class="{{ $label }}">Objekte</div>
                <div class="{{ $value }}">{{ $domain['counts']['users'] ?? 0 }} Benutzer · {{ $domain['counts']['groups'] ?? 0 }} Gruppen · {{ $domain['counts']['computers'] ?? 0 }} Computer</div>
                <div class="{{ $label }} mt-1">{{ $domain['counts']['ous'] ?? 0 }} OUs · {{ $domain['counts']['gpos'] ?? 0 }} Gruppenrichtlinien</div></div>
        </div>

        {{-- signed NTP for the members: never blocks Samba, but without it Windows clients drift and Kerberos fails --}}
        @php $ntp = (array) ($domain['ntp'] ?? $status['ntp'] ?? []); @endphp
        @if (($ntp['available'] ?? true) === false)
            <x-callout title="Zeitdienst (NTP) nicht verfügbar" ton="gelb" class="m-3" data-ntp-warning>
                {{ ($ntp['message'] ?? '') !== '' ? $ntp['message'] : 'PK95 AD kann die Zeit nicht an die Domänenmitglieder verteilen.' }}
                Windows-Rechner der Domäne holen ihre Zeit vom Domänencontroller; ohne NTP laufen ihre Uhren auseinander und die Anmeldung (Kerberos) scheitert ab fünf Minuten Abweichung.
            </x-callout>
        @endif

        {{-- Unix IDs (PK95 AD 1.0.1+): the unprivileged LXC only has 0–65535; missing field (1.0.0) = no hint --}}
        @php
            $idmap = is_array($domain['idmap'] ?? null) ? $domain['idmap'] : null;
            $xidRange = (array) ($idmap['xid_range'] ?? []);
        @endphp
        @if ($idmap && ($idmap['ok'] ?? true) === false)
            <x-callout title="Unix-IDs der Domäne" ton="gelb" class="m-3" data-idmap-warning>
                {{ ($idmap['message'] ?? '') !== '' ? $idmap['message'] : 'Samba vergibt Unix-IDs außerhalb des Bereichs, den der LXC kennt.' }}
                @if (count($xidRange) === 2)
                    <span class="mt-1 block text-xs">Bereich {{ number_format((int) $xidRange[0], 0, ',', '.') }} bis {{ number_format((int) $xidRange[1], 0, ',', '.') }}, vergeben {{ number_format((int) ($idmap['allocated'] ?? 0), 0, ',', '.') }}, nächste ID {{ number_format((int) ($idmap['next_xid'] ?? 0), 0, ',', '.') }}.</span>
                @endif
            </x-callout>
        @endif

        @if ($status)
            <x-panel class="m-3" data-status>
                <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Dienste</div>
                <div class="mt-2 flex flex-wrap gap-x-5 gap-y-2 text-sm">
                    <span>Samba: <span @class(['font-medium', 'text-green-700 dark:text-green-300' => ($status['samba']['state'] ?? '') === 'running', 'text-rose-700 dark:text-rose-300' => ($status['samba']['state'] ?? '') !== 'running'])>{{ ['running' => 'läuft', 'starting' => 'startet', 'restarting' => 'startet neu', 'backup' => 'angehalten (Sicherung)', 'stopped' => 'gestoppt'][$status['samba']['state'] ?? ''] ?? ($status['samba']['state'] ?? '–') }}</span></span>
                    @foreach (['ldap' => 'LDAP', 'kerberos' => 'Kerberos', 'smb' => 'SMB', 'dns' => 'DNS'] as $key => $name)
                        <span class="inline-flex items-center gap-1.5"><span @class(['h-2 w-2 rounded-full', 'bg-green-500' => $status['samba']['services'][$key] ?? false, 'bg-rose-500' => ! ($status['samba']['services'][$key] ?? false)])></span>{{ $name }}</span>
                    @endforeach
                    @php
                        $ntpRunning = (bool) ($ntp['running'] ?? false);
                        $ntpOff = ($ntp['available'] ?? true) === false;
                    @endphp
                    <span class="inline-flex items-center gap-1.5" @if ($ntpOff) data-tip="{{ $ntp['message'] ?? '' }}" @endif><span @class(['h-2 w-2 rounded-full', 'bg-green-500' => $ntpRunning, 'bg-amber-500' => ! $ntpRunning && $ntpOff, 'bg-rose-500' => ! $ntpRunning && ! $ntpOff])></span>Zeit (NTP){{ $ntpOff ? ': nicht verfügbar' : '' }}</span>
                </div>
                @if (($status['samba']['last_exit'] ?? '') !== '')
                    <p class="mt-2 text-xs text-rose-700 dark:text-rose-300">Zuletzt beendet: {{ $status['samba']['last_exit'] }}</p>
                @endif
            </x-panel>
        @endif

        {{-- PK95 DNS forwards the realm to Samba's DNS --}}
        <x-panel class="m-3" data-dns-forward>
            <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Weiterleitung in PK95 DNS</div>
            @php $rule = $domain['realm'].' → '.($forward['upstream'] ?? ''); @endphp
            @switch ($forward['state'] ?? 'no_dns')
                @case('present')
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Eingetragen: <span class="font-mono">{{ $rule }}</span>. Rechner, die PK95 DNS nutzen, finden die Domäne.</p>
                    @break
                @case('other')
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">PK95 DNS hat für {{ $domain['realm'] }} schon eine Weiterleitung an <span class="font-mono">{{ $forward['current'] }}</span>. Sie bleibt unverändert; Samba antwortet auf <span class="font-mono">{{ $forward['upstream'] }}</span>.</p>
                    @break
                @case('missing')
                    <div class="mt-1 flex flex-wrap items-center justify-between gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <span>In PK95 DNS fehlt die Weiterleitung <span class="font-mono">{{ $rule }}</span>.</span>
                        <x-input.button type="button" size="sm" wire:click="addDnsForward">In PK95 DNS eintragen</x-input.button>
                    </div>
                    @break
                @case('error')
                    <p class="mt-1 text-sm text-rose-700 dark:text-rose-300">{{ $forward['error'] }}</p>
                    @break
                @default
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">PK95 DNS ist nicht installiert. Trage im DNS-Server deines Netzes eine bedingte Weiterleitung ein: <span class="font-mono">{{ $domain['realm'] }}</span> an <span class="font-mono">{{ $domain['dc']['ip'] ?? '' }}:{{ $domain['dns_port'] ?? 5300 }}</span>.</p>
            @endswitch
        </x-panel>

        <div class="m-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
            <x-panel>
                <form wire:submit="savePolicy">
                    <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Kennwortrichtlinie</div>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach (Domain::POLICY as $key => [$name, $hint])
                            <x-field :label="$name" for="pol-{{ $key }}" feld="policy.{{ $key }}" :hilfe="$hint">
                                <x-input.text id="pol-{{ $key }}" type="number" min="0" feld="policy.{{ $key }}" wire:model="policy.{{ $key }}" class="mt-1 w-full" />
                            </x-field>
                        @endforeach
                    </div>
                    <x-input.checkbox label="Komplexe Kennwörter verlangen" wire:model="policy.complexity" />
                    <div class="mt-3 flex justify-end"><x-input.button type="submit" wire:loading.attr="disabled" wire:target="savePolicy">Speichern</x-input.button></div>
                </form>
            </x-panel>
            <div class="space-y-3">
                <x-panel>
                    <form wire:submit="saveForwarder" class="flex flex-wrap items-end gap-2">
                        <x-field label="DNS-Weiterleitung der AD (Namen außerhalb der Domäne)" for="ad-forwarder" feld="dns_forwarder" class="min-w-0 flex-1">
                            <x-input.text id="ad-forwarder" feld="dns_forwarder" wire:model="forwarder" class="mt-1 w-full font-mono" />
                        </x-field>
                        <x-input.button type="submit" wire:loading.attr="disabled" wire:target="saveForwarder" class="mb-6">Speichern</x-input.button>
                    </form>
                </x-panel>
                <x-panel data-dbcheck>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Datenbankprüfung</div>
                        <div class="flex gap-2">
                            <x-input.button type="button" size="sm" color="gray" wire:click="runDbcheck(false)" :disabled="$dbcheck['running'] ?? false">Prüfen</x-input.button>
                            <x-input.button type="button" size="sm" color="gray" wire:click="runDbcheck(true)" :disabled="$dbcheck['running'] ?? false">Prüfen und reparieren</x-input.button>
                        </div>
                    </div>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        @if ($dbcheck['running'] ?? false) Läuft …
                        @elseif (($dbcheck['ok'] ?? null) === null) Noch nicht gelaufen. Täglich um 03:30 prüft PK95 AD selbst.
                        @else {{ $dbcheck['ok'] ? 'Ohne Fehler' : ($dbcheck['errors'].' Fehler') }}, {{ Domain::when($dbcheck['finished_at'] ?? 0) }}{{ ($dbcheck['fix'] ?? false) ? ' (mit Reparatur)' : '' }}
                        @endif
                    </p>
                    @if (($dbcheck['output'] ?? '') !== '')
                        <details class="mt-2"><summary class="cursor-pointer text-sm text-cerulean-700 dark:text-cerulean-300">Ausgabe</summary>
                            <pre class="mt-2 max-h-64 overflow-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-100">{{ $dbcheck['output'] }}</pre></details>
                    @endif
                </x-panel>
                <x-panel>
                    <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">FSMO-Rollen</div>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Alle bei {{ implode(', ', array_unique(array_values((array) ($domain['fsmo'] ?? [])))) ?: '–' }}.</p>
                </x-panel>
            </div>
        </div>
    @endif
</div>
