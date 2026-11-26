@php
    use App\Modules\Proxy\Fmt;
    use App\Modules\Proxy\Waf;
    use App\Modules\Proxy\WebFirewall;
    use Illuminate\Support\Js;
    $input = 'rounded-lg border-gray-300 bg-white py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300';
    $chip = 'rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs text-gray-700 hover:border-rose-300 hover:bg-rose-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:border-rose-800 dark:hover:bg-rose-950';
    $badge = fn (string $action) => $action === 'blocked'
        ? 'bg-rose-100 text-rose-800 dark:bg-rose-900/60 dark:text-rose-100'
        : 'bg-amber-100 text-amber-900 dark:bg-amber-900/60 dark:text-amber-100';
    $label = 'text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $counts = fn (array $r) => Fmt::num((int) ($r['detected'] ?? 0)).' erkannt · '.Fmt::num((int) ($r['blocked'] ?? 0)).' blockiert';
@endphp

<div @if ($status) wire:poll.15s @endif>
    <x-page-header title="Web-Firewall">
        <x-slot:sub>
            @if ($status)
                Prüft Anfragen mit den OWASP-Regeln{{ $status['crs'] !== '' ? ' (CRS '.$status['crs'].')' : '' }}{{ $status['coraza'] !== '' ? ', Coraza '.$status['coraza'] : '' }}. Den Modus stellst du pro Host unter Hosts ein.
            @endif
        </x-slot:sub>
    </x-page-header>

    @if (! $status)
        <x-callout title="Keine Web-Firewall" ton="gelb" class="m-3" data-waf-unavailable>
            Diese Installation von PK95 Proxy hat keine Web-Firewall (ältere Version oder ohne Coraza).
        </x-callout>
    @else
        @foreach ($hostErrors as $e)
            <div class="m-3 rounded-xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-100" data-waf-host-error wire:key="werr-{{ $e['host_id'] ?? $loop->index }}">
                <span class="font-DINPro-bold">{{ $e['domain'] ?? 'Host '.($e['host_id'] ?? '?') }} läuft ohne Web-Firewall:</span> {{ $e['error'] ?? '' }}
            </div>
        @endforeach

        {{-- exclusion from a hit --}}
        @if ($exclusion !== null)
            <x-modal wire:key="waf-exclusion" submit="saveExclusion" size="md" save-label="Ausnahme anlegen">
                <x-slot:title>Ausnahme für {{ $exclusion['domain'] }}</x-slot:title>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Die Regel prüft auf diesem Host unter dem Pfad nicht mehr. Lege Ausnahmen nur für Treffer an, die sicher harmlos sind.</p>
                <div class="mt-3 grid grid-cols-1 gap-4">
                    <x-field label="Regel" for="ex-rule" feld="exclusion.rule_id" :required="true">
                        <x-input.text id="ex-rule" feld="exclusion.rule_id" wire:model="exclusion.rule_id" inputmode="numeric" class="mt-1 w-40 font-mono" />
                    </x-field>
                    <x-field label="Pfad beginnt mit" for="ex-path" feld="exclusion.path_prefix" hilfe="Leer = überall auf diesem Host.">
                        <x-input.text id="ex-path" feld="exclusion.path_prefix" wire:model="exclusion.path_prefix" class="mt-1 w-full font-mono" />
                    </x-field>
                    <x-field label="Kommentar" for="ex-comment" feld="exclusion.comment">
                        <x-input.text id="ex-comment" feld="exclusion.comment" wire:model="exclusion.comment" maxlength="200" placeholder="Zum Beispiel: Editor sendet HTML" class="mt-1 w-full" />
                    </x-field>
                </div>
            </x-modal>
        @endif

        {{-- summary --}}
        <x-panel class="m-3" data-waf-summary>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Übersicht</div>
                <div class="inline-flex rounded-lg border border-gray-200 p-0.5 dark:border-gray-700" role="group" aria-label="Zeitraum">
                    @foreach (WebFirewall::DAYS as $d => $l)
                        <button type="button" wire:click="period('{{ $d }}')" aria-pressed="{{ $days === (string) $d ? 'true' : 'false' }}"
                            @class(['rounded-md px-2.5 py-1 text-xs', 'bg-cerulean-600 text-white' => $days === (string) $d, 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $days !== (string) $d])>{{ $l }}</button>
                    @endforeach
                </div>
            </div>
            @if ($summaryError)
                <p class="mt-2 text-sm text-amber-800 dark:text-amber-300">{{ $summaryError }}</p>
            @elseif ($summary)
                <div class="mt-3 grid grid-cols-2 gap-3">
                    <div><div class="{{ $label }}">Erkannt</div><div class="text-2xl font-DINPro-bold tabular-nums text-gray-900 dark:text-gray-100" data-waf-detected>{{ Fmt::num((int) ($summary['total']['detected'] ?? 0)) }}</div></div>
                    <div><div class="{{ $label }}">Blockiert</div><div class="text-2xl font-DINPro-bold tabular-nums text-gray-900 dark:text-gray-100" data-waf-blocked>{{ Fmt::num((int) ($summary['total']['blocked'] ?? 0)) }}</div></div>
                </div>
                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                    @foreach ([['hosts', 'Hosts'], ['rules', 'Regeln'], ['ips', 'Adressen']] as [$key, $title])
                        <div class="min-w-0">
                            <div class="{{ $label }}">{{ $title }}</div>
                            @if (! ($summary[$key] ?? []))
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Keine Treffer.</p>
                            @else
                                <ul class="mt-1 space-y-1 text-sm" data-waf-top="{{ $key }}">
                                    @foreach (array_slice((array) $summary[$key], 0, 8) as $r)
                                        <li class="flex items-baseline justify-between gap-2">
                                            @switch($key)
                                                @case('hosts')
                                                    <button type="button" wire:click="filterBy('host', {{ Js::from((string) ($r['host'] ?? '')) }})" class="min-w-0 truncate text-left text-cerulean-700 hover:underline dark:text-cerulean-300">
                                                        {{ $r['host'] ?? '?' }}@if (isset(Waf::MODES[$r['mode'] ?? '']))<span class="ml-1 text-xs text-gray-500 dark:text-gray-400">{{ Waf::MODES[$r['mode']] }}</span>@endif
                                                    </button>
                                                    @break
                                                @case('rules')
                                                    <button type="button" wire:click="filterBy('rule', {{ Js::from((string) ($r['id'] ?? '')) }})" class="min-w-0 truncate text-left text-cerulean-700 hover:underline dark:text-cerulean-300" data-tip="{{ $r['msg'] ?? '' }}">
                                                        <span class="font-mono">{{ $r['id'] ?? '?' }}</span> <span class="text-gray-600 dark:text-gray-300">{{ $r['msg'] ?? '' }}</span>
                                                    </button>
                                                    @break
                                                @default
                                                    <button type="button" wire:click="filterBy('ip', {{ Js::from((string) ($r['ip'] ?? '')) }})" class="min-w-0 truncate text-left font-mono text-cerulean-700 hover:underline dark:text-cerulean-300">
                                                        {{ Fmt::flag($r['country'] ?? null) }} {{ $r['ip'] ?? '?' }}
                                                    </button>
                                            @endswitch
                                            <span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400" data-tip="{{ $counts($r) }}">{{ Fmt::num((int) ($r['detected'] ?? 0)) }} / <span class="text-rose-700 dark:text-rose-300">{{ Fmt::num((int) ($r['blocked'] ?? 0)) }}</span></span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </x-panel>

        {{-- filters --}}
        <div class="mx-3 mt-2 flex flex-wrap items-center gap-2">
            <x-input.combobox wire:model.live="host" label="Host" :options="$domains ? array_combine($domains, $domains) : []" placeholder="Alle Hosts" reset="Alle Hosts" :search="true" class="w-full sm:w-56" />
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
                    <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $filtered ? 'Keine passenden Treffer' : 'Noch keine Treffer' }}</div>
                    <p class="mt-2 text-sm text-gray-500">Hier erscheinen Anfragen, bei denen eine Regel angeschlagen hat.</p>
                </div>
            </x-panel>
        @else
            <x-panel class="m-3" polster="keins">
                <ul class="divide-y divide-gray-100 dark:divide-gray-700" data-waf-events>
                    @foreach ($rows as $hit)
                        @php $action = (string) ($hit['action'] ?? 'detected'); @endphp
                        <li class="px-4 py-3" wire:key="hit-{{ $hit['id'] ?? $loop->index }}" data-waf-hit="{{ $hit['id'] ?? '' }}">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $badge($action) }}">{{ Waf::ACTIONS[$action] ?? $action }}</span>
                                <span class="tabular-nums text-gray-500 dark:text-gray-400" data-tip="{{ Fmt::dateTime((int) ($hit['ts'] ?? 0)) }}">{{ Fmt::stamp((int) ($hit['ts'] ?? 0)) }}</span>
                                <button type="button" wire:click="filterBy('host', {{ Js::from((string) ($hit['host'] ?? '')) }})" class="font-medium text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $hit['host'] ?? '?' }}</button>
                                <button type="button" wire:click="filterBy('ip', {{ Js::from((string) ($hit['ip'] ?? '')) }})" class="font-mono text-xs text-gray-600 hover:underline dark:text-gray-300">{{ Fmt::flag($hit['country'] ?? null) }} {{ $hit['ip'] ?? '' }}</button>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ (int) ($hit['score'] ?? 0) }} Punkte</span>
                            </div>
                            <div class="mt-1 break-all font-mono text-xs text-gray-700 dark:text-gray-300">{{ $hit['method'] ?? '' }} {{ $hit['path'] ?? '' }}</div>
                            <ul class="mt-2 space-y-1">
                                @foreach ((array) ($hit['rules'] ?? []) as $r)
                                    <li class="flex flex-wrap items-start justify-between gap-2 rounded-lg bg-gray-50 px-3 py-1.5 text-xs dark:bg-gray-900/40" data-waf-rule="{{ $r['id'] ?? '' }}">
                                        <div class="min-w-0 flex-1">
                                            <button type="button" wire:click="filterBy('rule', {{ Js::from((string) ($r['id'] ?? '')) }})" class="font-mono text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $r['id'] ?? '?' }}</button>
                                            <span class="text-gray-700 dark:text-gray-200">{{ $r['msg'] ?? '' }}</span>
                                            @if (($r['target'] ?? '') !== '')<span class="block text-gray-500 dark:text-gray-400">in {{ $r['target'] }}</span>@endif
                                        </div>
                                        @if (($hit['host_id'] ?? 0) && Waf::excludable($r['id'] ?? ''))
                                            <button type="button" data-waf-exclude
                                                wire:click="newExclusion({{ (int) $hit['host_id'] }}, {{ Js::from((string) ($hit['host'] ?? '')) }}, {{ Js::from((string) $r['id']) }}, {{ Js::from((string) ($hit['path'] ?? '/')) }})"
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
    @endif
</div>
