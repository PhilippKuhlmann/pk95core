@php
    use App\Modules\Proxy\Fmt;
    use App\Modules\Proxy\Security;
    $card = 'font-DINPro-bold text-gray-900 dark:text-gray-100';
    $intro = 'mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400';
    $listProp = $mode === 'allow' ? 'allowList' : 'blockList';
    $sorted = collect($current)->sortBy(fn ($c) => $names[$c] ?? $c)->values();
    $pending = collect($bl['lists'] ?? [])->contains(fn ($l) => ! empty($l['pending']));
@endphp

<div @if ($busy || $pending) wire:poll.2s @else wire:poll.30s @endif>
    <x-page-header title="Sicherheit" />

    @if ($loadError)
        <x-callout title="PK95 Proxy antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    {{-- countries --}}
    <x-panel class="m-3">
        <div class="{{ $card }}">Länder</div>
        <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-700 dark:text-gray-300" role="radiogroup" aria-label="Art der Regel">
            <label class="inline-flex items-center gap-2"><input type="radio" value="block" wire:model.live="mode" class="border-gray-300 text-cerulean-600 focus:ring-cerulean-500">Gewählte Länder sperren</label>
            <label class="inline-flex items-center gap-2"><input type="radio" value="allow" wire:model.live="mode" class="border-gray-300 text-cerulean-600 focus:ring-cerulean-500">Nur gewählte Länder freigeben</label>
        </div>
        <p class="{{ $intro }}">
            @if ($mode === 'allow')
                Nur Verbindungen aus den gewählten Ländern erreichen Port 443. Adressen, die keinem Land zugeordnet sind, gehören nicht dazu. Port 80 bleibt offen, weil Let's Encrypt von dort Zertifikate prüft; deine Dienste sind darüber nicht erreichbar. Lokale Netze sind immer frei.
            @else
                Verbindungen aus gesperrten Ländern werden auf Port 443 verworfen, bevor sie Caddy erreichen. Das gilt für alle Hosts. Port 80 bleibt offen, weil Let's Encrypt von dort Zertifikate prüft; deine Dienste sind darüber nicht erreichbar. Lokale Netze können nie gesperrt werden.
            @endif
        </p>

        @if ($d)
            @php $f = $d['firewall'] ?? null; @endphp
            <div class="mt-3 text-sm">
                @if ($busy)
                    <span class="flex items-center gap-2"><span class="h-2 w-2 animate-pulse rounded-full {{ Fmt::dot('warn') }}"></span>Wird geladen …</span>
                @elseif ($f && ($f['error'] ?? ''))
                    <span class="text-rose-700 dark:text-rose-300">{{ $f['error'] }}</span>
                @elseif ($f && ! empty($f['countries']))
                    <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot('good') }}"></span>
                        Aktiv: {{ Fmt::plural(count($f['countries']), 'Land', 'Länder') }} {{ $f['mode'] === 'allow' ? 'freigegeben' : 'gesperrt' }}, {{ Fmt::num($f['networks']) }} Netzbereiche, seit {{ Fmt::stamp($f['applied_at']) }}</span>
                    <div class="text-xs text-gray-500">Bisher {{ Fmt::num($f['dropped'] ?? 0) }} Pakete verworfen (Stand der letzten Prüfung). Verworfene Verbindungen erscheinen nicht in den Zugriffen.</div>
                @else
                    <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot('none') }}"></span>Keine Länderregel aktiv</span>
                @endif
            </div>
        @endif

        <div class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $mode === 'allow' ? 'Freigegebene Länder' : 'Gesperrte Länder' }}</div>
        <div class="mt-2 flex flex-wrap gap-1.5">
            @forelse ($sorted as $c)
                <button type="button" wire:click="unselect('{{ $c }}')" title="{{ $mode === 'allow' ? 'Nicht mehr freigeben' : 'Entsperren' }}"
                    class="rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs hover:border-rose-300 hover:bg-rose-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-rose-950">
                    {{ Fmt::flag($c) }} {{ $names[$c] ?? $c }} ×
                </button>
            @empty
                <span class="text-sm text-gray-500">{{ $mode === 'allow' ? 'Noch keine. Wähle mindestens ein Land aus.' : 'Keine. Alle Länder dürfen zugreifen.' }}</span>
            @endforelse
        </div>

        <div x-data="{ q: '' }" class="mt-4">
            <input type="search" x-model="q" placeholder="Land suchen …" aria-label="Land suchen"
                class="w-full max-w-xs rounded-lg border-gray-300 bg-white py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
            <div class="mt-2 grid max-h-72 grid-cols-1 gap-x-4 overflow-y-auto rounded-lg border border-gray-200 p-2 sm:grid-cols-2 lg:grid-cols-3 dark:border-gray-700" role="group" aria-label="Länder">
                @foreach ($codes as $c)
                    <label class="flex items-center gap-2 rounded px-1 py-0.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-700" wire:key="cty-{{ $c }}"
                        x-show="!q || @js(mb_strtolower($names[$c])).includes(q.toLowerCase()) || q.toUpperCase() === '{{ $c }}'">
                        <input type="checkbox" value="{{ $c }}" wire:model.live="{{ $listProp }}" class="rounded border-gray-300 text-cerulean-600 focus:ring-cerulean-500">
                        <span>{{ Fmt::flag($c) }}</span><span class="flex-1">{{ $names[$c] }}</span>
                        @if ($traffic[$c] ?? 0)<span class="text-xs text-gray-400">{{ Fmt::num($traffic[$c]) }} in 7 Tagen</span>@endif
                    </label>
                @endforeach
            </div>
        </div>

        <div class="mt-4 flex items-center gap-3">
            <x-input.button type="button" :disabled="$same || $busy || ($mode === 'allow' && ! $current)"
                x-on:click="$store.confirm.ask({ title: @js($mode === 'allow' ? 'Freigabe übernehmen?' : 'Ländersperre übernehmen?'), tone: 'blue', button: 'Übernehmen', text: @js($confirmText), ok: () => $wire.saveCountries() })">Übernehmen</x-input.button>
            @unless ($same)<span class="text-xs text-gray-500">Noch nicht übernommen</span>@endunless
        </div>
    </x-panel>

    {{-- blocklists --}}
    <x-panel class="m-3">
        <div class="{{ $card }}">Blocklisten</div>
        <p class="{{ $intro }}">Adressen aus eingeschalteten Listen werden abgewiesen, noch bevor eine TLS-Verbindung zustande kommt. Das Panel aktualisiert die Listen alle 6 Stunden selbst. Interne Netze und die Einträge unter „Nie sperren“ bleiben immer offen. Das gilt für Port 443, nicht für Port 80 und nicht für Streams.</p>
        @php
            $st = $bl['status'] ?? null;
            $problem = ($bl['error'] ?? '') ?: ($st['error'] ?? '');
            $anyOn = collect($bl['lists'] ?? [])->contains(fn ($l) => ! empty($l['enabled']));
            $blStamp = fn ($ts) => Fmt::at($ts)->format('d.m., H:i').' Uhr';
        @endphp
        <div class="mt-3 text-sm">
            @if ($problem)
                <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot('bad') }}"></span>{{ $problem }}</span>
            @elseif (! empty($st['networks']))
                <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot('good') }}"></span>{{ Fmt::num($st['networks']) }} Netze gesperrt, {{ Fmt::num($st['dropped'] ?? 0) }} Pakete abgewiesen</span>
            @elseif ($anyOn)
                <span class="flex items-center gap-2"><span class="h-2 w-2 animate-pulse rounded-full {{ Fmt::dot('warn') }}"></span>Wird eingerichtet …</span>
            @else
                <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot('none') }}"></span>Keine Liste aktiv</span>
            @endif
        </div>
        <div class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($bl['lists'] ?? [] as $l)
                @php $on = ! empty($l['enabled']) || ! empty($l['included_by']); @endphp
                <div class="flex items-start justify-between gap-4 py-3" wire:key="bl-{{ $l['id'] }}">
                    <div class="min-w-0 text-sm">
                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ $l['name'] }}
                            @if (! empty($l['recommended']))<span class="ml-1 rounded bg-cerulean-100 px-1.5 py-0.5 text-xs text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-100">Empfohlen</span>@endif
                        </div>
                        @if ($l['description'] ?? '')<div class="text-xs text-gray-500">{{ $l['description'] }}</div>@endif
                        @if (! empty($l['custom']))<code class="block truncate text-xs text-gray-500">{{ $l['urls'][0] ?? '' }}</code>@endif
                        @if ($l['warning'] ?? '')<div class="text-xs text-amber-700 dark:text-amber-300">{{ $l['warning'] }}</div>@endif
                        @if ($l['included_by'] ?? '')<div class="text-xs text-gray-500">Über die {{ $l['included_by'] }} aktiv</div>@endif
                        @if ($on)
                            @if ($l['error'] ?? '')
                                <div class="text-xs text-rose-700 dark:text-rose-300">{{ ($l['fetched_at'] ?? 0) ? 'Abruf gescheitert, die Fassung vom '.$blStamp($l['fetched_at']).' bleibt aktiv: '.$l['error'] : 'Abruf gescheitert: '.$l['error'].'. Das Panel versucht es alle 30 Minuten erneut.' }}</div>
                            @elseif (! empty($l['pending']))
                                <div class="text-xs text-gray-500">Wird geladen …</div>
                            @elseif ($l['fetched_at'] ?? 0)
                                <div class="text-xs text-gray-500">{{ Fmt::num($l['count'] ?? 0) }} Einträge, Stand {{ $blStamp($l['fetched_at']) }}</div>
                            @endif
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        @if (! empty($l['custom']))
                            <x-input.button type="button" size="sm" color="gray" aria-label="{{ $l['name'] }} löschen"
                                x-on:click="$store.confirm.ask({ title: 'Liste löschen?', button: 'Löschen', text: @js($l['name'].' wird entfernt, die Adressen daraus sind danach nicht mehr gesperrt.'), ok: () => $wire.deleteBlocklist(@js($l['id'])) })">
                                <x-icon name="trash" class="h-4 w-4" />
                            </x-input.button>
                        @endif
                        @if ($l['included_by'] ?? '')
                            <x-proxy.switch :on="true" :label="$l['name']" disabled class="opacity-50" />
                        @elseif (! $on && ($l['warning'] ?? ''))
                            <x-proxy.switch :on="false" :label="$l['name']"
                                x-on:click="$store.confirm.ask({ title: @js($l['name'].' einschalten?'), tone: 'blue', button: 'Einschalten', text: @js($l['warning']), ok: () => $wire.setBlocklist(@js($l['id']), true) })" />
                        @else
                            <x-proxy.switch :on="$on" :label="$l['name']" wire:click="setBlocklist({{ Js::from($l['id']) }}, {{ $on ? 'false' : 'true' }})" />
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-4 text-sm font-medium text-gray-900 dark:text-gray-100">Eigene Liste hinzufügen</div>
        <x-input.hilfe>Dafür braucht es kein Update: Jede über HTTPS erreichbare Liste mit IP-Adressen oder CIDR-Netzen funktioniert, auch als JSON oder CSV.</x-input.hilfe>
        <form wire:submit="addBlocklist" class="mt-2 grid grid-cols-1 items-end gap-3 md:grid-cols-[1fr_2fr_auto]">
            <x-field label="Name" for="bl-name"><x-input.text id="bl-name" maxlength="60" required wire:model="blName" placeholder="Meine Liste" class="mt-1 w-full" /></x-field>
            <x-field label="Adresse der Liste" for="bl-url"><x-input.text id="bl-url" inputmode="url" required autocomplete="off" wire:model="blUrl" placeholder="https://example.de/liste.txt" class="mt-1 w-full font-mono" /></x-field>
            <x-input.button type="submit" size="feld">Liste hinzufügen</x-input.button>
        </form>
    </x-panel>

    {{-- automatic IP ban --}}
    <x-panel class="m-3 overflow-x-auto">
        <div class="{{ $card }}">Automatische IP-Sperre</div>
        <p class="{{ $intro }}">Das Panel wertet die Zugriffe laufend aus und sperrt Adressen, die nach Schwachstellen suchen oder Passwörter durchprobieren, auf Port 80 und 443. Wer wiederkommt, bleibt länger draußen: siebenfache, dann dreißigfache Dauer. Interne Netze werden nie gesperrt. Anwendungen, die ein falsches Passwort mit Status 200 beantworten, lassen sich an Anmeldefehlern nicht erkennen.</p>
        @if ($d)
            @php $bs = $d['ban_status'] ?? null; @endphp
            <div class="mt-3 text-sm">
                @if ($bs['error'] ?? '')<div class="text-rose-700 dark:text-rose-300">{{ $bs['error'] }}</div>@endif
                <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot($d['guard']['enabled'] ? 'good' : 'none') }}"></span>
                    {{ $d['guard']['enabled'] ? 'Automatik aktiv, '.Fmt::plural($d['bans_total'], 'Adresse', 'Adressen').' gesperrt' : 'Automatik ausgeschaltet, '.Fmt::plural($d['bans_total'], 'Sperre', 'Sperren').' aktiv' }}</span>
                @if ($bs && ! ($bs['error'] ?? '') && ($bs['applied_at'] ?? 0))
                    <div class="text-xs text-gray-500">Bisher {{ Fmt::num($bs['dropped'] ?? 0) }} Pakete verworfen (Stand {{ Fmt::stamp($bs['applied_at']) }}).</div>
                @endif
            </div>

            @if ($d['bans'])
                <table class="mt-3 w-full text-left text-xs">
                    <thead class="text-gray-500"><tr><th class="py-1 pr-3">Adresse</th><th class="py-1 pr-3">Grund</th><th class="py-1 pr-3">Details</th><th class="py-1 pr-3">Seit</th><th class="py-1 pr-3">Bis</th><th class="py-1"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($d['bans'] as $b)
                            <tr wire:key="ban-{{ $b['ip'] }}">
                                <td class="whitespace-nowrap py-1.5 pr-3 font-mono"><span title="{{ Fmt::country($b['country'] ?? null) }}">{{ Fmt::flag($b['country'] ?? null) }}</span> {{ $b['ip'] }}</td>
                                <td class="py-1.5 pr-3">{{ Security::REASONS[$b['reason']] ?? $b['reason'] }}{{ ($b['strikes'] ?? 1) > 1 ? ' ('.$b['strikes'].'.)' : '' }}</td>
                                <td class="max-w-xs truncate py-1.5 pr-3" title="{{ $b['host'] ?? '' }}">{{ $b['detail'] ?? '' }}</td>
                                <td class="whitespace-nowrap py-1.5 pr-3">{{ Fmt::stamp($b['created_at']) }}</td>
                                <td class="whitespace-nowrap py-1.5 pr-3">{{ ($b['expires_at'] ?? 0) ? Fmt::stamp($b['expires_at']) : 'Dauerhaft' }}</td>
                                <td class="whitespace-nowrap py-1.5 text-right">
                                    <x-input.button type="button" size="sm" color="gray"
                                        x-on:click="$store.confirm.ask({ title: 'Sperre aufheben?', tone: 'blue', button: 'Aufheben', text: @js($b['ip'].' wird entsperrt. Macht die Adresse weiter, wird sie erneut gesperrt.'), ok: () => $wire.removeBan(@js($b['ip']), false) })">Aufheben</x-input.button>
                                    <x-input.button type="button" size="sm" color="gray"
                                        x-on:click="$store.confirm.ask({ title: 'Nie mehr sperren?', tone: 'blue', button: 'Nie mehr sperren', text: @js($b['ip'].' wird entsperrt und kommt auf die Liste „Nie sperren“.'), ok: () => $wire.removeBan(@js($b['ip']), true) })">Nie sperren</x-input.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($d['bans_total'] > count($d['bans']))
                    <p class="mt-1 text-xs text-gray-500">Die neuesten {{ count($d['bans']) }} von {{ Fmt::num($d['bans_total']) }}.</p>
                @endif
            @endif
        @endif

        <form wire:submit="addBan" class="mt-4 grid grid-cols-1 items-end gap-3 md:grid-cols-[2fr_1fr_auto]">
            <x-field label="IP von Hand sperren" for="ban-ip"><x-input.text id="ban-ip" wire:model="banIp" placeholder="203.0.113.7 oder 203.0.113.0/24" class="mt-1 w-full font-mono" /></x-field>
            <x-field label="Dauer" for="ban-h">
                <x-input.select id="ban-h" wire:model="banHours" class="mt-1">
                    <option value="24">24 Stunden</option><option value="168">7 Tage</option><option value="720">30 Tage</option><option value="0">Dauerhaft</option>
                </x-input.select>
            </x-field>
            <x-input.button type="submit" size="feld">Sperren</x-input.button>
        </form>

        <details class="mt-4" @if ($scanMatches !== null) open @endif>
            <summary class="cursor-pointer text-sm text-cerulean-700 dark:text-cerulean-300">Regeln und Ausnahmen</summary>
            <div class="mt-3">
                <x-input.checkbox label="Automatisch sperren" wire:model="guard.enabled" />
                <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach (Security::THRESHOLDS as $key => [$label, $hint, $min, $max])
                        <x-field :label="$label" for="g-{{ $key }}" :hilfe="$hint">
                            <x-input.text id="g-{{ $key }}" type="number" min="{{ $min }}" max="{{ $max }}" wire:model="guard.{{ $key }}" class="mt-1 w-32" />
                        </x-field>
                    @endforeach
                </div>
                <x-input.hilfe>0 schaltet eine Regel ab.</x-input.hilfe>
                <x-field label="Nie sperren" for="g-exempt" class="mt-3 max-w-xl" hilfe="Eine IP oder ein CIDR-Bereich pro Zeile, etwa dein Büro oder ein externer Monitoring-Dienst.">
                    <x-input.textarea id="g-exempt" rows="2" wire:model="guard.exempt" placeholder="203.0.113.7" class="mt-1" />
                </x-field>
                <x-input.checkbox label="An PK95 Core melden, höchstens einmal pro Stunde als Sammelnachricht" wire:model="guard.notify" />
                <div class="mt-3 flex gap-2">
                    <x-input.button type="button" wire:click="saveGuard">Speichern</x-input.button>
                    <x-input.button type="button" color="gray" wire:click="scan(false)" wire:loading.attr="disabled">Letzte 24 Stunden prüfen</x-input.button>
                </div>
            </div>
        </details>

        @if ($scanMatches !== null)
            @if (! $scanMatches)
                <p class="mt-3 text-sm text-gray-500">In den letzten 24 Stunden hätte keine weitere Adresse eine Regel ausgelöst.</p>
            @else
                <div class="mt-4 text-sm font-medium">{{ Fmt::plural(count($scanMatches), 'Adresse', 'Adressen') }} der letzten 24 Stunden</div>
                <table class="mt-2 w-full text-left text-xs">
                    <thead class="text-gray-500"><tr><th class="py-1 pr-3">Adresse</th><th class="py-1 pr-3">Grund</th><th class="py-1 pr-3">Details</th><th class="py-1">Host</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($scanMatches as $m)
                            <tr>
                                <td class="whitespace-nowrap py-1.5 pr-3 font-mono">{{ Fmt::flag($m['country'] ?? null) }} {{ $m['ip'] }}</td>
                                <td class="py-1.5 pr-3">{{ Security::REASONS[$m['reason']] ?? $m['reason'] }}</td>
                                <td class="max-w-xs truncate py-1.5 pr-3">{{ $m['detail'] ?? '' }}</td>
                                <td class="py-1.5">{{ $m['host'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mt-3 flex gap-2">
                    <x-input.button type="button" wire:click="scan(true)">Alle sperren</x-input.button>
                    <x-input.button type="button" color="gray" wire:click="discardScan">Verwerfen</x-input.button>
                </div>
            @endif
        @endif
    </x-panel>

    {{-- server signature --}}
    <x-panel class="m-3">
        <div class="{{ $card }}">Server-Kennung</div>
        <div class="mt-2">
            <x-input.checkbox label="Eingesetzte Software in Antworten ausblenden" wire:model="hideServer" wire:change="saveHideServer" />
        </div>
        <x-input.hilfe>Entfernt Server, Via, X-Powered-By und ähnliche Angaben für alle Hosts. Sicherheits-Header stellst du pro Host unter Hosts → Bearbeiten → Erweitert ein.</x-input.hilfe>
    </x-panel>
</div>
