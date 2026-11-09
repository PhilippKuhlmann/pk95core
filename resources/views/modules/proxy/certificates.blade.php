@php
    use App\Modules\Proxy\Certificates;
    use App\Modules\Proxy\Fmt;
    use App\Modules\Proxy\Hosts;
    $nowMs = (int) (microtime(true) * 1000);
    $section = 'font-DINPro-bold text-gray-900 dark:text-gray-100';
    $intro = 'mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400';
@endphp

<div wire:poll.30s>
    <x-page-header title="Zertifikate" />

    @if ($loadError)
        <x-callout title="PK95 Proxy antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    <x-panel class="m-3 overflow-x-auto">
        <div class="{{ $section }}">Zertifikate deiner Hosts</div>
        <p class="{{ $intro }}">Caddy holt und erneuert die Zertifikate automatisch, sobald eine Domain auf diesen Server zeigt und Port 80 erreichbar ist. Die Tabelle zeigt, was Besucher tatsächlich bekommen.</p>
        @if (! $hosts)
            <p class="mt-3 text-sm text-gray-500">Es gibt noch keine Hosts.</p>
        @else
            <table class="mt-3 w-full text-left text-sm">
                <thead class="text-xs text-gray-500"><tr><th class="py-1 pr-3">Domain</th><th class="py-1 pr-3">Quelle</th><th class="py-1 pr-3">Aussteller</th><th class="py-1">Gültig bis</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($hosts as $x)
                        <tr @class(['opacity-60' => ! $x['enabled']])>
                            <td class="py-2 pr-3">{{ $x['domain'] }}
                                @if ($x['kind'] !== 'proxy')<span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ Hosts::KINDS[$x['kind']] ?? $x['kind'] }}</span>@endif
                            </td>
                            <td class="py-2 pr-3">
                                @if (! $x['enabled'])
                                    <span class="text-gray-500 dark:text-gray-400">Host deaktiviert</span>
                                @elseif ($x['status'] !== 'ok')
                                    <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot('warn') }}"></span>Wird noch geholt</span>
                                @else
                                    {{ match ($x['source'] ?? 'auto') { 'custom' => 'Eigenes: '.($x['cert_name'] ?? ''), 'local' => 'Lokale CA (nur intern gültig)', default => 'Automatisch' } }}
                                @endif
                            </td>
                            <td class="py-2 pr-3">{{ ($x['issuer'] ?? '') ?: '–' }}</td>
                            <td class="whitespace-nowrap py-2">
                                @if (! empty($x['not_after']))
                                    @php [$tone, $text] = Certificates::validity($x['not_after']); @endphp
                                    <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot($tone) }}"></span>{{ $text }}</span>
                                @else
                                    –
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-panel>

    <x-panel class="m-3 overflow-x-auto">
        <div class="{{ $section }}">Let's Encrypt für andere Anwendungen</div>
        <p class="{{ $intro }}">Caddy holt das Zertifikat und erneuert es automatisch. Du lädst Zertifikat und Schlüssel herunter und bindest sie in deine Anwendung ein. Die Domain muss per DNS auf diesen Server zeigen und Port 80 aus dem Internet erreichbar sein. Pro Eintrag gibt es eine Domain, Wildcards sind damit nicht möglich. Nach jeder Erneuerung musst du die neuen Dateien selbst austauschen, PK95 Core benachrichtigt dich dann.</p>
        @if ($acme)
            <table class="mt-3 w-full text-left text-sm">
                <thead class="text-xs text-gray-500"><tr><th class="py-1 pr-3">Domain</th><th class="py-1 pr-3">Aussteller</th><th class="py-1 pr-3">Gültig bis</th><th class="py-1"></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($acme as $c)
                        @php $st = $c['status'] ?? null; @endphp
                        <tr wire:key="acme-{{ $c['id'] }}">
                            <td class="py-2 pr-3 align-top">{{ $c['domain'] }}
                                @if ($c['note'])<div class="text-xs text-gray-500">{{ $c['note'] }}</div>@endif
                                @if ($st)
                                    <div class="mt-1 flex flex-col font-mono text-xs text-gray-500"><span>{{ $c['path'] }}/fullchain.pem</span><span>{{ $c['path'] }}/privkey.pem</span></div>
                                @endif
                            </td>
                            <td class="py-2 pr-3 align-top">{{ $st['issuer'] ?? '–' }}</td>
                            <td class="whitespace-nowrap py-2 pr-3 align-top">
                                @if ($st)
                                    @php [$tone, $text] = Certificates::validity($st['not_after']); @endphp
                                    <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot($tone) }}"></span>{{ $text }}</span>
                                @else
                                    @php $waiting = $nowMs - $c['created_at'] > 600000; @endphp
                                    <span class="flex items-center gap-2" title="Caddy versucht es automatisch weiter.">
                                        <span @class(['h-2 w-2 rounded-full', Fmt::dot($waiting ? 'bad' : 'warn'), 'animate-pulse' => ! $waiting])></span>
                                        {{ $waiting ? 'Noch nicht ausgestellt: DNS und Port 80 prüfen' : 'Wird geholt …' }}
                                    </span>
                                @endif
                            </td>
                            <td class="py-2 align-top">
                                <div class="flex justify-end gap-1">
                                    @if ($st)
                                        <x-input.button type="button" size="sm" color="gray" wire:click="download({{ $c['id'] }}, 'fullchain.pem')">Zertifikat</x-input.button>
                                        <x-input.button type="button" size="sm" color="gray"
                                            x-on:click="$store.confirm.ask({ title: 'Privaten Schlüssel herunterladen?', tone: 'blue', button: 'Herunterladen',
                                                text: @js('Wer den Schlüssel hat, kann sich als '.$c['domain'].' ausgeben. Speichere ihn nur dort, wo er gebraucht wird.'.($secure ? '' : ' PK95 Core läuft über HTTP: Der Schlüssel geht unverschlüsselt durch dein Netz. Lade ihn nur aus einem Netz herunter, dem du vertraust, nicht über öffentliches WLAN.')),
                                                ok: () => $wire.download({{ $c['id'] }}, 'privkey.pem') })">Schlüssel</x-input.button>
                                    @endif
                                    <x-input.button type="button" size="sm" color="gray" aria-label="{{ $c['domain'] }} löschen"
                                        x-on:click="$store.confirm.ask({ title: 'Eintrag löschen?', button: 'Löschen', text: @js('Die Dateien für '.$c['domain'].' verschwinden hier, und Caddy erneuert das Zertifikat nicht mehr, außer ein Host nutzt dieselbe Domain. Anwendungen, die es schon nutzen, laufen damit bis zum Ablauf weiter.'), ok: () => $wire.deleteAcme({{ $c['id'] }}) })">
                                        <x-icon name="trash" class="h-4 w-4" />
                                    </x-input.button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if (collect($acme)->contains(fn ($c) => ! empty($c['status'])))
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Die Pfade gelten im Container von PK95 Proxy (Volume pk95-proxy-data). Bei einer Erneuerung bleibt der Pfad gleich, nur der Inhalt ändert sich.</p>
            @endif
        @endif

        <form class="mt-4 grid grid-cols-1 items-end gap-3 md:grid-cols-[1fr_1fr_auto]"
            x-on:submit.prevent="$store.confirm.ask({ title: 'Zertifikat holen?', tone: 'blue', button: 'Zertifikat holen',
                text: 'Let\'s Encrypt stellt das Zertifikat für ' + $wire.acmeDomain.trim() + ' sofort aus. Bitte prüfe die Schreibweise: Der Name steht danach dauerhaft in den öffentlichen Zertifikatslogs, auch wenn du den Eintrag später löschst.',
                ok: () => $wire.createAcme() })">
            <x-field label="Domain" for="acme-domain">
                <x-input.text id="acme-domain" wire:model="acmeDomain" required placeholder="mail.example.de" autocomplete="off" class="mt-1 w-full font-mono" />
            </x-field>
            <x-field label="Notiz" for="acme-note">
                <x-input.text id="acme-note" maxlength="200" wire:model="acmeNote" placeholder="Wofür, zum Beispiel Mailserver" class="mt-1 w-full" />
            </x-field>
            <x-input.button type="submit" size="feld">Zertifikat anlegen</x-input.button>
        </form>
    </x-panel>

    <x-panel class="m-3 overflow-x-auto">
        <div class="{{ $section }}">Eigene Zertifikate</div>
        <p class="{{ $intro }}">Ein eigenes Zertifikat, auch ein Wildcard, gilt automatisch für alle passenden Domains. Eigene Zertifikate erneuert niemand für dich, das Panel warnt 30, 14, 7 Tage und 1 Tag vorher. Läuft eines ab, übernimmt für öffentliche Domains wieder das automatische Zertifikat. Der Schlüssel lässt sich nach dem Hochladen nicht mehr abrufen.</p>
        @if ($custom)
            <table class="mt-3 w-full text-left text-sm">
                <thead class="text-xs text-gray-500"><tr><th class="py-1 pr-3">Name</th><th class="py-1 pr-3">Domains</th><th class="py-1 pr-3">Aussteller</th><th class="py-1 pr-3">Gültig bis</th><th class="py-1"></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($custom as $c)
                        @php [$tone, $text] = Certificates::validity($c['not_after'], 30); @endphp
                        <tr wire:key="cert-{{ $c['id'] }}">
                            <td class="py-2 pr-3">{{ $c['name'] }}</td>
                            <td class="py-2 pr-3 text-xs">{{ implode(', ', $c['domains']) }}</td>
                            <td class="py-2 pr-3">{{ $c['issuer'] }}
                                @unless ($c['trusted'])<span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900 dark:bg-amber-900 dark:text-amber-100" title="Browser zeigen dafür eine Warnung, zum Beispiel bei selbstsignierten Zertifikaten.">nicht öffentlich vertraut</span>@endunless
                            </td>
                            <td class="whitespace-nowrap py-2 pr-3"><span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot($tone) }}"></span>{{ $text }}</span></td>
                            <td class="py-2 text-right">
                                <x-input.button type="button" size="sm" color="gray" aria-label="{{ $c['name'] }} löschen"
                                    x-on:click="$store.confirm.ask({ title: 'Zertifikat löschen?', button: 'Löschen', text: @js($c['name'].' wird entfernt. Für öffentliche Domains holt Caddy dann automatisch ein Zertifikat, das dauert einen Moment.'), ok: () => $wire.deleteCert({{ $c['id'] }}) })">
                                    <x-icon name="trash" class="h-4 w-4" />
                                </x-input.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <form wire:submit="upload" class="mt-4" x-data="{ load(e, prop) { const f = e.target.files[0]; if (f) f.text().then((t) => $wire.set(prop, t)); } }">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-field label="Name" for="cert-name">
                    <x-input.text id="cert-name" maxlength="80" wire:model="certName" placeholder="Wird sonst aus der Domain gebildet" class="mt-1 w-full" />
                </x-field>
            </div>
            <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-field label="Zertifikat mit Zwischenzertifikaten (PEM)" for="cert-pem">
                    <x-input.textarea id="cert-pem" rows="5" autocomplete="off" wire:model="certPem" placeholder="-----BEGIN CERTIFICATE-----" class="mt-1" />
                    <input type="file" accept=".pem,.crt,.cer,.key,.txt" aria-label="Zertifikat aus Datei laden" x-on:change="load($event, 'certPem')" class="mt-2 text-xs text-gray-500">
                </x-field>
                <x-field label="Privater Schlüssel (PEM, ohne Passwort)" for="key-pem">
                    <x-input.textarea id="key-pem" rows="5" autocomplete="off" wire:model="keyPem" placeholder="-----BEGIN PRIVATE KEY-----" class="mt-1" />
                    <input type="file" accept=".pem,.crt,.cer,.key,.txt" aria-label="Schlüssel aus Datei laden" x-on:change="load($event, 'keyPem')" class="mt-2 text-xs text-gray-500">
                </x-field>
            </div>
            <div class="mt-4 flex justify-end">
                <x-input.button type="submit" wire:loading.attr="disabled">Hochladen</x-input.button>
            </div>
        </form>
    </x-panel>
</div>
