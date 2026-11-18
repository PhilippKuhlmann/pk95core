{{-- GPO settings dialog, tab "Netzlaufwerke": drive maps (Group Policy Preferences, PK95 AD 1.1.0), user configuration --}}
@php
    use App\Modules\Ad\Gpos;
    $driveCount = count($drives);
    $atMax = $driveCount >= Gpos::DRIVES_MAX;
    $groupNames = collect($groupOptions)->pluck('label', 'value');
    $uncExample = '\\\\nas\\freigabe';
    $hint = 'Die Freigaben liegen auf einem Fileserver oder NAS; die Benutzer brauchen dort Rechte mit ihrem Domänenkonto. Kennwörter für Laufwerke speichert PK95 AD bewusst nicht (unsicher, MS14-025).';
    $groupsUrl = route('apps.page', [$installedApp, 'groups']);
@endphp

@if (! $drivesSupported)
    <p class="rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400" data-drives-unsupported>Netzlaufwerke per Gruppenrichtlinie gibt es ab PK95 AD 1.1.0.</p>
@else
    <div data-drives>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-300">
                Verbindet bei der Anmeldung Laufwerke, von oben nach unten.
                <span tabindex="0" role="img" aria-label="{{ $hint }}" data-tip="{{ $hint }}" data-drives-hint
                    class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-cerulean-600 hover:bg-cerulean-50 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-cerulean-500 dark:text-cerulean-400 dark:hover:bg-cerulean-900/30">
                    <x-icon name="info" class="h-4 w-4" />
                </span>
            </p>
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $driveCount }} von {{ Gpos::DRIVES_MAX }}</span>
        </div>
        <x-input.fehler feld="drives" class="mt-2" />

        @if ($drives)
            <div class="mt-3 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100" data-drive-table>
                    <thead class="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-400">
                        <tr><th class="px-2 py-2 sm:px-3"><span class="max-sm:sr-only">Laufwerk</span></th><th class="px-2 py-2 sm:px-3">Pfad</th><th class="hidden px-2 py-2 sm:px-3 lg:table-cell">Bezeichnung</th>
                            <th class="hidden px-2 py-2 sm:px-3 sm:table-cell">Gilt für</th><th class="hidden px-2 py-2 sm:px-3 md:table-cell">Aktion</th>
                            <th class="px-2 py-2 sm:px-3"><span class="sr-only">Bearbeiten</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($drives as $i => $d)
                            @php
                                $messages = collect($errors->get('drives.'.$i.'.*'))->flatten()->all();
                                $isFirst = $i === 0;
                                $isLast = $i === $driveCount - 1;
                                $audience = Gpos::driveAudience($d, $groupNames[$d['group']] ?? null);
                                $name = $d['letter'].':'.($d['path'] !== '' ? ' '.$d['path'] : '');
                                $changed = in_array($i, $changedDrives, true);
                            @endphp
                            <tr wire:key="drive-{{ $i }}-{{ $d['uid'] }}" data-drive-row @if ($changed) data-changed @endif @class([
                                'bg-red-50 dark:bg-red-950/40' => $messages,
                                'bg-cerulean-50 dark:bg-cerulean-900/20' => ! $messages && ($driveEditing === $i || $changed),
                            ])>
                                <td @class(['whitespace-nowrap px-2 py-2 sm:px-3 align-top', 'border-l-4 border-cerulean-500' => $changed])>
                                    <span class="font-mono font-DINPro-bold">{{ $d['letter'] }}:</span>
                                    @if ($d['first_available'])<span class="block text-xs text-gray-500 dark:text-gray-400">erster freier ab {{ $d['letter'] }}</span>@endif
                                    @if ($changed)<span class="block text-xs text-cerulean-700 dark:text-cerulean-300">{{ $d['uid'] === '' ? 'neu' : 'geändert' }}</span>@endif
                                </td>
                                <td class="px-2 py-2 align-top sm:min-w-40 sm:px-3">
                                    <span class="font-mono text-xs break-all">{{ $d['path'] !== '' ? $d['path'] : '–' }}</span>
                                    @if ($d['label'] !== '')<span class="block text-xs text-gray-500 lg:hidden dark:text-gray-400">{{ $d['label'] }}</span>@endif
                                    <span @class(['mt-0.5 flex items-center gap-1 text-xs sm:hidden',
                                        'text-gray-600 dark:text-gray-300' => $d['condition'] === 'all',
                                        'font-medium text-cerulean-800 dark:text-cerulean-200' => $d['condition'] === 'member',
                                        'font-medium text-amber-800 dark:text-amber-200' => $d['condition'] === 'not_member'])>@if ($d['condition'] !== 'all')<x-icon name="shield" class="h-3 w-3 shrink-0" />@endif{{ $audience }}</span>
                                    @if ($d['readonly'])
                                        <span class="mt-1 flex items-start gap-1 text-xs text-amber-800 dark:text-amber-200" data-drive-readonly>
                                            <x-icon name="lock" class="mt-0.5 h-3 w-3 shrink-0" />{{ $d['warning'] !== '' ? $d['warning'] : 'Mit der GPMC angelegt; hier nicht bearbeitbar.' }}
                                        </span>
                                    @endif
                                    @foreach ($messages as $message)
                                        <span class="mt-1 block text-xs text-red-700 dark:text-red-300" role="status">{{ $message }}</span>
                                    @endforeach
                                </td>
                                <td class="hidden px-2 py-2 sm:px-3 align-top text-gray-700 lg:table-cell dark:text-gray-300">{{ $d['label'] !== '' ? $d['label'] : '–' }}</td>
                                <td class="hidden px-2 py-2 sm:px-3 align-top sm:table-cell" data-audience>
                                    <span @class(['inline-flex items-center gap-1 text-sm',
                                        'text-gray-700 dark:text-gray-300' => $d['condition'] === 'all',
                                        'font-medium text-cerulean-800 dark:text-cerulean-200' => $d['condition'] === 'member',
                                        'font-medium text-amber-800 dark:text-amber-200' => $d['condition'] === 'not_member'])>
                                        @if ($d['condition'] !== 'all')<x-icon name="shield" class="h-3.5 w-3.5 shrink-0" />@endif{{ $audience }}
                                    </span>
                                </td>
                                <td class="hidden px-2 py-2 sm:px-3 align-top text-gray-700 md:table-cell dark:text-gray-300">
                                    {{ Gpos::DRIVE_ACTIONS[$d['action']] ?? $d['action'] }}
                                    @if ($d['visibility'] !== 'unchanged')<span class="block text-xs text-gray-500 dark:text-gray-400">{{ Gpos::DRIVE_VISIBILITY[$d['visibility']] ?? $d['visibility'] }}</span>@endif
                                </td>
                                <td class="px-2 py-2 align-top max-sm:w-[5.25rem] max-sm:min-w-[5.25rem] sm:px-3">
                                    {{-- on a phone 2 × 2, so the table needs no side scrolling --}}
                                    <div class="grid grid-cols-2 justify-items-end gap-1 sm:flex sm:justify-end">
                                        <x-input.symbolknopf titel="{{ $name }} früher verbinden" ton="grau" :disabled="$isFirst" wire:click="moveDrive({{ $i }}, -1)"><x-icon name="chevron-down" class="h-4 w-4 rotate-180" /></x-input.symbolknopf>
                                        <x-input.symbolknopf titel="{{ $name }} später verbinden" ton="grau" :disabled="$isLast" wire:click="moveDrive({{ $i }}, 1)"><x-icon name="chevron-down" class="h-4 w-4" /></x-input.symbolknopf>
                                        @unless ($d['readonly'])
                                            <x-input.symbolknopf titel="{{ $name }} bearbeiten" ton="grau" wire:click="editDrive({{ $i }})"><x-icon name="pencil" class="h-4 w-4" /></x-input.symbolknopf>
                                        @endunless
                                        <x-input.symbolknopf titel="{{ $name }} entfernen" ton="rot" wire:click="removeDrive({{ $i }})"><x-icon name="trash" class="h-4 w-4" /></x-input.symbolknopf>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="mt-3 rounded-lg border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">Noch keine Netzlaufwerke.</p>
        @endif

        {{-- editor of one row; the fields write straight into the list, "Speichern" of the dialog sends it --}}
        @if ($driveEditing !== null && isset($drives[$driveEditing]))
            @php
                $e = $driveEditing;
                $p = 'drives.'.$e.'.';
                $current = $drives[$e];
                $editorTitle = $current['uid'] === '' ? 'Neues Netzlaufwerk' : 'Netzlaufwerk '.$current['letter'].':';
                $conditional = $current['condition'] !== 'all';
                $chosenGroup = $current['group'] !== '' ? ($groupNames[$current['group']] ?? ($current['group_name'] ?: null)) : null;
            @endphp
            <div class="mt-3 rounded-lg border border-cerulean-200 bg-cerulean-50/40 p-4 dark:border-cerulean-800 dark:bg-cerulean-900/10" wire:key="drive-editor-{{ $e }}" data-drive-editor
                x-init="$nextTick(() => $el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }))">
                <div class="mb-3 text-sm font-DINPro-bold text-gray-800 dark:text-gray-100">{{ $editorTitle }}</div>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-[8rem_1fr_1fr]">
                    <x-field label="Buchstabe" :for="'d-letter-'.$e" :feld="$p.'letter'">
                        <x-input.select :id="'d-letter-'.$e" :feld="$p.'letter'" wire:model.live="drives.{{ $e }}.letter" class="mt-1">
                            @foreach (range('A', 'Z') as $letter)<option value="{{ $letter }}">{{ $letter }}:</option>@endforeach
                        </x-input.select>
                    </x-field>
                    <x-field label="Pfad" :for="'d-path-'.$e" :feld="$p.'path'" hilfe="UNC-Pfad der Freigabe, bis 260 Zeichen." :required="true">
                        <x-input.text :id="'d-path-'.$e" :feld="$p.'path'" wire:model.live.debounce.500ms="drives.{{ $e }}.path" maxlength="260" :placeholder="$uncExample" class="mt-1 w-full font-mono text-sm" />
                    </x-field>
                    <x-field label="Bezeichnung" :for="'d-label-'.$e" :feld="$p.'label'" hilfe="Name im Explorer, leer: der Freigabename.">
                        <x-input.text :id="'d-label-'.$e" :feld="$p.'label'" wire:model.live.debounce.500ms="drives.{{ $e }}.label" maxlength="128" class="mt-1 w-full" />
                    </x-field>
                </div>

                {{-- the condition: who gets this drive --}}
                <fieldset class="mt-4 rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-600 dark:bg-gray-800" data-drive-condition>
                    <legend class="flex items-center gap-1.5 px-1 text-sm font-DINPro-bold text-gray-900 dark:text-gray-100"><x-icon name="shield" class="h-4 w-4 text-cerulean-600 dark:text-cerulean-400" />Bedingung</legend>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-field label="Gilt für" :for="'d-cond-'.$e" :feld="$p.'condition'">
                            <x-input.select :id="'d-cond-'.$e" :feld="$p.'condition'" wire:model.live="drives.{{ $e }}.condition" class="mt-1">
                                @foreach (Gpos::DRIVE_CONDITIONS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                            </x-input.select>
                        </x-field>
                        @if ($conditional)
                            <x-field label="Sicherheitsgruppe" :feld="$p.'group'" :required="true" hilfe="Sicherheitsgruppen aus „Gruppen“.">
                                <x-input.combobox wire:model.live="drives.{{ $e }}.group" label="Sicherheitsgruppe" :options="$groupOptions" placeholder="Gruppe wählen …" :search="true" class="mt-1" />
                                <x-slot:unten>
                                    @if ($chosenGroup)
                                        <a href="{{ $groupsUrl }}?q={{ urlencode($chosenGroup) }}" target="_blank" rel="noopener" class="mt-1 inline-flex items-center gap-1 text-xs text-cerulean-700 hover:underline dark:text-cerulean-300" data-group-link>
                                            Gruppe {{ $chosenGroup }} öffnen<x-icon name="external" class="h-3 w-3" />
                                        </a>
                                    @endif
                                </x-slot:unten>
                            </x-field>
                        @endif
                    </div>
                    <p class="mt-2 text-xs text-gray-600 dark:text-gray-300" data-condition-summary>
                        {{ $conditional && $chosenGroup ? Gpos::driveAudience($current, $chosenGroup).' bekommen dieses Laufwerk.' : ($conditional ? 'Wähle die Sicherheitsgruppe.' : 'Alle Benutzer, für die diese Gruppenrichtlinie gilt, bekommen dieses Laufwerk.') }}
                    </p>
                </fieldset>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-field label="Aktion" :for="'d-action-'.$e" :feld="$p.'action'" :hilfe="Gpos::DRIVE_ACTION_HELP[$current['action']] ?? null">
                        <x-input.select :id="'d-action-'.$e" :feld="$p.'action'" wire:model.live="drives.{{ $e }}.action" class="mt-1">
                            @foreach (Gpos::DRIVE_ACTIONS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </x-input.select>
                    </x-field>
                    <x-field label="Im Explorer" :for="'d-vis-'.$e" :feld="$p.'visibility'">
                        <x-input.select :id="'d-vis-'.$e" :feld="$p.'visibility'" wire:model.live="drives.{{ $e }}.visibility" class="mt-1">
                            @foreach (Gpos::DRIVE_VISIBILITY as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </x-input.select>
                    </x-field>
                </div>
                <div class="mt-2 flex flex-col gap-1">
                    <x-input.checkbox label="Ersten freien Buchstaben ab diesem nehmen" hilfe="Sonst muss der Buchstabe in dieser Gruppenrichtlinie eindeutig sein." wire:model.live="drives.{{ $e }}.first_available" />
                    <x-input.checkbox label="Wieder verbinden" hilfe="Das Laufwerk bleibt nach Abmelden und Neustart verbunden." wire:model.live="drives.{{ $e }}.reconnect" />
                </div>
                <div class="mt-3 flex justify-end">
                    <x-input.button type="button" size="sm" color="gray" wire:click="closeDrive" class="max-sm:min-h-11">Fertig</x-input.button>
                </div>
            </div>
        @endif

        <div class="mt-3">
            <x-input.button type="button" size="sm" color="gray" wire:click="addDrive" :disabled="$atMax" class="max-sm:min-h-11"><x-icon name="plus" class="h-3.5 w-3.5" sw="2.2" />Netzlaufwerk hinzufügen</x-input.button>
        </div>
    </div>
@endif
