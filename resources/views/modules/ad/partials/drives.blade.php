{{-- GPO settings dialog: drive maps (Group Policy Preferences, PK95 AD 1.1.0), user configuration --}}
@php
    use App\Modules\Ad\Gpos;
    $driveCount = count($drives);
    $atMax = $driveCount >= Gpos::DRIVES_MAX;
    $groupNames = collect($groupOptions)->pluck('label', 'value');
    $uncExample = '\\\\nas\\freigabe';
@endphp

@if (! $catalog->has('user'))
    <h3 class="mt-6 text-base font-CoconPro text-gray-900 dark:text-gray-100">{{ Gpos::SCOPES['user'] }}</h3>
@endif
<fieldset class="mt-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700" data-drives>
    <legend class="px-1 text-sm font-DINPro-bold text-gray-700 dark:text-gray-200">Netzlaufwerke</legend>
    @if (! $drivesSupported)
        <p class="text-sm text-gray-500 dark:text-gray-400" data-drives-unsupported>Netzlaufwerke per Gruppenrichtlinie gibt es ab PK95 AD 1.1.0.</p>
    @else
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Verbindet bei der Anmeldung Laufwerksbuchstaben mit Freigaben. Die Freigaben liegen auf einem Fileserver oder NAS, die Benutzer brauchen dort Rechte mit ihrem Domänenkonto.
            Kennwörter für Laufwerke speichert PK95 AD bewusst nicht (unsicher, MS14-025). Windows arbeitet die Liste von oben nach unten ab.
        </p>
        <x-input.fehler feld="drives" class="mt-2" />

        @if ($drives)
            <div class="mt-3 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100" data-drive-table>
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr><th class="px-3 py-2">Laufwerk</th><th class="px-3 py-2">Freigabe</th><th class="hidden px-3 py-2 sm:table-cell">Für</th>
                            <th class="hidden px-3 py-2 md:table-cell">Aktion</th><th class="px-3 py-2"><span class="sr-only">Aktionen</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($drives as $i => $d)
                            @php
                                $messages = collect($errors->get('drives.'.$i.'.*'))->flatten()->all();
                                $isFirst = $i === 0;
                                $isLast = $i === $driveCount - 1;
                                $group = $d['group'] === '' ? null : ($groupNames[$d['group']] ?? ($d['group_name'] ?: $d['group']));
                                $who = $group === null ? 'Alle Benutzer' : ($d['not'] ? 'Nicht in '.$group : $group);
                                $name = $d['letter'].':'.($d['path'] !== '' ? ' '.$d['path'] : '');
                            @endphp
                            <tr wire:key="drive-{{ $i }}-{{ $d['uid'] }}" data-drive-row @class([
                                'bg-red-50 dark:bg-red-950/40' => $messages,
                                'bg-cerulean-50 dark:bg-cerulean-900/20' => ! $messages && $driveEditing === $i,
                            ])>
                                <td class="whitespace-nowrap px-3 py-2 align-top">
                                    <span class="font-mono font-DINPro-bold">{{ $d['letter'] }}:</span>
                                    @if ($d['first_available'])<span class="block text-xs text-gray-500 dark:text-gray-400">erster freier ab {{ $d['letter'] }}</span>@endif
                                    @if ($d['label'] !== '')<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $d['label'] }}</span>@endif
                                </td>
                                <td class="min-w-40 px-3 py-2 align-top">
                                    <span class="font-mono text-xs break-all">{{ $d['path'] !== '' ? $d['path'] : '–' }}</span>
                                    <span class="block text-xs text-gray-500 sm:hidden dark:text-gray-400">{{ $who }}</span>
                                    @if ($d['readonly'])
                                        <span class="mt-1 flex items-start gap-1 text-xs text-amber-800 dark:text-amber-200" data-drive-readonly>
                                            <x-icon name="lock" class="mt-0.5 h-3 w-3 shrink-0" />{{ $d['warning'] !== '' ? $d['warning'] : 'Mit der GPMC angelegt; hier nicht bearbeitbar.' }}
                                        </span>
                                    @endif
                                    @foreach ($messages as $message)
                                        <span class="mt-1 block text-xs text-red-700 dark:text-red-300" role="status">{{ $message }}</span>
                                    @endforeach
                                </td>
                                <td class="hidden px-3 py-2 align-top text-gray-700 sm:table-cell dark:text-gray-300">{{ $who }}</td>
                                <td class="hidden px-3 py-2 align-top text-gray-700 md:table-cell dark:text-gray-300">
                                    {{ Gpos::DRIVE_ACTIONS[$d['action']] ?? $d['action'] }}
                                    @if ($d['visibility'] !== 'unchanged')<span class="block text-xs text-gray-500 dark:text-gray-400">{{ Gpos::DRIVE_VISIBILITY[$d['visibility']] ?? $d['visibility'] }}</span>@endif
                                </td>
                                <td class="px-3 py-2 align-top">
                                    <div class="flex justify-end gap-1">
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
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Noch keine Netzlaufwerke.</p>
        @endif

        {{-- editor of one row; the fields write straight into the list, "Speichern" of the dialog sends it --}}
        @if ($driveEditing !== null && isset($drives[$driveEditing]))
            @php
                $e = $driveEditing;
                $p = 'drives.'.$e.'.';
                $current = $drives[$e];
                $editorTitle = $current['uid'] === '' ? 'Neues Netzlaufwerk' : 'Netzlaufwerk '.$current['letter'].':';
            @endphp
            <div class="mt-3 rounded-lg border border-cerulean-200 bg-cerulean-50/40 p-4 dark:border-cerulean-800 dark:bg-cerulean-900/10" wire:key="drive-editor-{{ $e }}" data-drive-editor>
                <div class="mb-3 text-sm font-DINPro-bold text-gray-800 dark:text-gray-100">{{ $editorTitle }}</div>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-[8rem_1fr_1fr]">
                    <x-field label="Buchstabe" :for="'d-letter-'.$e" :feld="$p.'letter'">
                        <x-input.select :id="'d-letter-'.$e" :feld="$p.'letter'" wire:model.live="drives.{{ $e }}.letter" class="mt-1">
                            @foreach (range('A', 'Z') as $letter)<option value="{{ $letter }}">{{ $letter }}:</option>@endforeach
                        </x-input.select>
                    </x-field>
                    <x-field label="Freigabe" :for="'d-path-'.$e" :feld="$p.'path'" hilfe="UNC-Pfad, bis 260 Zeichen." :required="true">
                        <x-input.text :id="'d-path-'.$e" :feld="$p.'path'" wire:model="drives.{{ $e }}.path" maxlength="260" :placeholder="$uncExample" class="mt-1 w-full font-mono text-sm" />
                    </x-field>
                    <x-field label="Bezeichnung" :for="'d-label-'.$e" :feld="$p.'label'" hilfe="Name im Explorer, leer: der Freigabename.">
                        <x-input.text :id="'d-label-'.$e" :feld="$p.'label'" wire:model="drives.{{ $e }}.label" maxlength="128" class="mt-1 w-full" />
                    </x-field>
                    <x-field label="Aktion" :for="'d-action-'.$e" :feld="$p.'action'" :hilfe="Gpos::DRIVE_ACTION_HELP[$current['action']] ?? null">
                        <x-input.select :id="'d-action-'.$e" :feld="$p.'action'" wire:model.live="drives.{{ $e }}.action" class="mt-1">
                            @foreach (Gpos::DRIVE_ACTIONS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </x-input.select>
                    </x-field>
                    <x-field label="Im Explorer" :for="'d-vis-'.$e" :feld="$p.'visibility'">
                        <x-input.select :id="'d-vis-'.$e" :feld="$p.'visibility'" wire:model="drives.{{ $e }}.visibility" class="mt-1">
                            @foreach (Gpos::DRIVE_VISIBILITY as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </x-input.select>
                    </x-field>
                    <x-field label="Für" :feld="$p.'group'" hilfe="Nur für Mitglieder einer Sicherheitsgruppe, oder für alle anderen.">
                        <x-input.combobox wire:model.live="drives.{{ $e }}.group" label="Für" :options="$groupOptions" placeholder="Alle Benutzer der Richtlinie" reset="Alle Benutzer der Richtlinie" :search="true" class="mt-1" />
                    </x-field>
                </div>
                <div class="mt-2 flex flex-col gap-1">
                    <x-input.checkbox label="Ersten freien Buchstaben ab diesem nehmen" hilfe="Sonst muss der Buchstabe in dieser Gruppenrichtlinie eindeutig sein." wire:model="drives.{{ $e }}.first_available" />
                    <x-input.checkbox label="Wieder verbinden" hilfe="Das Laufwerk bleibt nach Abmelden und Neustart verbunden." wire:model="drives.{{ $e }}.reconnect" />
                    @if ($current['group'] !== '')
                        <x-input.checkbox label="Nur für Benutzer, die nicht Mitglied der Gruppe sind" wire:model="drives.{{ $e }}.not" />
                    @endif
                </div>
                <div class="mt-3 flex justify-end">
                    <x-input.button type="button" size="sm" color="gray" wire:click="closeDrive">Fertig</x-input.button>
                </div>
            </div>
        @endif

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <x-input.button type="button" size="sm" color="gray" wire:click="addDrive" :disabled="$atMax"><x-icon name="plus" class="h-3.5 w-3.5" sw="2.2" />Netzlaufwerk hinzufügen</x-input.button>
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $driveCount }} von {{ Gpos::DRIVES_MAX }}</span>
        </div>
    @endif
</fieldset>
