@php
    use App\Modules\Dns\Fmt;
    use App\Modules\Dns\Settings;
    $card = 'font-DINPro-bold text-gray-900 dark:text-gray-100';
    $intro = 'mt-1 text-sm text-gray-500 dark:text-gray-400';
    $num = 'mt-1 w-36';
@endphp

<div>
    <x-page-header title="Einstellungen" />

    @if ($loadError)
        <x-callout title="PK95 DNS antwortet nicht" ton="gelb" class="m-3">
            {{ $loadError }}
            <x-slot:aktionen>
                <x-input.button type="button" color="gray" wire:click="retry"><x-icon name="refresh" class="h-4 w-4" />Erneut versuchen</x-input.button>
            </x-slot:aktionen>
        </x-callout>
    @endif

    @if ($loaded)
        <div class="grid grid-cols-1 xl:grid-cols-2">
            {{-- upstreams --}}
            <x-panel class="m-3">
                <div class="{{ $card }}">Upstreams</div>
                <p class="{{ $intro }}">An diese Server gehen alle Anfragen, die nicht blockiert oder lokal beantwortet werden.</p>
                <form wire:submit="save('upstreams')" class="mt-3 flex flex-col gap-4">
                    <x-field label="Upstreams" for="s-up" feld="s.upstreams" hilfe="Eine Adresse pro Zeile: https://dns.quad9.net/dns-query (DNS over HTTPS), h3://cloudflare-dns.com/dns-query (DoH über HTTP/3), quic://dns.adguard-dns.com (DNS over QUIC) oder tls://dns.quad9.net (DNS over TLS). Klartext wie 9.9.9.9 nur, wenn „Nur verschlüsselte Upstreams“ aus ist.">
                        <x-input.textarea id="s-up" feld="s.upstreams" rows="4" wire:model="s.upstreams" class="mt-1" />
                    </x-field>
                    <x-input.checkbox label="Nur verschlüsselte Upstreams" hilfe="Upstreams und Ausweich-Upstreams müssen DoH, DoH3, DoQ oder DoT sprechen; Zertifikate werden immer geprüft. Bedingte Weiterleitung und private PTR-Anfragen ins LAN dürfen Klartext nutzen." wire:model="s.encrypted_only" />
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-field label="Ausweich-Upstreams" for="s-fb" feld="s.fallback" hilfe="Nur wenn alle Upstreams scheitern.">
                            <x-input.textarea id="s-fb" feld="s.fallback" rows="2" wire:model="s.fallback" class="mt-1" />
                        </x-field>
                        <x-field label="Bootstrap-DNS" for="s-bs" feld="s.bootstrap" hilfe="IP-Adressen, die nur die Namen der verschlüsselten Upstreams auflösen. Die Zertifikatsprüfung schützt die Verbindung trotzdem.">
                            <x-input.textarea id="s-bs" feld="s.bootstrap" rows="2" wire:model="s.bootstrap" class="mt-1" />
                        </x-field>
                    </div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-[2fr_1fr]">
                        <x-field label="Verteilung" for="s-mode" feld="s.upstream_mode">
                            <x-input.select id="s-mode" feld="s.upstream_mode" wire:model="s.upstream_mode" class="mt-1">
                                @foreach (Settings::MODES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                            </x-input.select>
                        </x-field>
                        <x-field label="Zeitlimit (ms)" for="s-to" feld="s.upstream_timeout_ms">
                            <x-input.text id="s-to" feld="s.upstream_timeout_ms" type="number" min="500" max="30000" step="100" wire:model="s.upstream_timeout_ms" class="mt-1 w-full" />
                        </x-field>
                    </div>
                    <x-input.checkbox label="DNSSEC anfragen" hilfe="Setzt das DO-Bit, damit Upstreams DNSSEC-Signaturen mitschicken." wire:model="s.dnssec" />
                    @if ($testResults)
                        <ul class="flex flex-col gap-1 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700" wire:key="test-results">
                            @foreach ($testResults as $r)
                                <li class="flex flex-wrap items-center gap-2">
                                    <span class="h-2 w-2 shrink-0 rounded-full {{ Fmt::dot($r['ok'] ? 'good' : 'bad') }}"></span>
                                    <span class="break-all font-mono text-xs text-gray-900 dark:text-gray-100">{{ $r['upstream'] }}</span>
                                    @if ($r['ok'])
                                        <span class="text-xs text-green-700 dark:text-green-300">{{ Fmt::ms($r['ms']) }}</span>
                                    @else
                                        <span class="text-xs text-rose-700 dark:text-rose-300">{{ $r['error'] !== '' ? $r['error'] : 'Keine Antwort' }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        <x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button>
                        <x-input.button type="button" color="gray" wire:click="test" wire:loading.attr="disabled" wire:target="test">
                            <span wire:loading.remove wire:target="test">Testen</span><span wire:loading wire:target="test">Wird getestet …</span>
                        </x-input.button>
                    </div>
                </form>
                @if ($status && $status['upstreams'])
                    <x-form-section title="Zustand seit dem Start" />
                    <ul class="mt-2 flex flex-col gap-1 text-sm">
                        @foreach ($status['upstreams'] as $u)
                            <li class="flex flex-wrap items-center gap-2">
                                <span class="h-2 w-2 shrink-0 rounded-full {{ Fmt::dot($u['ok'] ? 'good' : (($u['queries'] ?? 0) === 0 && ($u['last_error'] ?? '') === '' ? 'idle' : 'bad')) }}"></span>
                                <span class="break-all font-mono text-xs text-gray-900 dark:text-gray-100">{{ $u['address'] }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ Fmt::num($u['queries']) }} Anfragen · {{ Fmt::num($u['failures']) }} Fehler · {{ Fmt::ms($u['avg_ms']) }}</span>
                                @if (($u['last_error'] ?? '') !== '')<span class="w-full pl-4 text-xs text-rose-700 dark:text-rose-300">{{ $u['last_error'] }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-panel>

            {{-- conditional forwarding --}}
            <x-panel class="m-3">
                <div class="{{ $card }}">Weiterleitung nach Domain</div>
                <p class="{{ $intro }}">Anfragen für eine Domain und ihre Subdomains gehen an einen bestimmten Server, etwa fritz.box an die Fritzbox.</p>
                <form wire:submit="save('routing')" class="mt-3 flex flex-col gap-4">
                    <div class="flex flex-col gap-2">
                        @foreach ($s['conditional'] as $i => $c)
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_auto]" wire:key="cond-{{ $i }}">
                                <div>
                                    <x-input.text wire:model="s.conditional.{{ $i }}.domain" feld="s.conditional.{{ $i }}.domain" aria-label="Domain" placeholder="fritz.box" class="w-full font-mono text-sm" />
                                    <x-input.fehler :feld="'s.conditional.'.$i.'.domain'" />
                                </div>
                                <div>
                                    <x-input.text wire:model="s.conditional.{{ $i }}.upstream" feld="s.conditional.{{ $i }}.upstream" aria-label="Server" placeholder="192.168.178.1" class="w-full font-mono text-sm" />
                                    <x-input.fehler :feld="'s.conditional.'.$i.'.upstream'" />
                                </div>
                                <x-input.button type="button" size="feld" color="gray" wire:click="removeConditional({{ $i }})">Entfernen</x-input.button>
                            </div>
                        @endforeach
                        <x-input.fehler feld="s.conditional" />
                        <div><x-input.button type="button" size="sm" color="gray" wire:click="addConditional"><x-icon name="plus" class="h-3.5 w-3.5" />Domain hinzufügen</x-input.button></div>
                    </div>
                    <x-input.checkbox label="Private Rückwärtsauflösung im Netz halten"
                        hilfe="Fragen nach Namen privater Adressen (192.168.x.x, 10.x.x.x …) gehen nie an öffentliche Upstreams, sondern an die Server darunter. Ohne Server antwortet PK95 DNS mit „unbekannt“." wire:model="s.private_ptr" />
                    <x-field label="Server für private Adressen" for="s-pu" feld="s.private_upstreams" hilfe="Meist der Router, der die Namen im Netz kennt.">
                        <x-input.textarea id="s-pu" feld="s.private_upstreams" rows="2" wire:model="s.private_upstreams" placeholder="192.168.178.1" class="mt-1" />
                    </x-field>
                    <div><x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button></div>
                </form>
            </x-panel>

            {{-- access --}}
            <x-panel class="m-3">
                <div class="{{ $card }}">Zugriff</div>
                <p class="{{ $intro }}">Wer diesen DNS-Server fragen darf.</p>
                <form wire:submit="save('access')" class="mt-3 flex flex-col gap-4">
                    @if (trim((string) $s['allowed_clients']) === '')
                        <x-callout title="Offener Resolver" ton="gelb">Ohne erlaubte Adressen beantwortet PK95 DNS Anfragen von überall. Ist Port 53 aus dem Internet erreichbar, kann der Server für Angriffe missbraucht werden. Trage deine Netze ein.</x-callout>
                    @endif
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-field label="Erlaubte Clients" for="s-allow" feld="s.allowed_clients" hilfe="IP-Adressen oder Netze, eine pro Zeile. Leer erlaubt alle.">
                            <x-input.textarea id="s-allow" feld="s.allowed_clients" rows="5" wire:model.live.debounce.400ms="s.allowed_clients" class="mt-1" />
                        </x-field>
                        <x-field label="Gesperrte Clients" for="s-deny" feld="s.disallowed_clients" hilfe="Werden immer abgelehnt.">
                            <x-input.textarea id="s-deny" feld="s.disallowed_clients" rows="5" wire:model="s.disallowed_clients" class="mt-1" />
                        </x-field>
                    </div>
                    <x-field label="Ratenlimit (Anfragen pro Sekunde und Client)" for="s-rl" feld="s.ratelimit" hilfe="0 schaltet das Limit aus.">
                        <x-input.text id="s-rl" feld="s.ratelimit" type="number" min="0" max="100000" wire:model="s.ratelimit" class="{{ $num }}" />
                    </x-field>
                    <x-input.checkbox label="ANY-Anfragen ablehnen" hilfe="Werden kaum genutzt, aber gern für Verstärkungsangriffe." wire:model="s.refuse_any" />
                    <div><x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button></div>
                </form>
            </x-panel>

            {{-- blocking --}}
            <x-panel class="m-3">
                <div class="{{ $card }}">Antwort bei Blockierung</div>
                <p class="{{ $intro }}">So antwortet PK95 DNS, wenn eine Regel eine Domain blockiert.</p>
                <form wire:submit="save('blocking')" class="mt-3 flex flex-col gap-4">
                    <x-field label="Antwort" for="s-bm" feld="s.blocking_mode">
                        <x-input.select id="s-bm" feld="s.blocking_mode" wire:model.live="s.blocking_mode" class="mt-1">
                            @foreach (Settings::BLOCKING as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </x-input.select>
                    </x-field>
                    @if ($s['blocking_mode'] === 'custom_ip')
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <x-field label="IPv4-Adresse" for="s-b4" feld="s.blocking_ipv4">
                                <x-input.text id="s-b4" feld="s.blocking_ipv4" wire:model="s.blocking_ipv4" placeholder="192.168.1.2" class="mt-1 w-full font-mono text-sm" />
                            </x-field>
                            <x-field label="IPv6-Adresse" for="s-b6" feld="s.blocking_ipv6">
                                <x-input.text id="s-b6" feld="s.blocking_ipv6" wire:model="s.blocking_ipv6" placeholder="fd00::2" class="mt-1 w-full font-mono text-sm" />
                            </x-field>
                        </div>
                    @endif
                    <x-field label="Gültigkeit der Antwort (Sekunden)" for="s-bttl" feld="s.blocked_ttl" hilfe="So lange merken sich Geräte die Blockierung.">
                        <x-input.text id="s-bttl" feld="s.blocked_ttl" type="number" min="0" max="86400" wire:model="s.blocked_ttl" class="{{ $num }}" />
                    </x-field>
                    <x-input.checkbox label="IPv6-Adressen nicht ausliefern" hilfe="AAAA-Anfragen bekommen eine leere Antwort. Nur nötig, wenn IPv6 im Netz Probleme macht." wire:model="s.disable_ipv6" />
                    <div><x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button></div>
                </form>
            </x-panel>

            {{-- cache --}}
            <x-panel class="m-3">
                <div class="{{ $card }}">Cache</div>
                @if ($status)
                    @php $cache = $status['cache']; @endphp
                    <p class="{{ $intro }}">{{ Fmt::num($cache['entries']) }} Einträge, {{ Fmt::share($cache['hits'], $cache['hits'] + $cache['misses']) }} der Anfragen aus dem Cache beantwortet.</p>
                @endif
                <form wire:submit="save('cache')" class="mt-3 flex flex-col gap-4">
                    <x-input.checkbox label="Antworten zwischenspeichern" wire:model="s.cache_enabled" />
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-field label="Größe (Einträge)" for="s-cs" feld="s.cache_size">
                            <x-input.text id="s-cs" feld="s.cache_size" type="number" min="0" max="1000000" wire:model="s.cache_size" class="mt-1 w-full" />
                        </x-field>
                        <x-field label="Mindestdauer (s)" for="s-cmin" feld="s.cache_min_ttl">
                            <x-input.text id="s-cmin" feld="s.cache_min_ttl" type="number" min="0" max="3600" wire:model="s.cache_min_ttl" class="mt-1 w-full" />
                        </x-field>
                        <x-field label="Höchstdauer (s)" for="s-cmax" feld="s.cache_max_ttl">
                            <x-input.text id="s-cmax" feld="s.cache_max_ttl" type="number" min="0" max="604800" wire:model="s.cache_max_ttl" class="mt-1 w-full" />
                        </x-field>
                    </div>
                    <x-input.hilfe>Die Dauer begrenzt, wie lange eine Antwort im Cache bleibt. Höchstdauer 0 heißt: wie vom Upstream angegeben.</x-input.hilfe>
                    <div class="flex flex-wrap gap-2">
                        <x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button>
                        <x-input.button type="button" color="gray" wire:click="clearCache">Cache leeren</x-input.button>
                    </div>
                </form>
            </x-panel>

            {{-- query log and statistics --}}
            <x-panel class="m-3">
                <div class="{{ $card }}">Abfragelog und Statistik</div>
                <form wire:submit="save('log')" class="mt-3 flex flex-col gap-4">
                    <x-input.checkbox label="Abfragen protokollieren" hilfe="Das Log enthält, welches Gerät welche Domain gefragt hat." wire:model="s.querylog_enabled" />
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-field label="Log aufbewahren (Stunden)" for="s-qh" feld="s.querylog_hours" hilfe="1 bis 2160 (90 Tage).">
                            <x-input.text id="s-qh" feld="s.querylog_hours" type="number" min="1" max="2160" wire:model="s.querylog_hours" class="mt-1 w-full" />
                        </x-field>
                        <x-field label="Statistik aufbewahren (Tage)" for="s-sd" feld="s.stats_days" hilfe="1 bis 90.">
                            <x-input.text id="s-sd" feld="s.stats_days" type="number" min="1" max="90" wire:model="s.stats_days" class="mt-1 w-full" />
                        </x-field>
                    </div>
                    <x-input.checkbox label="IP-Adressen kürzen" hilfe="Speichert IPv4 ohne letzte Stelle und IPv6 nur bis /48. Clients lassen sich im Log dann nicht mehr genau zuordnen." wire:model="s.anonymize_ip" />
                    <div class="flex flex-wrap gap-2">
                        <x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button>
                        <x-input.button type="button" color="gray"
                            x-on:click="$store.confirm.ask({ title: 'Abfragelog leeren?', text: 'Alle gespeicherten Abfragen werden gelöscht.', button: 'Leeren', ok: () => $wire.clearLog() })">Log leeren</x-input.button>
                        <x-input.button type="button" color="gray"
                            x-on:click="$store.confirm.ask({ title: 'Statistik zurücksetzen?', text: 'Alle Zahlen der Übersicht beginnen wieder bei null.', button: 'Zurücksetzen', ok: () => $wire.resetStats() })">Statistik zurücksetzen</x-input.button>
                    </div>
                </form>
            </x-panel>

            {{-- safesearch --}}
            <x-panel class="m-3">
                <div class="{{ $card }}">SafeSearch</div>
                <p class="{{ $intro }}">Leitet Suchmaschinen auf ihre jugendfreie Variante um. Gilt für alle Clients ohne eigene Einstellungen.</p>
                <form wire:submit="save('safesearch')" class="mt-3 flex flex-col gap-3">
                    <x-input.checkbox label="SafeSearch erzwingen" wire:model.live="s.safesearch.enabled" />
                    @if ($s['safesearch']['enabled'])
                        <div class="ml-6 flex flex-wrap gap-x-5">
                            @foreach (Fmt::ENGINES as $key => $label)
                                <x-input.checkbox :label="$label" wire:model="s.safesearch.{{ $key }}" />
                            @endforeach
                        </div>
                    @endif
                    <x-input.fehler feld="s.safesearch" />
                    <div><x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button></div>
                </form>
            </x-panel>

            {{-- lists --}}
            <x-panel class="m-3">
                <div class="{{ $card }}">Listen</div>
                <form wire:submit="save('lists')" class="mt-3 flex flex-col gap-4">
                    <x-field label="Listen aktualisieren alle (Stunden)" for="s-lu" feld="s.lists_update_hours" hilfe="0 schaltet die Aktualisierung aus, höchstens 168 (eine Woche).">
                        <x-input.text id="s-lu" feld="s.lists_update_hours" type="number" min="0" max="168" wire:model="s.lists_update_hours" class="{{ $num }}" />
                    </x-field>
                    <div><x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button></div>
                </form>
            </x-panel>
        </div>
    @endif
</div>
