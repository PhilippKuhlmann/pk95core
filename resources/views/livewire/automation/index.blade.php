@php
    use App\Models\AppLink;
    $tone = fn ($s) => match ($s) {
        AppLink::ACTIVE => 'bg-green-500', AppLink::PENDING_DNS => 'bg-amber-500', AppLink::FAILED => 'bg-rose-500', default => 'bg-gray-300 dark:bg-gray-600',
    };
@endphp

<div wire:poll.30s @if ($lanIp === '' && $detected === null) wire:init="loadLanIp" @endif>
    <x-page-header title="Automatik">
        <x-slot:sub>Rollst du mit ProxApps eine App aus, richtet Core sie im Proxy ein, trägt die Domain in PK95 DNS ein und überwacht sie mit Uptime.</x-slot:sub>
    </x-page-header>

    @if (trim((string) \App\Support\Settings::get('automation_base_domain')) === '')
        <x-callout title="Keine Basisdomain festgelegt" ton="gelb" class="m-3 max-w-3xl">
            Ohne Basisdomain bekommen neue Apps nur dann einen Proxy-Host, wenn du im Deploy-Formular eine ganze Domain einträgst. Monitore legt Core trotzdem an.
        </x-callout>
    @endif

    <x-panel class="m-3 max-w-3xl">
        <form wire:submit="save" class="flex flex-col gap-4">
            <x-field label="Basisdomain" for="a-base" feld="baseDomain"
                hilfe="Neue Apps bekommen <Subdomain>.<Basisdomain>, z. B. cloud.example.de. Die Subdomain schlägt der Katalog vor, im Deploy-Formular kannst du sie ändern oder eine ganze Domain eintragen.">
                <x-input.text id="a-base" feld="baseDomain" wire:model="baseDomain" placeholder="example.de" class="mt-1 w-full max-w-sm font-mono" />
            </x-field>
            <div class="flex flex-col gap-1">
                <x-input.checkbox label="Proxy-Host in PK95 Proxy anlegen" wire:model="proxy"
                    hilfe="Öffentlich, mit Angriffsfilter. Aktiv, sobald die Domain per DNS auf deine öffentliche IP zeigt, vorher deaktiviert (schützt vor dem Let's-Encrypt-Limit). Core prüft alle 10 Minuten nach." />
                <x-input.checkbox label="Lokalen DNS-Eintrag in PK95 DNS anlegen" wire:model="dns"
                    hilfe="Dieselbe Domain wie im Proxy, Antwort ist die LAN-IP des Proxy. Geräte im Netz erreichen die App so direkt statt über den Router. Einträge, die es schon gibt, lässt Core unverändert." />
                <x-input.checkbox label="Monitore in PK95 Uptime anlegen" wire:model="uptime"
                    hilfe="Einer prüft die App direkt (IP und Port), einer die Domain über den Proxy samt Zertifikat." />
            </div>
            <x-field label="Öffentliche IP" for="a-ip" feld="publicIp"
                :hilfe="'Leer lassen: Core ermittelt sie selbst'.($detectedIp ? ' (gerade '.$detectedIp.')' : '').'. Nötig nur bei mehreren Anschlüssen oder wenn die Ermittlung scheitert.'">
                <x-input.text id="a-ip" feld="publicIp" wire:model="publicIp" placeholder="203.0.113.10" class="mt-1 w-full max-w-xs font-mono" />
            </x-field>
            @php
                $lanHelp = match (true) {
                    $detected === null => 'Leer lassen: Core ermittelt sie selbst, das läuft gerade.',
                    ($detected['ip'] ?? null) !== null => 'Leer lassen: Core nimmt die erkannte Adresse '.$detected['ip'].' (Route des LXC ins Internet, geprüft '
                        .\App\Http\Support\Format::dt($detected['at']).'). Ein Eintrag hier hat immer Vorrang.',
                    default => 'Nicht erkannt: '.($detected['error'] ?? 'unbekannter Fehler').' Trag die Adresse von Hand ein.',
                };
            @endphp
            <x-field label="LAN-IP des Proxy" for="a-lan" feld="lanIp" :hilfe="$lanHelp">
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <x-input.text id="a-lan" feld="lanIp" wire:model="lanIp" :placeholder="$detected['ip'] ?? '192.168.1.20'" class="w-full max-w-xs font-mono" />
                    <x-input.button type="button" size="sm" color="gray" wire:click="detectLanIp" wire:loading.attr="disabled" wire:target="detectLanIp">Erneut erkennen</x-input.button>
                </div>
            </x-field>
            <div><x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button></div>
        </form>
    </x-panel>

    <x-panel class="m-3 overflow-x-auto" polster="keins">
        <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
            <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Automatisch eingerichtete Apps</div>
            @if ($links->isNotEmpty())
                <x-input.button type="button" size="sm" color="gray" wire:click="syncDns" wire:loading.attr="disabled" wire:target="syncDns"
                    title="Fehlende lokale DNS-Einträge der Apps anlegen und auf die aktuelle LAN-IP setzen">DNS abgleichen</x-input.button>
            @endif
        </div>
        @if ($links->isEmpty())
            <p class="px-4 pb-4 pt-2 text-sm text-gray-500 dark:text-gray-400">Noch keine. Rolle eine App mit ProxApps aus, dann erscheint sie hier.</p>
        @else
            <table class="mt-2 w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr><th class="px-4 py-2">App</th><th class="px-4 py-2">Domain</th><th class="px-4 py-2">Ziel</th><th class="px-4 py-2">Status</th><th class="px-4 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($links as $l)
                        <tr wire:key="link-{{ $l->id }}" @class(['opacity-60' => $l->state === AppLink::REMOVED])>
                            <td class="px-4 py-3 align-top"><div class="font-medium text-gray-900 dark:text-gray-100">{{ $l->name }}</div><div class="text-xs text-gray-500">{{ $l->hostname }}</div></td>
                            <td class="px-4 py-3 align-top font-mono text-xs">
                                @if ($l->domain && $l->state === AppLink::ACTIVE && $l->proxy_host_id)
                                    <a href="https://{{ $l->domain }}" target="_blank" rel="noopener" class="text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $l->domain }}</a>
                                @else
                                    {{ $l->domain ?: '–' }}
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top font-mono text-xs">{{ $l->target }}</td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ $tone($l->state) }}"></span>{{ AppLink::STATES[$l->state] ?? $l->state }}</div>
                                <div class="text-xs text-gray-500">
                                    {{ $l->proxy_host_id ? 'Proxy-Host' : 'kein Proxy-Host' }}
                                    · {{ $l->dns_record_id ? 'DNS → '.$l->dns_answer : 'kein DNS-Eintrag' }}
                                    · {{ count($l->monitor_ids ?? []) }} {{ count($l->monitor_ids ?? []) === 1 ? 'Monitor' : 'Monitore' }}
                                </div>
                                @if ($l->message)<div class="mt-1 max-w-md text-xs text-gray-500 dark:text-gray-400">{{ $l->message }}</div>@endif
                                @if ($l->dns_message && $l->state !== AppLink::REMOVED)<div class="mt-1 max-w-md text-xs text-amber-700 dark:text-amber-300">{{ $l->dns_message }}</div>@endif
                            </td>
                            <td class="px-4 py-3 text-right align-top">
                                @if ($l->state === AppLink::PENDING_DNS)
                                    <x-input.button type="button" size="sm" color="gray" wire:click="recheck({{ $l->id }})" wire:loading.attr="disabled">DNS jetzt prüfen</x-input.button>
                                @elseif ($l->state === AppLink::REMOVED)
                                    <x-input.button type="button" size="sm" color="gray" wire:click="forget({{ $l->id }})">Ausblenden</x-input.button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-panel>
</div>
