{{--
    GPO settings dialog: tabs Computer / Benutzer. Settings come from the catalog, grouped by
    its "group" into sections that open when something in them is configured; one compact row
    per setting with a status badge, a click opens its editor. The Group Policy Preferences
    lists (Gpp\Kinds, e.g. drive maps) are the last sections of their tab, like the GPMC:
    configuration → preferences. The footer counts the changes; "Speichern" sends only those.
--}}
@php
    use App\Modules\Ad\Gpos;
    $settingsGpo = collect($gpos)->firstWhere('id', $settingsFor);
    $needle = mb_strtolower(trim($settingsSearch));
    $filtering = $needle !== '' || $onlyConfigured;
    $filterKey = substr(md5($needle.'|'.(int) $onlyConfigured), 0, 8);
    $configured = fn ($item) => Gpos::isConfigured($item, $settings[$item['key']] ?? null);
    $counts = [];
    foreach (['computer', 'user'] as $scope) {
        $counts[$scope] = $catalog->get($scope, collect())->flatten(1)->filter($configured)->count();
    }
    $changes = $settingChanges + $gppChangeCount;
    $tabs = [
        // a tab counts its configured settings and the rows of its preferences lists
        'computer' => ['Computer', $counts['computer'] + $this->gppRowCount('computer')],
        'user' => ['Benutzer', $counts['user'] + $this->gppRowCount('user')],
    ];
    $tones = [
        'gray' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
        'green' => 'bg-green-100 text-green-800 dark:bg-green-900/60 dark:text-green-100',
        'red' => 'bg-red-100 text-red-800 dark:bg-red-900/60 dark:text-red-100',
        'blue' => 'bg-cerulean-100 text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-100',
    ];
    $segments = [
        '' => ['Nicht konfiguriert', 'peer-checked:bg-gray-200 peer-checked:text-gray-900 dark:peer-checked:bg-gray-600 dark:peer-checked:text-white'],
        '1' => ['Aktiviert', 'peer-checked:bg-green-600 peer-checked:text-white'],
        '0' => ['Deaktiviert', 'peer-checked:bg-red-600 peer-checked:text-white'],
    ];
    $badge = 'inline-flex shrink-0 items-center whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-medium';
    $otherCount = (int) ($otherSettings['computer'] ?? 0) + (int) ($otherSettings['user'] ?? 0);
@endphp

<x-modal wire:key="gpo-settings" submit="saveSettings" size="2xl">
    <x-slot:title>Einstellungen von {{ $settingsGpo['name'] ?? '' }}</x-slot:title>
    <x-slot:actions>
        <span @class(['text-sm', 'font-DINPro-bold text-cerulean-700 dark:text-cerulean-300' => $changes > 0, 'text-gray-500 max-sm:hidden dark:text-gray-400' => $changes === 0]) data-change-count aria-live="polite">
            {{ $changes === 0 ? 'Keine Änderungen' : ($changes === 1 ? '1 Änderung' : $changes.' Änderungen') }}
        </span>
        @if ($changes > 0)
            <x-input.button type="button" color="gray" wire:click="discardSettings" class="max-sm:min-h-11">Verwerfen</x-input.button>
        @endif
    </x-slot:actions>

    {{-- the dialog is as high as its content (x-modal limits it and scrolls); tabs and filter stay on top --}}
    <div class="sticky -top-4 z-10 -mx-5 border-b border-gray-200 bg-white px-5 pt-4 pb-3 dark:border-gray-700 dark:bg-gray-800" style="margin-top: -1rem">
        <div role="tablist" aria-label="Bereiche" class="flex gap-1 overflow-x-auto rounded-lg bg-gray-100 p-1 dark:bg-gray-900/60">
            @foreach ($tabs as $tab => [$tabLabel, $tabCount])
                <button type="button" role="tab" id="gpo-tab-{{ $tab }}" aria-controls="gpo-panel" aria-selected="{{ $settingsTab === $tab ? 'true' : 'false' }}"
                    wire:click="$set('settingsTab', '{{ $tab }}')" data-tab="{{ $tab }}" @if ($settingsTab === $tab) data-modal-focus @endif
                    @class(['flex min-h-9 flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-md px-3 py-1.5 text-sm transition-colors focus:outline-hidden focus-visible:ring-2 focus-visible:ring-cerulean-500 max-sm:min-h-11 max-sm:gap-1 max-sm:px-1.5 max-sm:text-[13px]',
                        'bg-white font-DINPro-bold text-gray-900 shadow-xs dark:bg-gray-700 dark:text-white' => $settingsTab === $tab,
                        'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white' => $settingsTab !== $tab])>
                    {{ $tabLabel }}
                    {{-- the same round badge for every count; 0 in grey, visible on the grey bar too --}}
                    <span @class(['inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 text-xs font-medium tabular-nums',
                        $tones['blue'] => $tabCount > 0,
                        'bg-gray-200 text-gray-600 dark:bg-gray-600 dark:text-gray-200' => $tabCount === 0]) data-tab-count>{{ $tabCount }}<span class="sr-only"> konfiguriert</span></span>
                </button>
            @endforeach
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                <input type="search" wire:model.live.debounce.300ms="settingsSearch" placeholder="Einstellung suchen …" aria-label="Einstellung suchen"
                    class="min-w-0 flex-1 basis-56 rounded-lg border-gray-300 bg-white py-2 text-sm text-gray-900 focus:border-cerulean-500 focus:ring-cerulean-500 dark:border-gray-700 dark:bg-gray-700 dark:text-gray-100">
                <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 text-sm text-gray-700 sm:min-h-0 dark:text-gray-300">
                    <input type="checkbox" wire:model.live="onlyConfigured" role="switch" class="peer sr-only" data-only-configured>
                    <span class="relative inline-flex h-6 w-11 shrink-0 rounded-full bg-gray-300 transition-colors peer-checked:bg-cerulean-600 peer-focus-visible:ring-2 peer-focus-visible:ring-cerulean-500 peer-focus-visible:ring-offset-2 dark:bg-gray-600 dark:peer-focus-visible:ring-offset-gray-800 after:absolute after:top-0.5 after:left-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:after:translate-x-5" aria-hidden="true"></span>
                    Nur konfigurierte
                </label>
            </div>
    </div>

    <div id="gpo-panel" role="tabpanel" aria-labelledby="gpo-tab-{{ $settingsTab }}" class="pt-3">
            @if (($otherSettings[$settingsTab] ?? 0) > 0 || ($otherCount === 0 && ! empty($otherSettings['extensions']) && $settingsTab === 'computer'))
                <x-callout title="Weitere Einstellungen" class="mb-3" data-other-settings>
                    Diese Gruppenrichtlinie enthält {{ $otherCount }} weitere Einträge{{ ! empty($otherSettings['extensions']) ? ' ('.implode(', ', $otherSettings['extensions']).')' : '' }}, die nur mit den Windows-Werkzeugen (GPMC) bearbeitet werden können. Sie bleiben unverändert.
                </x-callout>
            @endif

            @php $shown = 0; @endphp
            @foreach ($catalog->get($settingsTab, collect()) as $group => $items)
                @php
                    $visible = $items->filter(fn ($item) => (! $onlyConfigured || $configured($item))
                        && ($needle === '' || str_contains(mb_strtolower(($item['label'] ?? '').' '.($item['help'] ?? '')), $needle)));
                    $groupConfigured = $items->filter($configured)->count();
                    $groupChanged = $items->contains(fn ($item) => ($settings[$item['key']] ?? null) !== ($settingsOriginal[$item['key']] ?? null));
                    $groupErrors = $items->contains(fn ($item) => $errors->has('settings.'.$item['key']) || $errors->has('settings.'.$item['key'].'.*'));
                    $shown += $visible->count();
                @endphp
                @continue($visible->isEmpty())
                <section x-data="{ open: @js($filtering || $groupConfigured > 0 || $groupChanged || $groupErrors) }"
                    wire:key="sec-{{ $settingsTab }}-{{ md5($group) }}-{{ $filterKey }}{{ $groupErrors ? '-e' : '' }}"
                    class="mb-3 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700" data-gpo-group="{{ $group }}">
                    <h3>
                        <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()"
                            class="flex w-full items-center gap-3 bg-gray-50 px-4 py-3 text-left hover:bg-gray-100 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cerulean-500 dark:bg-gray-900/40 dark:hover:bg-gray-900/70">
                            <span class="min-w-0 flex-1 font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $group }}</span>
                            <span @class(['text-xs', 'text-cerulean-700 dark:text-cerulean-300' => $groupConfigured > 0, 'text-gray-500 dark:text-gray-400' => $groupConfigured === 0]) data-group-count>{{ $groupConfigured }} von {{ $items->count() }} konfiguriert</span>
                            <x-icon name="chevron-down" class="h-4 w-4 shrink-0 text-gray-500 transition-transform dark:text-gray-400" ::class="open && 'rotate-180'" />
                        </button>
                    </h3>
                    <div x-show="open" x-cloak class="divide-y divide-gray-100 border-t border-gray-200 dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($visible as $item)
                            @php
                                $key = $item['key'];
                                $value = $settings[$key] ?? null;
                                [$statusLabel, $tone] = Gpos::settingStatus($item, $value);
                                $changed = $value !== ($settingsOriginal[$key] ?? null);
                                $hasError = $errors->has('settings.'.$key) || $errors->has('settings.'.$key.'.*');
                                $id = 's-'.$key;
                                $unit = ! empty($item['unit']) ? ' '.$item['unit'] : '';
                                $range = isset($item['min'], $item['max']) ? $item['min'].' bis '.$item['max'].$unit.'.' : '';
                                $help = trim(($item['help'] ?? '').' '.$range);
                                $only = Gpos::editionsLabel($item['editions'] ?? null);
                            @endphp
                            <div x-data="{ open: @js($hasError) }" wire:key="set-{{ $key }}{{ $hasError ? '-e' : '' }}" data-setting="{{ $key }}"
                                @class(['relative', 'bg-cerulean-50/60 dark:bg-cerulean-900/15' => $changed]) @if ($changed) data-changed @endif>
                                @if ($changed)
                                    <span class="absolute inset-y-0 left-0 w-1 bg-cerulean-500" aria-hidden="true"></span>
                                @endif
                                <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-controls="{{ $id }}-editor"
                                    class="flex min-h-11 w-full items-center gap-3 px-4 py-2 text-left hover:bg-gray-50 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cerulean-500 dark:hover:bg-gray-700/40">
                                    <span class="min-w-0 flex-1 text-sm text-gray-900 dark:text-gray-100">
                                        {{ $item['label'] }}
                                        @if ($only)<span class="ml-1 inline-block rounded bg-gray-100 px-1.5 py-0.5 align-middle text-xs whitespace-nowrap text-gray-700 dark:bg-gray-700 dark:text-gray-200" data-editions data-tip="Wirkt nur auf diesen Windows-Editionen, andere übergehen die Einstellung.">{{ $only }}</span>@endif
                                        @if ($changed)<span class="ml-1 text-xs text-cerulean-700 dark:text-cerulean-300">geändert</span>@endif
                                        @if ($hasError)<span class="ml-1 text-xs text-red-700 dark:text-red-300">Fehler</span>@endif
                                    </span>
                                    <span class="{{ $badge }} {{ $tones[$tone] }} min-w-0 max-w-[45%]" data-status="{{ $tone }}"><span class="truncate">{{ $statusLabel }}</span></span>
                                    <x-icon name="chevron-down" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" ::class="open && 'rotate-180'" />
                                </button>

                                <div id="{{ $id }}-editor" x-show="open" x-cloak class="px-4 pb-4">
                                    @if ($help !== '')
                                        <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">{{ $help }}</p>
                                    @endif
                                    @switch($item['type'])
                                        @case('bool')
                                            <fieldset>
                                                <legend class="sr-only">{{ $item['label'] }}</legend>
                                                <div class="inline-flex rounded-lg border border-gray-300 bg-white p-0.5 max-sm:flex max-sm:w-full dark:border-gray-600 dark:bg-gray-800" data-segments>
                                                    @foreach ($segments as $segValue => [$segLabel, $segClass])
                                                        <label class="cursor-pointer max-sm:flex-1">
                                                            <input type="radio" class="peer sr-only" name="{{ $id }}" value="{{ $segValue }}" wire:model.live="settings.{{ $key }}">
                                                            <span class="{{ $segClass }} flex min-h-9 items-center justify-center whitespace-nowrap rounded-md px-3 py-1.5 text-center text-sm text-gray-600 transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-cerulean-500 max-sm:min-h-11 max-sm:px-2 max-sm:text-xs dark:text-gray-300">{{ $segLabel }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </fieldset>
                                            @break
                                        @case('enum')
                                            <x-input.select :id="$id" :feld="'settings.'.$key" wire:model.live="settings.{{ $key }}" aria-label="{{ $item['label'] }}">
                                                <option value="">Nicht konfiguriert</option>
                                                @foreach ((array) ($item['options'] ?? []) as $option)<option value="{{ $option['value'] }}">{{ Gpos::optionLabel($option) }}</option>@endforeach
                                            </x-input.select>
                                            @php
                                                $chosen = collect((array) ($item['options'] ?? []))->first(fn ($o) => $value !== null && (string) $o['value'] === (string) $value);
                                                $chosenOnly = $chosen ? Gpos::editionsLabel($chosen['editions'] ?? null) : null;
                                            @endphp
                                            @if ($chosenOnly)
                                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-option-editions>Dieser Wert wirkt {{ $chosenOnly }}; andere Editionen übergehen ihn.</p>
                                            @endif
                                            @break
                                        @case('int')
                                            <div class="flex items-center gap-2">
                                                <x-input.text :id="$id" :feld="'settings.'.$key" type="number" :min="$item['min'] ?? null" :max="$item['max'] ?? null" wire:model.live.debounce.500ms="settings.{{ $key }}" placeholder="Nicht konfiguriert" aria-label="{{ $item['label'] }}" class="w-40 tabular-nums" />
                                                @if ($unit !== '')<span class="text-sm text-gray-600 dark:text-gray-300">{{ trim($unit) }}</span>@endif
                                            </div>
                                            @break
                                        @case('text')
                                            <x-input.textarea :id="$id" :feld="'settings.'.$key" rows="4" :maxlength="$item['max'] ?? null" wire:model.live.debounce.500ms="settings.{{ $key }}" placeholder="Nicht konfiguriert" aria-label="{{ $item['label'] }}" />
                                            @break
                                        @case('scripts')
                                            <div class="flex flex-col gap-2">
                                                @foreach ((array) ($settings[$key] ?? []) as $i => $script)
                                                    <div class="grid grid-cols-[1fr_auto] gap-2 sm:grid-cols-[2fr_1fr_auto]" wire:key="script-{{ $key }}-{{ $i }}">
                                                        <div>
                                                            <x-input.text :feld="'settings.'.$key.'.'.$i.'.path'" wire:model.live.debounce.500ms="settings.{{ $key }}.{{ $i }}.path" placeholder="logon.bat" aria-label="Skript {{ $i + 1 }}" class="w-full font-mono text-sm" />
                                                            <x-input.fehler :feld="'settings.'.$key.'.'.$i.'.path'" />
                                                        </div>
                                                        <div class="max-sm:col-start-1 max-sm:row-start-2">
                                                            <x-input.text :feld="'settings.'.$key.'.'.$i.'.parameters'" wire:model.live.debounce.500ms="settings.{{ $key }}.{{ $i }}.parameters" placeholder="Parameter" aria-label="Parameter für Skript {{ $i + 1 }}" maxlength="260" class="w-full font-mono text-sm" />
                                                            <x-input.fehler :feld="'settings.'.$key.'.'.$i.'.parameters'" />
                                                        </div>
                                                        <x-input.symbolknopf titel="Skript {{ $i + 1 }} entfernen" ton="rot" wire:click="removeScript('{{ $key }}', {{ $i }})" class="mt-0.5"><x-icon name="trash" class="h-4 w-4" /></x-input.symbolknopf>
                                                    </div>
                                                @endforeach
                                                <div><x-input.button type="button" size="sm" color="gray" wire:click="addScript('{{ $key }}')"><x-icon name="plus" class="h-3.5 w-3.5" sw="2.2" />Skript hinzufügen</x-input.button></div>
                                            </div>
                                            @break
                                        @default
                                            <x-input.text :id="$id" :feld="'settings.'.$key" :maxlength="$item['max'] ?? null" :pattern="$item['pattern'] ?? null" wire:model.live.debounce.500ms="settings.{{ $key }}" placeholder="Nicht konfiguriert" aria-label="{{ $item['label'] }}" class="w-full" />
                                    @endswitch
                                    <x-input.fehler :feld="'settings.'.$key" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach

            {{-- the preferences lists of this tab (Gpp\Kinds) after the catalog groups, like the GPMC:
                 configuration → preferences → drive maps, printers, … --}}
            @php
                $visibleKinds = [];
                foreach (\App\Modules\Ad\Gpp\Kinds::of($settingsTab) as $kindKey => $listKind) {
                    $kindRows = $gpp[$kindKey] ?? [];
                    $kindMatch = $needle === '' || str_contains($listKind->searchWords(), $needle)
                        || collect($kindRows)->contains(fn ($r) => str_contains(mb_strtolower($listKind->searchText($r).' '.$r['group_name']), $needle));
                    if ($kindMatch && (! $onlyConfigured || (! empty($gppSupported[$kindKey]) && $kindRows !== []))) {
                        $visibleKinds[$kindKey] = $listKind;
                    }
                }
                $shown += count($visibleKinds);
            @endphp
            @if ($visibleKinds)
                <div class="mt-5 mb-2 flex items-center gap-2 text-xs font-DINPro-bold uppercase tracking-wide text-gray-500 dark:text-gray-400" data-gpp-heading>
                    Einstellungen ({{ implode(', ', array_map(fn ($k) => $k->area(), $visibleKinds)) }})<span class="h-px flex-1 bg-gray-200 dark:bg-gray-700" aria-hidden="true"></span>
                </div>
                @foreach ($visibleKinds as $kind)
                    @include('modules.ad.partials.gpp.section')
                @endforeach
            @endif

            @if ($catalog->isEmpty() && $settingsTab !== 'user')
                <p class="text-sm text-gray-500 dark:text-gray-400">PK95 AD hat keinen Einstellungskatalog geliefert.</p>
            @elseif ($shown === 0)
                <p class="rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400" data-settings-empty>
                    {{ $filtering ? 'Keine Einstellung passt zum Filter.' : 'In diesem Bereich gibt es keine Einstellungen.' }}
                </p>
            @endif
    </div>
</x-modal>
