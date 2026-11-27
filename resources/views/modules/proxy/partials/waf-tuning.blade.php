{{--
    The six settings of the web firewall a host takes from the central configuration (PK95 Proxy
    1.9.0): block level and, in „Erweitert“, detection level, threshold, body limit, rule groups and
    limits. One form for the host dialog and „Zentrale Einstellungen“.
      $m         Livewire property: waf (host), wafEffective (host inherits: shown, not editable), central
      $t         its form state (Waf::tuningForm)
      $groups    rule groups (/api/waf/groups)
      $defaults  CRS defaults of the limits, null = unknown (a new host: no limits yet)
      $suggested suggested number limits
      $readonly  greyed out, nothing to change
      $idp       prefix of the element ids
--}}
@php
    use App\Modules\Proxy\Fmt;
    use App\Modules\Proxy\Waf;
    $readonly ??= false;
    // „Erweitert“ is closed unless a field inside has an error (the key renews the Alpine state then)
    $inside = ['detection_paranoia', 'anomaly_threshold', 'body_limit_kb', 'disabled_groups', 'limits'];
    $advErr = ! $readonly && collect($errors->keys())->contains(fn ($k) => in_array(explode('.', substr($k, strlen($m) + 1))[0], $inside, true) && str_starts_with($k, $m.'.'));
    $adjusted = Waf::adjusted($t, $defaults);
    $box = 'mt-1 rounded border-gray-300 text-cerulean-600 focus:ring-cerulean-500 disabled:opacity-60 dark:border-gray-600 dark:bg-gray-700';
    $groupsBy = collect($groups)->groupBy('kind');
    $level = (int) ($t['paranoia'] ?? 1);
@endphp
<div @class(['opacity-70' => $readonly]) data-waf-tuning="{{ $m }}">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-field label="Strenge beim Blockieren" :for="$idp.'-paranoia'" :feld="$m.'.paranoia'"
            hilfe="Höhere Stufen finden mehr, melden aber auch öfter harmlose Anfragen. Ab Stufe 3 brauchen die meisten Anwendungen Ausnahmen.">
            <x-input.select :id="$idp.'-paranoia'" :feld="$m.'.paranoia'" wire:model.live="{{ $m }}.paranoia" class="mt-1" :disabled="$readonly">
                @foreach (Waf::PARANOIA_19 as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
            </x-input.select>
        </x-field>
    </div>

    <div x-data="{ open: @js($advErr) }" wire:key="{{ $idp }}-advanced-{{ $advErr ? 'error' : 'ok' }}" data-waf-advanced
        class="mt-3 rounded-lg border border-gray-200 dark:border-gray-700">
        <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-controls="{{ $idp }}-advanced"
            class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/40 max-md:min-h-11">
            <span>Erweitert @if ($adjusted)<span class="font-normal text-cerulean-700 dark:text-cerulean-300" data-waf-adjusted>· {{ $adjusted }} angepasst</span>@endif</span>
            <x-icon name="chevron-down" class="h-4 w-4 shrink-0 transition-transform" ::class="open && 'rotate-180'" />
        </button>
        <div id="{{ $idp }}-advanced" x-show="open" x-cloak class="space-y-5 border-t border-gray-200 px-3 py-3 dark:border-gray-700">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-field label="Zusätzlich nur erkennen bis" :for="$idp.'-detect'" :feld="$m.'.detection_paranoia'" hilfe="Regeln oberhalb der Blockstufe protokollieren nur. So siehst du, was eine strengere Stufe treffen würde.">
                    <x-input.select :id="$idp.'-detect'" :feld="$m.'.detection_paranoia'" wire:model="{{ $m }}.detection_paranoia" class="mt-1" :disabled="$readonly">
                        @foreach (Waf::PARANOIA_19 as $v => $l)
                            @continue($v < $level)
                            <option value="{{ $v }}">{{ $v === $level ? 'Stufe '.$v.' (wie Blockieren)' : 'Stufe '.$v }}</option>
                        @endforeach
                    </x-input.select>
                </x-field>
                <x-field label="Schwelle" :for="$idp.'-threshold'" :feld="$m.'.anomaly_threshold'" hilfe="Punktzahl ab der eine Anfrage als Angriff gilt, 2 bis 100. Standard 5.">
                    <x-input.text :id="$idp.'-threshold'" type="number" min="2" max="100" :feld="$m.'.anomaly_threshold'" wire:model="{{ $m }}.anomaly_threshold" class="mt-1 w-32" :disabled="$readonly" />
                </x-field>
                <x-field label="Body prüfen bis (KB)" :for="$idp.'-body'" :feld="$m.'.body_limit_kb'" hilfe="0 bis 8192, 0 = Body nicht prüfen. Standard 1024.">
                    <x-input.text :id="$idp.'-body'" type="number" min="0" max="8192" :feld="$m.'.body_limit_kb'" wire:model="{{ $m }}.body_limit_kb" class="mt-1 w-32" :disabled="$readonly" />
                </x-field>
            </div>

            @if ($groups)
                <x-field label="Regelgruppen abschalten" :feld="$m.'.disabled_groups'" hilfe="Nur was es sicher nicht gibt, etwa Java bei PHP-Anwendungen. Eine Angriffsart abzuschalten schwächt den Schutz deutlich.">
                    <div class="mt-1 grid grid-cols-1 gap-x-6 gap-y-3 md:grid-cols-2" data-waf-groups>
                        @foreach (['technology' => 'Technik', 'attack' => 'Angriffsart'] as $kindKey => $kindLabel)
                            @continue(! $groupsBy->has($kindKey))
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $kindLabel }}</div>
                                <div class="mt-1 flex flex-col gap-1">
                                    @foreach ($groupsBy[$kindKey] as $g)
                                        <label class="inline-flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300" wire:key="{{ $idp }}-g-{{ $g['id'] }}">
                                            <input type="checkbox" value="{{ $g['id'] }}" wire:model.live="{{ $m }}.disabled_groups" class="{{ $box }}" @disabled($readonly)>
                                            <span>{{ $g['label'] ?? $g['id'] }}@if (($g['description'] ?? '') !== '')<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $g['description'] }}</span>@endif</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-field>
            @endif

            <div data-waf-limits>
                <div class="text-sm font-medium text-gray-700 dark:text-gray-300">Grenzwerte und Erlaubtes</div>
                @if ($defaults === null)
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Grenzwerte stellst du nach dem Anlegen des Hosts ein.</p>
                @else
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Vorbelegt mit dem Standard der OWASP-Regeln. Was dem Standard entspricht, folgt auch künftigen Änderungen daran.</p>
                    <x-field label="Erlaubte Methoden" :feld="$m.'.limits.allowed_methods'" class="mt-3">
                        <div class="mt-1 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4 md:grid-cols-6" data-waf-methods>
                            @foreach (Waf::METHODS as $meth)
                                <label class="inline-flex items-center gap-2 font-mono text-xs text-gray-700 dark:text-gray-300">
                                    <input type="checkbox" value="{{ $meth }}" wire:model="{{ $m }}.limits.allowed_methods" @disabled($readonly)
                                        class="rounded border-gray-300 text-cerulean-600 focus:ring-cerulean-500 disabled:opacity-60 dark:border-gray-600 dark:bg-gray-700">{{ $meth }}
                                </label>
                            @endforeach
                        </div>
                    </x-field>
                    <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-field label="Erlaubte Content-Types" :for="$idp.'-ctypes'" :feld="$m.'.limits.allowed_content_types'" hilfe="Einer pro Zeile, ohne Parameter. Leer = Standard.">
                            <x-input.textarea :id="$idp.'-ctypes'" rows="4" :feld="$m.'.limits.allowed_content_types'" wire:model="{{ $m }}.limits.allowed_content_types" class="mt-1 font-mono text-xs" :disabled="$readonly" />
                        </x-field>
                        <x-field label="Verbotene Dateiendungen" :for="$idp.'-ext'" :feld="$m.'.limits.restricted_extensions'" hilfe="Eine pro Zeile, etwa .bak. Ganz leer = keine verboten.">
                            <x-input.textarea :id="$idp.'-ext'" rows="4" :feld="$m.'.limits.restricted_extensions'" wire:model="{{ $m }}.limits.restricted_extensions" class="mt-1 font-mono text-xs" :disabled="$readonly" />
                        </x-field>
                    </div>
                    <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                        @foreach (Waf::LIMIT_NUMBERS as $k => [$label, $unit])
                            <x-field :label="$label" :for="$idp.'-'.$k" :feld="$m.'.limits.'.$k"
                                :hilfe="$unit.'. Leer = kein Limit (Standard)'.(isset($suggested[$k]) ? ', Vorschlag: '.Fmt::num((int) $suggested[$k]) : '').'.'">
                                <x-input.text :id="$idp.'-'.$k" type="number" min="1" :feld="$m.'.limits.'.$k" wire:model="{{ $m }}.limits.{{ $k }}" placeholder="kein Limit" class="mt-1 w-full" :disabled="$readonly" />
                            </x-field>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
