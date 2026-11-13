@php
    $status = [
        'up' => ['Online', 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100', 'bg-green-500'],
        'warn' => ['Warnung', 'bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100', 'bg-amber-500'],
        'down' => ['Offline', 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-100', 'bg-rose-500'],
        'pending' => ['Ausstehend', 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200', 'bg-gray-400'],
        'paused' => ['Pausiert', 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400', 'bg-gray-300'],
    ];
    $ms = fn ($v) => $v === null ? '–' : $v.' ms';
    $pct = fn ($v) => $v === null ? '–' : number_format($v, $v >= 99.995 ? 0 : 2, ',', '.').' %';
    $ago = fn ($v) => $v ? \Illuminate\Support\Carbon::createFromTimestampMs($v)->diffForHumans() : 'noch nie';
@endphp

<div wire:poll.15s>
    <x-page-header title="Monitore">
        <x-slot:sub>
            {{ $total }} Monitore · {{ $counts['up'] ?? 0 }} online · {{ $counts['down'] ?? 0 }} offline
            @if ($counts['warn'] ?? 0) · {{ $counts['warn'] }} mit Warnung @endif
            @if ($counts['paused'] ?? 0) · {{ $counts['paused'] }} pausiert @endif
        </x-slot:sub>
        <label class="relative">
            <span class="sr-only">Monitore durchsuchen</span>
            <input type="search" wire:model.live.debounce.250ms="search" placeholder="Suche"
                class="w-56 rounded-lg border-gray-300 bg-white py-2 pl-3 pr-3 text-sm text-gray-900 focus:border-cerulean-500 focus:ring-cerulean-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
        </label>
        @if ($editing === null)
            <x-input.button type="button" wire:click="create"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Monitor anlegen</x-input.button>
        @endif
    </x-page-header>

    @if ($loadError)
        <x-callout title="PK95 Uptime antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    {{-- create / edit --}}
    @if ($editing !== null)
        <x-panel class="m-3" wire:key="monitor-form">
            <form wire:submit="save">
                <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">{{ $editing === 'new' ? 'Neuer Monitor' : 'Monitor bearbeiten' }}</div>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <x-field label="Name" for="m-name" feld="form.name" required>
                        <x-input.text id="m-name" feld="form.name" wire:model="form.name" class="mt-1 w-full" />
                    </x-field>
                    <x-field label="Gruppe" for="m-group" feld="form.group" hilfe="Leer = ohne Gruppe">
                        <x-input.text id="m-group" feld="form.group" wire:model="form.group" class="mt-1 w-full" />
                    </x-field>
                    <x-field label="Typ" for="m-type" feld="form.type">
                        <x-input.select id="m-type" feld="form.type" wire:model.live="form.type" class="mt-1" :disabled="$editing !== 'new'">
                            @foreach ($types as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </x-input.select>
                    </x-field>
                </div>

                <x-form-section title="Ziel" />
                <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-3">
                    @switch($form['type'] ?? 'http')
                        @case('http')
                            <x-field label="URL" for="m-url" feld="form.url" class="md:col-span-2" required>
                                <x-input.text id="m-url" feld="form.url" wire:model="form.url" class="mt-1 w-full font-mono" />
                            </x-field>
                            <x-field label="Methode" for="m-method" feld="form.method">
                                <x-input.select id="m-method" wire:model="form.method" class="mt-1"><option>GET</option><option>HEAD</option></x-input.select>
                            </x-field>
                            <x-field label="Erwartete Statuscodes" for="m-acc" feld="form.acceptedStatus" hilfe="z. B. 200-299,301">
                                <x-input.text id="m-acc" feld="form.acceptedStatus" wire:model="form.acceptedStatus" class="mt-1 w-full font-mono" />
                            </x-field>
                            <x-field label="Suchwort" for="m-kw" feld="form.keyword" hilfe="Optional: muss in der Antwort vorkommen">
                                <x-input.text id="m-kw" feld="form.keyword" wire:model="form.keyword" class="mt-1 w-full" />
                            </x-field>
                            <x-field label="Zertifikatswarnung (Tage)" for="m-cwd" feld="form.certWarnDays" hilfe="0 = aus">
                                <x-input.text id="m-cwd" type="number" feld="form.certWarnDays" wire:model="form.certWarnDays" class="mt-1 w-full" />
                            </x-field>
                            <div class="flex flex-col gap-1 md:col-span-3">
                                <x-input.checkbox label="Weiterleitungen folgen" wire:model="form.followRedirects" />
                                <x-input.checkbox label="Suchwort darf NICHT vorkommen" wire:model="form.keywordInverted" />
                                <x-input.checkbox label="Ungültige Zertifikate akzeptieren" wire:model="form.ignoreTlsErrors" />
                            </div>
                            @break
                        @case('push')
                            <x-field label="Kulanzzeit (Sekunden)" for="m-grace" feld="form.graceSeconds" hilfe="Zusätzlich zum Intervall, bevor der Monitor als ausgefallen gilt">
                                <x-input.text id="m-grace" type="number" feld="form.graceSeconds" wire:model="form.graceSeconds" class="mt-1 w-full" />
                            </x-field>
                            @break
                        @default
                            <x-field label="Host" for="m-host" feld="form.host" required>
                                <x-input.text id="m-host" feld="form.host" wire:model="form.host" class="mt-1 w-full font-mono" />
                            </x-field>
                            @if (in_array($form['type'], ['tcp', 'certificate'], true))
                                <x-field label="Port" for="m-port" feld="form.port">
                                    <x-input.text id="m-port" type="number" feld="form.port" wire:model="form.port" class="mt-1 w-full" />
                                </x-field>
                            @endif
                            @if ($form['type'] === 'certificate')
                                <x-field label="Warnen ab (Tage)" for="m-wd" feld="form.warnDays">
                                    <x-input.text id="m-wd" type="number" feld="form.warnDays" wire:model="form.warnDays" class="mt-1 w-full" />
                                </x-field>
                            @endif
                            @if ($form['type'] === 'dns')
                                <x-field label="Eintragstyp" for="m-rt" feld="form.recordType">
                                    <x-input.select id="m-rt" wire:model="form.recordType" class="mt-1">
                                        @foreach (['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS'] as $rt)<option>{{ $rt }}</option>@endforeach
                                    </x-input.select>
                                </x-field>
                                <x-field label="Erwarteter Wert" for="m-exp" feld="form.expected" hilfe="Optional">
                                    <x-input.text id="m-exp" feld="form.expected" wire:model="form.expected" class="mt-1 w-full font-mono" />
                                </x-field>
                                <x-field label="Resolver" for="m-res" feld="form.resolver" hilfe="Optional, IP-Adresse">
                                    <x-input.text id="m-res" feld="form.resolver" wire:model="form.resolver" class="mt-1 w-full font-mono" />
                                </x-field>
                            @endif
                    @endswitch
                </div>

                <x-form-section title="Prüfung" />
                <div class="mt-3 grid grid-cols-2 gap-4 md:grid-cols-4">
                    <x-field label="Intervall (s)" for="m-int" feld="form.intervalSeconds">
                        <x-input.text id="m-int" type="number" feld="form.intervalSeconds" wire:model="form.intervalSeconds" class="mt-1 w-full" />
                    </x-field>
                    <x-field label="Zeitlimit (s)" for="m-to" feld="form.timeoutSeconds">
                        <x-input.text id="m-to" type="number" feld="form.timeoutSeconds" wire:model="form.timeoutSeconds" class="mt-1 w-full" />
                    </x-field>
                    <x-field label="Wiederholungen" for="m-ret" feld="form.retries">
                        <x-input.text id="m-ret" type="number" feld="form.retries" wire:model="form.retries" class="mt-1 w-full" />
                    </x-field>
                    <x-field label="Wiederholen nach (s)" for="m-ri" feld="form.retryIntervalSeconds">
                        <x-input.text id="m-ri" type="number" feld="form.retryIntervalSeconds" wire:model="form.retryIntervalSeconds" class="mt-1 w-full" />
                    </x-field>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <x-input.button type="button" color="gray" wire:click="cancel">Abbrechen</x-input.button>
                    <x-input.button wire:loading.attr="disabled">Speichern</x-input.button>
                </div>
            </form>
        </x-panel>
    @endif

    @if (! $loadError && $total === 0 && $editing === null)
        <x-emptystate message="{{ $search !== '' ? 'Kein Monitor passt zur Suche.' : 'Noch keine Monitore. Lege den ersten an.' }}" />
    @endif

    {{-- list, grouped --}}
    @foreach ($groups as $group => $monitors)
        <div class="m-3">
            @if ($group !== '')
                <div class="mb-2 px-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $group }}</div>
            @endif
            <x-panel polster="keins" class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($monitors as $m)
                    @php([$label, $badge, $dot] = $status[$m['status']] ?? $status['pending'])
                    <div wire:key="mon-{{ $m['id'] }}">
                        <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                            <button type="button" wire:click="toggle('{{ $m['id'] }}')" class="flex min-w-0 flex-1 items-center gap-3 text-left">
                                <span class="inline-flex shrink-0 items-center rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $badge }}">{{ $label }}</span>
                                <span class="min-w-0">
                                    <span class="block truncate font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $m['name'] }}</span>
                                    <span class="block truncate font-mono text-xs text-gray-500 dark:text-gray-400">{{ $types[$m['type']] ?? $m['type'] }} · {{ $m['target'] }}</span>
                                </span>
                            </button>
                            @if ($strip = \App\Modules\Uptime\Bars::strip($m))
                                {{-- last 24 h, oldest left; below lg its own line under the row, on phones the last 12 h --}}
                                <span class="flex items-end gap-px max-lg:order-last max-lg:basis-full" role="img" aria-label="{{ $strip['label'] }}" data-bars24h>
                                    @foreach ($strip['bars'] as $bar)
                                        <span @class(['h-5 w-1 shrink-0 rounded-sm', $bar['class'], 'max-sm:hidden' => $loop->index < intdiv($loop->count, 2)]) data-tip="{{ $bar['title'] }}"></span>
                                    @endforeach
                                </span>
                            @else
                                {{-- older PK95 Uptime without bars24h: latest checks, oldest left --}}
                                <span class="hidden items-end gap-px lg:flex" aria-hidden="true">
                                    @foreach (array_slice($m['beats'] ?? [], -30) as $beat)
                                        <span class="h-5 w-1.5 rounded-sm {{ ($status[$beat['status']] ?? $status['pending'])[2] }}" data-tip="{{ $beat['message'] ?? 'OK' }}"></span>
                                    @endforeach
                                </span>
                            @endif
                            <span class="w-24 text-right text-sm text-gray-700 dark:text-gray-200" data-tip="Verfügbarkeit 24 h"><span class="sr-only">Verfügbarkeit 24 h: </span>{{ $pct($m['uptime24h']) }}</span>
                            <span class="w-20 text-right text-sm text-gray-500 dark:text-gray-400" data-tip="Letzte Antwortzeit"><span class="sr-only">Letzte Antwortzeit: </span>{{ $ms($m['lastLatencyMs']) }}</span>
                            <span class="flex shrink-0 gap-1">
                                @if ($m['type'] !== 'push' && $m['status'] !== 'paused')
                                    <x-input.symbolknopf wire:click="checkNow('{{ $m['id'] }}')" titel="Jetzt prüfen"><x-icon name="refresh" class="h-4 w-4" /></x-input.symbolknopf>
                                @endif
                                @if ($m['status'] === 'paused')
                                    <x-input.symbolknopf wire:click="resume('{{ $m['id'] }}')" titel="Fortsetzen"><x-icon name="play" class="h-4 w-4" /></x-input.symbolknopf>
                                @else
                                    <x-input.symbolknopf wire:click="pause('{{ $m['id'] }}')" titel="Pausieren"><x-icon name="stop" class="h-4 w-4" /></x-input.symbolknopf>
                                @endif
                                <x-input.symbolknopf wire:click="edit('{{ $m['id'] }}')" titel="Bearbeiten"><x-icon name="pencil" class="h-4 w-4" /></x-input.symbolknopf>
                                <x-input.symbolknopf titel="Löschen"
                                    x-on:click="$store.confirm.ask({ title: {{ \Illuminate\Support\Js::from('Monitor „'.$m['name'].'“ löschen?') }}, text: 'Verlauf und Vorfälle des Monitors gehen verloren.', button: 'Löschen', ok: () => $wire.delete('{{ $m['id'] }}') })">
                                    <x-icon name="trash" class="h-4 w-4" />
                                </x-input.symbolknopf>
                            </span>
                        </div>

                        @if ($expanded === $m['id'] && $detail)
                            <div class="grid grid-cols-1 gap-4 bg-gray-50 px-4 py-4 text-sm md:grid-cols-2 dark:bg-gray-900/40">
                                <table class="w-full">
                                    <x-kv label="Status seit" :value="\App\Http\Support\Format::fromMs($detail['statusSince'])->format('d.m.Y H:i')" />
                                    <x-kv label="Letzte Prüfung" :value="$ago($detail['lastCheckAt'])" />
                                    <x-kv label="Meldung" :value="$detail['lastMessage']" />
                                    <x-kv label="Verfügbarkeit" :value="'24 h '.$pct($detail['uptime24h']).' · 7 T '.$pct($detail['uptime7d']).' · 30 T '.$pct($detail['uptime30d'])" />
                                    <x-kv label="Ø Antwortzeit 24 h" :value="$ms($detail['avgLatency24hMs'])" />
                                    <x-kv label="Intervall" :value="$detail['intervalSeconds'].' s, Zeitlimit '.$detail['timeoutSeconds'].' s, '.$detail['retries'].' Wiederholungen'" />
                                    @if ($detail['certExpiresAt'])
                                        <x-kv label="Zertifikat bis" :value="\App\Http\Support\Format::fromMs($detail['certExpiresAt'])->format('d.m.Y')" />
                                    @endif
                                    @if ($detail['pushUrl'])
                                        <x-kv label="Push-URL" :value="$detail['pushUrl']" mono />
                                    @endif
                                </table>
                                <div>
                                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Letzte Vorfälle</div>
                                    @forelse ($incidents as $incident)
                                        <div class="flex gap-3 py-1">
                                            <span class="shrink-0 font-mono text-xs text-gray-500 dark:text-gray-400">{{ \App\Http\Support\Format::fromMs($incident['startedAt'])->format('d.m. H:i') }}</span>
                                            <span class="text-gray-700 dark:text-gray-200">
                                                {{ $incident['cause'] }}
                                                <span class="text-gray-500 dark:text-gray-400">
                                                    – {{ $incident['resolvedAt'] ? 'behoben nach '.\Illuminate\Support\Carbon::createFromTimestampMs($incident['resolvedAt'])->diffForHumans(\Illuminate\Support\Carbon::createFromTimestampMs($incident['startedAt']), true) : 'läuft noch' }}
                                                </span>
                                            </span>
                                        </div>
                                    @empty
                                        <div class="text-gray-500 dark:text-gray-400">Keine Vorfälle.</div>
                                    @endforelse
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </x-panel>
        </div>
    @endforeach
</div>
