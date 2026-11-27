@php
    use App\Modules\Proxy\Fmt;
    use App\Modules\Proxy\Waf;
    use App\Modules\Proxy\WebFirewall;
    use Illuminate\Support\Js;
    $input = 'rounded-lg border-gray-300 bg-white py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300';
    $chip = 'rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs text-gray-700 hover:border-rose-300 hover:bg-rose-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:border-rose-800 dark:hover:bg-rose-950';
    $badge = fn (string $action) => $action === 'block'
        ? 'bg-rose-100 text-rose-800 dark:bg-rose-900/60 dark:text-rose-100'
        : 'bg-amber-100 text-amber-900 dark:bg-amber-900/60 dark:text-amber-100';
    $label = 'text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $redBox = 'm-3 rounded-xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-100';
    $card = 'font-DINPro-bold text-gray-900 dark:text-gray-100';
    // the figures change by themselves, the forms of the other tabs must not be refreshed under the fingers
    $poll = $status && in_array($tab, ['uebersicht', 'protokoll'], true);
    $domainOf = $status ? collect($status['hosts'])->pluck('domain', 'host_id')->all() : [];
    $where = fn (array $e) => ($e['path_prefix'] ?? '') !== '' ? 'unter '.$e['path_prefix'] : 'überall';
@endphp

<div @if ($poll) wire:poll.15s @endif>
    <x-page-header title="Web-Firewall">
        <x-slot:sub>
            @if ($status)
                Prüft Anfragen mit den OWASP-Regeln{{ $status['crs'] !== '' ? ' (CRS '.$status['crs'].')' : '' }}{{ $status['coraza'] !== '' ? ', Coraza '.$status['coraza'] : '' }}. Den Modus stellst du pro Host unter Hosts ein.
            @endif
        </x-slot:sub>
    </x-page-header>

    @if (! $status)
        <x-callout title="Keine Web-Firewall" ton="gelb" class="m-3" data-waf-unavailable>
            Diese Installation von PK95 Proxy hat keine Web-Firewall (vor Version 1.8.0 oder Caddy ohne WAF-Modul).
        </x-callout>
    @else
        @if (! $status['rules_loaded'])
            <div class="{{ $redBox }}" data-waf-rules-error>
                <span class="font-DINPro-bold">Die Regeln sind nicht geladen, kein Host ist geschützt:</span> {{ $status['error'] !== '' ? $status['error'] : 'unbekannter Fehler' }}
            </div>
        @endif
        @foreach ($hostErrors as $hostId => $err)
            <div class="{{ $redBox }}" data-waf-host-error wire:key="werr-{{ $hostId }}">
                <span class="font-DINPro-bold">{{ $domainOf[$hostId] ?? $hostNames[(string) $hostId] ?? 'Host '.$hostId }} läuft ohne Web-Firewall:</span> {{ $err }}
            </div>
        @endforeach

        {{-- exception from a hit --}}
        @if ($exception !== null)
            <x-modal wire:key="waf-exception" submit="saveException" size="md" save-label="Ausnahme anlegen">
                <x-slot:title>Ausnahme für {{ $exception['domain'] }}</x-slot:title>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Die Regel prüft auf diesem Host unter dem Pfad nicht mehr. Lege Ausnahmen nur für Treffer an, die sicher harmlos sind.</p>
                <div class="mt-3 grid grid-cols-1 gap-4">
                    <x-field label="Regel" for="ex-rule" feld="exception.rule_id" :required="true">
                        <x-input.text id="ex-rule" feld="exception.rule_id" wire:model="exception.rule_id" inputmode="numeric" class="mt-1 w-40 font-mono" />
                    </x-field>
                    <x-field label="Pfad beginnt mit" for="ex-path" feld="exception.path_prefix" hilfe="Leer = für den ganzen Host.">
                        <x-input.text id="ex-path" feld="exception.path_prefix" wire:model="exception.path_prefix" class="mt-1 w-full font-mono" />
                    </x-field>
                    @if (($exception['target'] ?? '') !== '')
                        <div data-exception-target>
                            <x-input.checkbox wire:model="exception.use_target" :label="'Nur für den Parameter '.$exception['target']"
                                hilfe="Enger und sicherer: Die Regel prüft alle anderen Parameter weiter." />
                            <x-input.fehler feld="exception.target" />
                        </div>
                    @elseif (($exception['target_hint'] ?? '') !== '')
                        <p class="text-xs text-gray-500 dark:text-gray-400" data-exception-target-hint>Die Regel hat {{ $exception['target_hint'] }} getroffen. Dafür gibt es keine Ausnahme je Parameter, sie gilt für die ganze Regel.</p>
                    @endif
                    @if ($hasCentral)
                        <fieldset data-exception-scope>
                            <legend class="text-sm font-medium text-gray-700 dark:text-gray-300">Gilt für</legend>
                            <div class="mt-1 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-700 dark:text-gray-300">
                                <label class="inline-flex items-center gap-2"><input type="radio" value="host" wire:model="exception.scope" class="border-gray-300 text-cerulean-600 focus:ring-cerulean-500 dark:border-gray-600 dark:bg-gray-700">Nur für {{ $exception['domain'] }}</label>
                                <label class="inline-flex items-center gap-2"><input type="radio" value="all" wire:model="exception.scope" class="border-gray-300 text-cerulean-600 focus:ring-cerulean-500 dark:border-gray-600 dark:bg-gray-700">Für alle Hosts</label>
                            </div>
                        </fieldset>
                    @endif
                    <x-field label="Notiz" for="ex-note" feld="exception.note">
                        <x-input.text id="ex-note" feld="exception.note" wire:model="exception.note" maxlength="200" placeholder="Zum Beispiel: Editor sendet HTML" class="mt-1 w-full" />
                    </x-field>
                </div>
            </x-modal>
        @endif

        <x-tabs :tabs="WebFirewall::TABS" :active="$tab" label="Bereiche der Web-Firewall" class="mx-3 mt-2" data-waf-tabs />

        {{-- switching tabs: the same spinner as the page placeholder while the tab loads its data --}}
        <div wire:loading.flex wire:target="show" class="mx-3 mt-3 items-center gap-2 text-sm text-gray-600 dark:text-gray-300" role="status" data-tab-loading>
            <span class="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-cerulean-200 border-t-cerulean-600 dark:border-cerulean-900 dark:border-t-cerulean-400" aria-hidden="true"></span>
            Lade …
        </div>

        <div wire:loading.remove wire:target="show" data-tab-panel="{{ $tab }}">
            @switch($tab)
                @case('protokoll')
                    {{-- filters --}}
                    <div class="mx-3 mt-3 flex flex-wrap items-center gap-2">
                        <x-input.combobox wire:model.live="host" label="Host" :options="$hostNames" placeholder="Alle Hosts" reset="Alle Hosts" :search="true" class="w-full sm:w-56" />
                        <x-input.combobox wire:model.live="action" label="Aktion" :options="Waf::ACTIONS" placeholder="Erkannt und blockiert" reset="Erkannt und blockiert" class="w-full sm:w-52" />
                        <input type="search" wire:model.live.debounce.300ms="rule" placeholder="Regel-ID" aria-label="Regel-ID" inputmode="numeric" class="{{ $input }} w-28">
                        <input type="search" wire:model.live.debounce.300ms="ip" placeholder="IP-Adresse" aria-label="IP-Adresse" class="{{ $input }} w-40">
                        <input type="search" wire:model.live.debounce.300ms="q" placeholder="Pfad enthält …" aria-label="Pfad" class="{{ $input }} w-48">
                        @if ($country)
                            <button type="button" wire:click="filterBy('country', '')" data-tip="Filter entfernen" class="{{ $chip }}">{{ Fmt::flag($country) }} Land: {{ Fmt::country($country) }} ×</button>
                        @endif
                        @if ($filtered)
                            <button type="button" wire:click="clearFilters" class="text-xs text-cerulean-700 underline hover:no-underline dark:text-cerulean-300">Filter zurücksetzen</button>
                        @endif
                    </div>

                    @if ($loadError)
                        <x-callout title="Treffer konnten nicht geladen werden" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
                    @elseif (! $rows)
                        <x-panel class="m-3" polster="weit">
                            <div class="text-center">
                                <div class="{{ $card }}">{{ $filtered ? 'Keine passenden Treffer' : 'Noch keine Treffer' }}</div>
                                <p class="mt-2 text-sm text-gray-500">Hier erscheinen Anfragen, bei denen eine Regel angeschlagen hat.</p>
                            </div>
                        </x-panel>
                    @else
                        <x-panel class="m-3" polster="keins">
                            <ul class="divide-y divide-gray-100 dark:divide-gray-700" data-waf-events>
                                @foreach ($rows as $hit)
                                    @php $action = (string) ($hit['action'] ?? 'detect'); @endphp
                                    <li class="px-4 py-3" wire:key="hit-{{ $hit['id'] ?? $loop->index }}" data-waf-hit="{{ $hit['id'] ?? '' }}">
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                            <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $badge($action) }}">{{ Waf::ACTIONS[$action] ?? $action }}</span>
                                            <span class="tabular-nums text-gray-500 dark:text-gray-400" data-tip="{{ Fmt::dateTime((int) ($hit['ts'] ?? 0)) }}">{{ Fmt::stamp((int) ($hit['ts'] ?? 0)) }}</span>
                                            <button type="button" wire:click="filterBy('host', {{ Js::from((string) ($hit['host_id'] ?? '')) }})" class="font-medium text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $hit['domain'] ?? '?' }}</button>
                                            <button type="button" wire:click="filterBy('ip', {{ Js::from((string) ($hit['ip'] ?? '')) }})" class="font-mono text-xs text-gray-600 hover:underline dark:text-gray-300">{{ Fmt::flag($hit['country'] ?? null) }} {{ $hit['ip'] ?? '' }}</button>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ (int) ($hit['anomaly_score'] ?? 0) }} Punkte</span>
                                        </div>
                                        <div class="mt-1 break-all font-mono text-xs text-gray-700 dark:text-gray-300">{{ $hit['method'] ?? '' }} {{ $hit['path'] ?? '' }}</div>
                                        <ul class="mt-2 space-y-1">
                                            @foreach ((array) ($hit['rules'] ?? []) as $r)
                                                <li class="flex flex-wrap items-start justify-between gap-2 rounded-lg bg-gray-50 px-3 py-1.5 text-xs dark:bg-gray-900/40" data-waf-rule="{{ $r['id'] ?? '' }}">
                                                    <div class="min-w-0 flex-1">
                                                        <button type="button" wire:click="filterBy('rule', {{ Js::from((string) ($r['id'] ?? '')) }})" class="font-mono text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $r['id'] ?? '?' }}</button>
                                                        <span class="text-gray-700 dark:text-gray-200">{{ $r['msg'] ?? '' }}</span>
                                                        @if (($r['paranoia'] ?? 0) > 0)
                                                            @php $onlyDetected = ($hit['block_paranoia'] ?? 0) > 0 && $r['paranoia'] > $hit['block_paranoia']; @endphp
                                                            <span @class(['ml-1 rounded px-1 py-0.5 text-[11px]', 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => ! $onlyDetected,
                                                                'bg-sky-100 text-sky-800 dark:bg-sky-900/60 dark:text-sky-100' => $onlyDetected]) data-waf-rule-level="{{ $r['paranoia'] }}">Stufe {{ $r['paranoia'] }}{{ $onlyDetected ? ' · nur erkannt' : '' }}</span>
                                                        @endif
                                                        @if (($r['target'] ?? '') !== '')
                                                            <span class="block break-all text-gray-500 dark:text-gray-400">in {{ $r['target'] }}@if (($r['data'] ?? '') !== ''): <span class="font-mono">{{ $r['data'] }}</span>@endif</span>
                                                        @endif
                                                    </div>
                                                    @if (($hit['host_id'] ?? 0) && Waf::excludable($r['id'] ?? ''))
                                                        <button type="button" data-waf-except
                                                            wire:click="newException({{ (int) $hit['host_id'] }}, {{ Js::from((string) ($hit['domain'] ?? '')) }}, {{ Js::from((string) $r['id']) }}, {{ Js::from((string) ($hit['path'] ?? '/')) }}, {{ Js::from((string) ($r['target'] ?? '')) }})"
                                                            class="shrink-0 rounded-md border border-gray-200 bg-white px-2 py-1 text-gray-700 hover:bg-gray-100 max-md:min-h-11 max-md:px-3 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">Ausnahme anlegen</button>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </li>
                                @endforeach
                            </ul>
                        </x-panel>
                        @if (count($rows) >= $limit && $limit < 500)
                            <div class="m-3 text-center">
                                <x-input.button type="button" color="gray" wire:click="older">Ältere laden</x-input.button>
                            </div>
                        @endif
                    @endif
                    @break

                @case('ausnahmen')
                    @if ($hasCentral)
                        <x-panel class="m-3" data-waf-central-exceptions>
                            <div class="{{ $card }}">Für alle Hosts</div>
                            @if (! $centralExceptions)
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Keine. Du legst sie im Protokoll mit „Ausnahme anlegen“ → „Für alle Hosts“ an.</p>
                            @else
                                <ul class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-700 dark:border-gray-700">
                                    @foreach ($centralExceptions as $e)
                                        <li class="flex items-start justify-between gap-2 px-3 py-2 text-sm" wire:key="wcx-{{ $e['id'] }}">
                                            <div class="min-w-0">
                                                <span class="font-mono">Regel {{ $e['rule_id'] }}</span>
                                                <span class="text-gray-500 dark:text-gray-400">{{ $where($e) }}</span>
                                                @if (($e['target'] ?? '') !== '')<span class="font-mono text-xs text-gray-500 dark:text-gray-400">· nur {{ $e['target'] }}</span>@endif
                                                @if (($e['note'] ?? '') !== '')<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $e['note'] }}</span>@endif
                                            </div>
                                            <x-input.symbolknopf titel="Ausnahme löschen" ton="rot"
                                                x-on:click="$store.confirm.ask({ title: 'Ausnahme löschen?', text: {{ Js::from('Regel '.$e['rule_id'].' prüft dann auf allen Hosts wieder '.$where($e).'.') }}, button: 'Löschen', ok: () => $wire.deleteCentralException({{ Js::from((string) $e['id']) }}) })">
                                                <x-icon name="trash" class="h-4 w-4" />
                                            </x-input.symbolknopf>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </x-panel>
                    @endif

                    <x-panel class="m-3" data-waf-host-exceptions>
                        <div class="{{ $card }}">Pro Host</div>
                        @if ($hostExceptions === null)
                            {{-- too many hosts to load each: the host dialogs --}}
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Zu viele Hosts, um alle auf einmal zu laden. Die Ausnahmen stehen im Dialog des jeweiligen Hosts:</p>
                            <ul class="mt-2 flex flex-wrap gap-2 text-sm">
                                @foreach ($wafHosts as $h)
                                    <li><a href="{{ route('apps.page', [$installedApp, 'hosts']) }}?q={{ urlencode((string) ($h['domains'][0] ?? '')) }}" wire:navigate class="text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $h['domains'][0] ?? '#'.$h['id'] }}</a></li>
                                @endforeach
                            </ul>
                        @elseif (! $hostExceptions)
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Kein Host hat eigene Ausnahmen.</p>
                        @else
                            <div class="mt-2 overflow-x-auto">
                                <table class="w-full text-left text-sm" data-waf-host-exception-rows>
                                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                        <tr><th class="py-2 pr-3">Host</th><th class="py-2 pr-3">Regel</th><th class="py-2 pr-3">Pfad</th><th class="hidden py-2 pr-3 sm:table-cell">Parameter</th><th class="hidden py-2 pr-3 md:table-cell">Notiz</th><th class="py-2"><span class="sr-only">Löschen</span></th></tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                        @foreach ($hostExceptions as $e)
                                            <tr wire:key="whx-{{ $e['host_id'] }}-{{ $e['id'] }}">
                                                <td class="py-2 pr-3 align-top"><a href="{{ route('apps.page', [$installedApp, 'hosts']) }}?q={{ urlencode($e['domain']) }}" wire:navigate class="text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $e['domain'] }}</a></td>
                                                <td class="py-2 pr-3 align-top font-mono">{{ $e['rule_id'] }}</td>
                                                <td class="break-all py-2 pr-3 align-top font-mono text-xs">{{ ($e['path_prefix'] ?? '') !== '' ? $e['path_prefix'] : 'überall' }}</td>
                                                <td class="hidden py-2 pr-3 align-top font-mono text-xs sm:table-cell">{{ ($e['target'] ?? '') !== '' ? $e['target'] : '–' }}</td>
                                                <td class="hidden py-2 pr-3 align-top text-xs text-gray-500 md:table-cell dark:text-gray-400">{{ $e['note'] ?? '' }}</td>
                                                <td class="py-2 text-right align-top">
                                                    <x-input.symbolknopf titel="Ausnahme löschen" ton="rot"
                                                        x-on:click="$store.confirm.ask({ title: 'Ausnahme löschen?', text: {{ Js::from('Regel '.$e['rule_id'].' prüft auf '.$e['domain'].' dann wieder '.$where($e).'.') }}, button: 'Löschen', ok: () => $wire.deleteHostException({{ (int) $e['host_id'] }}, {{ Js::from((string) $e['id']) }}) })">
                                                        <x-icon name="trash" class="h-4 w-4" />
                                                    </x-input.symbolknopf>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-panel>
                    @break

                @case('einstellungen')
                    @if (! $v19)
                        <x-callout title="Keine zentralen Einstellungen" ton="gelb" class="m-3">Zentrale Einstellungen gibt es ab PK95 Proxy 1.9.0. Die Web-Firewall stellst du pro Host unter Hosts ein.</x-callout>
                    @else
                        {{-- PK95 Proxy 1.9.0: central settings, hosts take level, limits and groups from here --}}
                        <x-panel class="m-3" data-waf-settings>
                            <div class="{{ $card }}">{{ $hasCentral ? 'Zentrale Einstellungen' : 'Einstellungen' }}</div>
                            @if ($hasCentral)
                                <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Gelten für jeden Host mit „Zentrale Einstellungen verwenden“. Modus, Profile, Ausnahmen und eigene Netze stellst du weiter pro Host ein.</p>
                            @endif
                            <form wire:submit="saveSettings" class="mt-3 space-y-4">
                                @if ($hasCentral)
                                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <x-field label="Modus für neue Hosts" for="ws-newmode" feld="settings.new_host_mode" hilfe="Damit startet jeder neu angelegte Reverse Proxy.">
                                            <x-input.select id="ws-newmode" feld="settings.new_host_mode" wire:model="settings.new_host_mode" class="mt-1">
                                                @foreach (Waf::MODES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                            </x-input.select>
                                        </x-field>
                                    </div>
                                    @include('modules.proxy.partials.waf-tuning', ['m' => 'central', 't' => $central, 'groups' => $groups,
                                        'defaults' => $centralDefaults, 'suggested' => $centralSuggested, 'readonly' => false, 'idp' => 'wc'])
                                @endif
                                <x-input.checkbox wire:model="settings.use_internal_networks" label="Interne Netze (Zugriffsliste „Intern“) nicht prüfen"
                                    hilfe="Anfragen aus diesen Netzen prüft die Web-Firewall auf keinem Host. Ändern sich die Netze der Liste, gilt das sofort." />
                                <x-field label="Weitere Netze nicht prüfen" for="ws-bypass" feld="settings.bypass_cidrs"
                                    hilfe="Eines pro Zeile, für alle Hosts. Pro Host gibt es zusätzlich eigene Netze im Host-Dialog. „Nie sperren“ der IP-Sperre ist davon unabhängig.">
                                    <x-input.textarea id="ws-bypass" rows="3" feld="settings.bypass_cidrs" wire:model="settings.bypass_cidrs" placeholder="192.168.1.0/24" class="mt-1 font-mono" />
                                </x-field>
                                <div class="flex justify-end">
                                    <x-input.button type="submit" wire:loading.attr="disabled" wire:target="saveSettings">Speichern</x-input.button>
                                </div>
                            </form>
                        </x-panel>
                    @endif
                    @break

                @default
                    {{-- overview: figures and the top lists as compact cards; a click filters the log --}}
                    <div class="mx-3 mt-3 flex flex-wrap items-center justify-between gap-2">
                        <div class="{{ $card }}">{{ $host !== '' ? 'Übersicht: '.($hostNames[$host] ?? 'Host '.$host) : 'Übersicht' }}</div>
                        <div class="inline-flex rounded-lg border border-gray-200 bg-white p-0.5 dark:border-gray-700 dark:bg-gray-800" role="group" aria-label="Zeitraum">
                            @foreach (WebFirewall::DAYS as $d => $l)
                                <button type="button" wire:click="period('{{ $d }}')" aria-pressed="{{ $days === (string) $d ? 'true' : 'false' }}"
                                    @class(['rounded-md px-2.5 py-1 text-xs max-sm:min-h-9', 'bg-cerulean-600 text-white' => $days === (string) $d, 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $days !== (string) $d])>{{ $l }}</button>
                            @endforeach
                        </div>
                    </div>
                    @if ($statsError)
                        <x-callout title="Zahlen nicht verfügbar" ton="gelb" class="m-3">{{ $statsError }}</x-callout>
                    @elseif ($stats)
                        @php $byLevel = array_filter((array) ($stats['hits_by_paranoia'] ?? []), fn ($n) => (int) $n > 0); @endphp
                        <div class="m-3 grid grid-cols-2 gap-3 lg:grid-cols-4" data-waf-summary>
                            <x-panel polster="eng"><div class="{{ $label }}">Treffer</div><div class="text-2xl font-DINPro-bold tabular-nums text-gray-900 dark:text-gray-100" data-waf-hits>{{ Fmt::num((int) ($stats['hits'] ?? 0)) }}</div></x-panel>
                            <x-panel polster="eng"><div class="{{ $label }}">Blockiert</div><div class="text-2xl font-DINPro-bold tabular-nums text-gray-900 dark:text-gray-100" data-waf-blocks>{{ Fmt::num((int) ($stats['blocks'] ?? 0)) }}</div></x-panel>
                            @if ($byLevel)
                                <x-panel polster="eng" data-waf-levels>
                                    <div class="{{ $label }}">Treffer nach Stufe</div>
                                    <div class="mt-1 flex flex-wrap gap-x-3 text-sm tabular-nums text-gray-700 dark:text-gray-300">
                                        @foreach (['1', '2', '3', '4', '0'] as $lvl)
                                            @if (isset($byLevel[$lvl]))<span>{{ $lvl === '0' ? 'vor 1.9' : 'Stufe '.$lvl }}: {{ Fmt::num((int) $byLevel[$lvl]) }}</span>@endif
                                        @endforeach
                                    </div>
                                </x-panel>
                            @endif
                            @if ($v19)
                                <x-panel polster="eng" data-waf-bypassed>
                                    <div class="{{ $label }}">Heute umgangen</div>
                                    <div class="text-2xl font-DINPro-bold tabular-nums text-gray-900 dark:text-gray-100">{{ Fmt::num($bypassedToday) }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">aus eigenen Netzen</div>
                                </x-panel>
                            @endif
                        </div>
                        <div class="m-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                            @foreach ([['top_rules', 'Häufigste Regeln', 'rule', 'rule_id'], ['top_hosts', 'Häufigste Hosts', 'host', 'host_id']] as [$key, $title, $filter, $idKey])
                                <x-panel polster="eng" class="min-w-0">
                                    <div class="{{ $label }}">{{ $title }}</div>
                                    @if (! ($stats[$key] ?? []))
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Keine Treffer.</p>
                                    @else
                                        <ul class="mt-1 space-y-1 text-sm" data-waf-top="{{ $filter === 'rule' ? 'rules' : 'hosts' }}">
                                            @foreach (array_slice((array) $stats[$key], 0, 8) as $r)
                                                <li class="flex items-baseline justify-between gap-2">
                                                    <button type="button" wire:click="filterBy('{{ $filter }}', {{ Js::from((string) ($r[$idKey] ?? '')) }})" data-tip="Im Protokoll zeigen"
                                                        class="min-w-0 truncate text-left text-cerulean-700 hover:underline max-sm:min-h-9 dark:text-cerulean-300">
                                                        @if ($filter === 'rule')
                                                            <span class="font-mono">{{ $r['rule_id'] ?? '?' }}</span> <span class="text-gray-600 dark:text-gray-300">{{ $r['msg'] ?? '' }}</span>
                                                        @else
                                                            {{ $r['domain'] ?? '?' }}
                                                        @endif
                                                    </button>
                                                    <span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ Fmt::num((int) ($r['count'] ?? 0)) }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </x-panel>
                            @endforeach
                        </div>
                    @endif
            @endswitch
        </div>
    @endif
</div>
