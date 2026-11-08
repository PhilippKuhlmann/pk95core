@php
    use App\Modules\Proxy\Hosts;
    $date = fn ($s) => \Illuminate\Support\Carbon::createFromTimestamp($s)->timezone(config('app.timezone'))->format('d.m.Y');
    $logUrl = fn ($d) => route('apps.page', [$installedApp, 'log']).'?host='.urlencode($d);
    $dot = fn ($tone) => match ($tone) {
        'good' => 'bg-green-500', 'warn' => 'bg-amber-500', 'bad' => 'bg-rose-500', default => 'bg-gray-300',
    };
    $kind = $form['kind'] ?? 'proxy';
@endphp

<div wire:poll.20s>
    <x-page-header title="Hosts">
        <x-slot:sub>{{ count($hosts) }} Hosts · {{ count(array_filter($hosts, fn ($h) => $h['enabled'])) }} aktiv</x-slot:sub>
        @if ($editing === null)
            <x-input.button type="button" wire:click="create"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Host hinzufügen</x-input.button>
        @endif
    </x-page-header>

    @if ($loadError)
        <x-callout title="PK95 Proxy antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    @if ($editing !== null)
        <x-panel class="m-3" wire:key="host-form">
            <form wire:submit="save">
                <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">{{ $editing === 'new' ? 'Host hinzufügen' : 'Host bearbeiten' }}</div>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <x-field label="Art" for="h-kind" :hilfe="Hosts::KIND_HINTS[$kind] ?? null">
                        <x-input.select id="h-kind" wire:model.live="form.kind" class="mt-1">
                            @foreach (Hosts::KINDS as $k => $label)
                                <option value="{{ $k }}">{{ $label }}</option>
                            @endforeach
                        </x-input.select>
                    </x-field>
                    <x-field label="Domains" for="h-domains" hilfe="Eine pro Zeile. Der Name muss per DNS auf diesen Server zeigen." class="md:col-span-2">
                        <x-input.textarea id="h-domains" rows="2" wire:model="form.domains" placeholder="app.example.de" class="mt-1" />
                    </x-field>
                </div>

                @if ($kind === 'proxy')
                    <x-form-section title="Ziel" />
                    <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-6">
                        <x-field label="Protokoll" for="h-scheme">
                            <x-input.select id="h-scheme" wire:model.live="form.scheme" class="mt-1"><option value="http">http</option><option value="https">https</option></x-input.select>
                        </x-field>
                        <x-field label="Ziel-Host" for="h-host" class="md:col-span-3">
                            <x-input.text id="h-host" wire:model="form.upstream_host" placeholder="10.10.250.20" class="mt-1 w-full font-mono" />
                        </x-field>
                        <x-field label="Port" for="h-port" class="md:col-span-2">
                            <x-input.text id="h-port" type="number" min="1" max="65535" wire:model="form.upstream_port" placeholder="8080" class="mt-1 w-full" />
                        </x-field>
                    </div>
                    @if (($form['scheme'] ?? 'http') === 'https')
                        <x-input.checkbox label="Zertifikat des Ziels nicht prüfen (für selbstsignierte Zertifikate)" wire:model="form.skip_verify" />
                    @endif
                @elseif ($kind === 'redirect')
                    <x-form-section title="Weiterleitung" />
                    <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-3">
                        <x-field label="Ziel-Adresse" for="h-rurl" class="md:col-span-2">
                            <x-input.text id="h-rurl" wire:model="form.redirect_url" placeholder="https://neue-domain.de" class="mt-1 w-full font-mono" />
                        </x-field>
                        <x-field label="Status" for="h-rcode" hilfe="Browser merken sich 301 und 308 sehr lange. Nimm 302, solange du nicht ganz sicher bist.">
                            <x-input.select id="h-rcode" wire:model="form.redirect_code" class="mt-1">
                                <option value="302">302: vorübergehend</option>
                                <option value="301">301: dauerhaft</option>
                                <option value="307">307: vorübergehend, Methode bleibt</option>
                                <option value="308">308: dauerhaft, Methode bleibt</option>
                            </x-input.select>
                        </x-field>
                    </div>
                    <x-input.checkbox label="Pfad und Abfrage an die Ziel-Adresse anhängen" wire:model="form.redirect_keep_path" />
                @elseif ($kind === 'status')
                    <x-form-section title="Statusseite" />
                    <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-3">
                        <x-field label="Titel der Seite" for="h-stitle">
                            <x-input.text id="h-stitle" maxlength="80" wire:model="form.status_title" placeholder="Status" class="mt-1 w-full" />
                        </x-field>
                        <x-field label="Angezeigte Dienste" class="md:col-span-2" hilfe="Besucher sehen die Domain und den Zustand, nie das Ziel oder interne Adressen.">
                            @if ($proxies)
                                <div class="mt-1 grid max-h-56 grid-cols-1 gap-x-4 overflow-y-auto rounded-lg border border-gray-200 p-2 sm:grid-cols-2 dark:border-gray-700">
                                    @foreach ($proxies as $p)
                                        <x-input.checkbox :label="$p['domains'][0].($p['enabled'] ? '' : ' (deaktiviert)')" value="{{ $p['id'] }}" wire:model="form.status_hosts" />
                                    @endforeach
                                </div>
                            @else
                                <p class="mt-1 text-sm text-gray-500">Es gibt noch keinen Reverse Proxy.</p>
                            @endif
                        </x-field>
                    </div>
                @endif

                <div class="mt-4">
                    <x-input.checkbox label="Aktiv (Caddy holt dann sofort das Zertifikat)" wire:model="form.enabled" />
                </div>

                @if ($kind === 'proxy')
                    <x-form-section title="Wartung" />
                    <x-input.checkbox label="Wartungsseite statt des Dienstes anzeigen" hilfe="Während der Wartung gibt es keine Prüfung und keine Ausfallmeldungen." wire:model.live="form.maintenance" />
                    @if (! empty($form['maintenance']))
                        <div class="mt-2 grid grid-cols-1 gap-3 pl-6">
                            <x-input.checkbox label="Aus internen Netzen weiter normal erreichbar" wire:model="form.maintenance_bypass" />
                            <x-field label="Text für diesen Host" for="h-mtext" hilfe="Leer lassen für den Text von der Seite Fehlerseiten.">
                                <x-input.textarea id="h-mtext" rows="2" maxlength="1000" wire:model="form.maintenance_text" placeholder="Zum Beispiel: Wir sind gegen 18 Uhr wieder da." class="mt-1 font-sans text-sm" />
                            </x-field>
                        </div>
                    @endif
                @endif

                <x-form-section title="Zugriff" />
                @if (! in_array($kind, ['redirect', 'dead'], true))
                    <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-field label="Zugriffsliste" for="h-al" hilfe="Ohne Liste kann jeder mit dem Link den Dienst aufrufen. Listen, auch „Intern“ mit deinen internen Netzen, bearbeitest du unter Zugriffslisten.">
                            <x-input.select id="h-al" wire:model.live="form.access_list_id" class="mt-1">
                                <option value="0">Keine: öffentlich erreichbar</option>
                                @foreach ($lists as $l)
                                    <option value="{{ $l['id'] }}">{{ $l['name'] }}</option>
                                @endforeach
                            </x-input.select>
                        </x-field>
                    </div>
                    @if (in_array((string) ($form['access_list_id'] ?? '0'), $listsWithUsers, true))
                        <x-callout title="Hinweis" class="mt-3">Apps und Schnittstellen können sich auf der Login-Seite nicht anmelden. Sie brauchen eine erlaubte IP-Adresse mit „Eine Bedingung genügt“.</x-callout>
                    @endif
                @endif
                @if ($kind === 'proxy')
                    <div class="mt-2">
                        <x-input.checkbox label="Häufige Angriffe blockieren (Block Common Exploits)" wire:model="form.block_exploits"
                            hilfe="Antwortet mit 403 auf typische SQL- und Datei-Injection, bekannte Angriffs- und Spam-Muster und einige Werkzeuge, Regeln wie in Nginx Proxy Manager. Dazu auf .git, .env und wp-config.php. Das kann selten auch echte Anfragen treffen, etwa eine unkodierte Adresse wie ?redirect=http://…" />
                    </div>
                @endif

                <x-form-section title="Verschlüsselung" />
                <div class="mt-2 flex flex-col gap-1">
                    <x-input.checkbox label="HTTPS erzwingen" wire:model.live="form.force_https"
                        :hilfe="empty($form['force_https']) ? 'Ohne HTTPS-Zwang ist der Dienst auch unverschlüsselt über Port 80 erreichbar. Nur für alte Geräte sinnvoll, die kein HTTPS können.' : null" />
                    <x-input.checkbox label="HSTS senden (Browser erzwingen ein Jahr lang HTTPS)" wire:model="form.hsts" :disabled="empty($form['force_https'])" />
                </div>
                <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-field label="TLS-Mindestversion" for="h-tls">
                        <x-input.select id="h-tls" wire:model="form.tls_min" class="mt-1">
                            <option value="1.2">TLS 1.2: passt für fast alle Geräte</option>
                            <option value="1.3">TLS 1.3: nur aktuelle Geräte</option>
                        </x-input.select>
                    </x-field>
                </div>
                <x-input.checkbox label="An BSI TR-02102-2 (2026-01) ausrichten" wire:model="form.tls_bsi"
                    hilfe="TLS 1.2 nur noch mit AES-GCM und Forward Secrecy, Schlüsseleinigung nur mit den Kurven P-256, P-384 und P-521. Dadurch entfällt die quantensichere Schlüsseleinigung, die Browser sonst nutzen, weil das BSI sie noch nicht empfiehlt. Volle Konformität ist nicht möglich: TLS 1.3 bietet weiter auch ChaCha20 an, das lässt sich in Caddy nicht abschalten." />

                <x-form-section title="Erweitert" />
                <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-field label="Sicherheits-Header" for="h-sec" hilfe="Header, die die Anwendung selbst sendet, bleiben unverändert. „Streng“ verhindert das Einbetten in andere Seiten, zum Beispiel in ein Home-Assistant-Dashboard.">
                        <x-input.select id="h-sec" wire:model="form.security_headers" class="mt-1">
                            <option value="off">Aus</option>
                            <option value="basic">Standard: sicher für fast alle Anwendungen</option>
                            <option value="strict">Streng: auch gegen Einbetten, ohne Kamera und Standort</option>
                        </x-input.select>
                    </x-field>
                    <x-field label="Notiz" for="h-notes">
                        <x-input.text id="h-notes" maxlength="500" wire:model="form.notes" class="mt-1 w-full" />
                    </x-field>
                </div>
                @if ($kind === 'proxy')
                    <x-input.checkbox label="Host-Header auf das Ziel setzen (nötig bei manchen externen Diensten)" wire:model="form.upstream_host_header" />
                @endif

                <div class="mt-6 flex justify-end gap-2">
                    <x-input.button type="button" color="gray" wire:click="cancel">Abbrechen</x-input.button>
                    <x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button>
                </div>
            </form>
        </x-panel>
    @endif

    <div class="m-3 flex flex-wrap gap-1" role="group" aria-label="Art">
        @foreach (['' => 'Alle'] + Hosts::KINDS as $k => $label)
            <button type="button" wire:click="filter('{{ $k }}')" aria-pressed="{{ $kindFilter === $k ? 'true' : 'false' }}"
                @class(['rounded-lg px-3 py-1.5 text-sm transition-colors',
                    'bg-cerulean-600 text-white' => $kindFilter === $k,
                    'bg-white text-gray-700 hover:bg-gray-100 border border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700 dark:hover:bg-gray-700' => $kindFilter !== $k])>
                {{ $label }} ({{ $k === '' ? count($hosts) : ($counts[$k] ?? 0) }})
            </button>
        @endforeach
    </div>

    @if (! $shown && ! $loadError)
        <x-panel class="m-3">
            <div class="text-center">
                <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">{{ Hosts::EMPTY[$kindFilter][0] }}</div>
                <p class="mx-auto mt-2 max-w-xl text-sm text-gray-500 dark:text-gray-400">{{ Hosts::EMPTY[$kindFilter][1] }}</p>
                @if ($editing === null)
                    <x-input.button type="button" class="mt-4" wire:click="create">Host hinzufügen</x-input.button>
                @endif
            </div>
        </x-panel>
    @elseif ($shown)
        <x-panel class="m-3 overflow-x-auto" polster="keins">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Aktiv</th><th class="px-4 py-3">Domains</th><th class="px-4 py-3">Ziel</th>
                        <th class="px-4 py-3">Zertifikat</th><th class="hidden px-4 py-3 lg:table-cell">Zugriff</th><th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($shown as $x)
                        @php
                            $c = $checks[$x['id']] ?? null;
                            $protect = [];
                            if ($x['access_list_id']) $protect[] = $listNames[$x['access_list_id']] ?? 'Zugriffsliste';
                            if ($x['block_exploits'] && $x['kind'] === 'proxy') $protect[] = 'Angriffsfilter';
                            if ($x['hsts']) $protect[] = 'HSTS';
                            if ($x['tls_min'] === '1.3') $protect[] = 'TLS 1.3';
                            if ($x['tls_bsi']) $protect[] = 'BSI';
                            if ($x['allow_http']) $protect[] = 'HTTP erlaubt';
                        @endphp
                        <tr wire:key="host-{{ $x['id'] }}" @class(['opacity-60' => ! $x['enabled']])>
                            <td class="px-4 py-3 align-top">
                                <button type="button" role="switch" aria-checked="{{ $x['enabled'] ? 'true' : 'false' }}" aria-label="{{ $x['domains'][0] }} aktiv"
                                    wire:click="toggle({{ $x['id'] }})" wire:loading.attr="disabled"
                                    @class(['relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors', 'bg-cerulean-600' => $x['enabled'], 'bg-gray-300 dark:bg-gray-600' => ! $x['enabled']])>
                                    <span @class(['inline-block h-5 w-5 translate-y-0.5 rounded-full bg-white shadow transition-transform', 'translate-x-5.5' => $x['enabled'], 'translate-x-0.5' => ! $x['enabled']])></span>
                                </button>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex flex-col">
                                    @foreach ($x['domains'] as $d)
                                        <a href="{{ $logUrl($d) }}" wire:navigate title="Zugriffe ansehen" class="font-medium text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $d }}</a>
                                    @endforeach
                                </div>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @if ($x['kind'] !== 'proxy')
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ Hosts::KINDS[$x['kind']] }}</span>
                                    @endif
                                    @if ($x['maintenance'])
                                        <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900 dark:bg-amber-900 dark:text-amber-100">{{ $x['maintenance_bypass'] ? 'Wartung, intern erreichbar' : 'Wartung' }}</span>
                                    @endif
                                </div>
                                @if ($x['notes'])
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $x['notes'] }}</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 align-top">
                                @switch($x['kind'])
                                    @case('redirect')
                                        <div class="font-mono text-xs">{{ $x['redirect_url'] }}</div>
                                        <div class="text-xs text-gray-500">Status {{ $x['redirect_code'] }}{{ $x['redirect_keep_path'] ? ', Pfad bleibt erhalten' : '' }}</div>
                                        @break
                                    @case('dead')
                                        <span class="text-gray-500">Antwortet mit 404</span>
                                        @break
                                    @case('status')
                                        {{ count($x['status_hosts']) }} {{ count($x['status_hosts']) === 1 ? 'Dienst' : 'Dienste' }} öffentlich sichtbar
                                        @break
                                    @default
                                        @php $known = $x['enabled'] && $c && $c['upstream_known']; @endphp
                                        <div class="flex items-center gap-2 font-mono text-xs">
                                            @if ($known)<span class="h-2 w-2 rounded-full {{ $dot($c['upstream_up'] ? 'good' : 'bad') }}"></span>@endif
                                            {{ $x['scheme'] }}://{{ str_contains($x['upstream_host'], ':') ? '['.$x['upstream_host'].']' : $x['upstream_host'] }}:{{ $x['upstream_port'] }}
                                        </div>
                                        @if ($known && ! $c['upstream_up'])
                                            <div class="mt-1 whitespace-normal text-xs text-rose-700 dark:text-rose-300">{{ $c['upstream_error'] ?? 'Ziel nicht erreichbar' }}</div>
                                        @endif
                                @endswitch
                            </td>
                            <td class="px-4 py-3 align-top text-xs">
                                @if (! $x['enabled'])
                                    <span class="text-gray-400">Deaktiviert</span>
                                @elseif (! $c)
                                    <span class="text-gray-400">Wird geprüft …</span>
                                @else
                                    @foreach ($c['domains'] as $d)
                                        @php $prefix = count($c['domains']) > 1 ? $d['domain'].': ' : ''; @endphp
                                        @if ($d['status'] === 'ok')
                                            @php $days = (int) round(($d['not_after'] - time()) / 86400); @endphp
                                            <div class="flex items-center gap-2" title="Ausgestellt von {{ $d['issuer'] ?? '' }}">
                                                <span class="h-2 w-2 shrink-0 rounded-full {{ $dot($days < 14 ? 'warn' : 'good') }}"></span>{{ $prefix }}gültig bis {{ $date($d['not_after']) }}
                                            </div>
                                        @else
                                            <div class="flex items-center gap-2" title="Caddy holt das Zertifikat noch. Der Hostname muss per DNS auf diesen Server zeigen und Port 80 muss aus dem Internet erreichbar sein.">
                                                <span class="h-2 w-2 shrink-0 rounded-full {{ $dot('warn') }}"></span>{{ $prefix }}Zertifikat ausstehend
                                            </div>
                                        @endif
                                    @endforeach
                                @endif
                            </td>
                            <td class="hidden px-4 py-3 align-top text-xs lg:table-cell">
                                {!! $protect ? e(implode(', ', $protect)) : '<span class="text-gray-400">Öffentlich</span>' !!}
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex justify-end gap-1">
                                    @if ($x['kind'] === 'proxy')
                                        <x-input.button type="button" size="sm" :color="$x['maintenance'] ? 'blue' : 'gray'" wire:click="maintenance({{ $x['id'] }})"
                                            aria-pressed="{{ $x['maintenance'] ? 'true' : 'false' }}" title="{{ $x['maintenance'] ? 'Wartung beenden' : 'Wartung starten' }}">
                                            <x-icon name="warn" class="h-4 w-4" /><span class="sr-only">{{ $x['domains'][0] }}: {{ $x['maintenance'] ? 'Wartung beenden' : 'Wartung starten' }}</span>
                                        </x-input.button>
                                    @endif
                                    <x-input.button type="button" size="sm" color="gray" wire:click="edit({{ $x['id'] }})">Bearbeiten</x-input.button>
                                    <x-input.button type="button" size="sm" color="gray" aria-label="{{ $x['domains'][0] }} löschen"
                                        x-on:click="$store.confirm.ask({ title: @js($x['domains'][0].' löschen?'), text: 'Die Domain ist danach sofort nicht mehr erreichbar. Bisherige Zugriffsdaten bleiben erhalten.', button: 'Löschen', ok: () => $wire.delete({{ $x['id'] }}) })">
                                        <x-icon name="trash" class="h-4 w-4" />
                                    </x-input.button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-panel>
    @endif
</div>
