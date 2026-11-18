{{--
    A Group Policy Preferences list inside its section ($kind, see Gpp\Kind): intro, table in
    file order (the kind renders its cells), the editor of one row (the kind renders its fields,
    the condition is shared) and "… hinzufügen". The fields write straight into $gpp; "Speichern"
    of the dialog sends the list.
--}}
@php
    $key = $kind->key();
    $rows = $gpp[$key] ?? [];
    $rowCount = count($rows);
    $atMax = $rowCount >= $kind->max();
    $editIndex = $this->gppEditingIndex($key);
    [$changedRows] = $this->gppChanges($key);
    $groupNames = collect($groupOptions)->pluck('label', 'value');
    $groupsUrl = route('apps.page', [$installedApp, 'groups']);
    [$earlier, $later] = $kind->moveLabels();
@endphp

@if (empty($gppSupported[$key]))
    <p class="rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400" data-gpp-unsupported>{{ $kind->title() }} per Gruppenrichtlinie gibt es ab PK95 AD {{ $kind->since() }}.</p>
@else
    <div data-gpp="{{ $key }}">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-300">
                {{ $kind->intro() }}
                @if ($kind->hint())
                    <span tabindex="0" role="img" aria-label="{{ $kind->hint() }}" data-tip="{{ $kind->hint() }}" data-gpp-hint
                        class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-cerulean-600 hover:bg-cerulean-50 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-cerulean-500 dark:text-cerulean-400 dark:hover:bg-cerulean-900/30">
                        <x-icon name="info" class="h-4 w-4" />
                    </span>
                @endif
            </p>
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $rowCount }} von {{ $kind->max() }}</span>
        </div>
        <x-input.fehler :feld="'gpp.'.$key" class="mt-2" />

        @if ($rows)
            <div class="mt-3 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100" data-gpp-table>
                    <thead class="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-400">
                        <tr>
                            @foreach ($kind->columns() as $c => [$colLabel, $colClass])
                                {{-- the first column names itself on a phone (letter, name …), its head would only take room --}}
                                <th class="{{ trim('px-2 py-2 sm:px-3 '.$colClass) }}">@if ($c === 0)<span class="max-sm:sr-only">{{ $colLabel }}</span>@else{{ $colLabel }}@endif</th>
                            @endforeach
                            <th class="px-2 py-2 sm:px-3"><span class="sr-only">Bearbeiten</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($rows as $i => $row)
                            @php
                                $messages = collect($errors->get('gpp.'.$key.'.'.$i.'.*'))->flatten()->all();
                                $audience = $kind->audience($row, $groupNames[$row['group']] ?? null);
                                $name = $kind->rowLabel($row);
                                $changed = in_array($i, $changedRows, true);
                            @endphp
                            <tr wire:key="gpp-{{ $key }}-{{ $i }}-{{ $row['uid'] }}" data-gpp-row @if ($changed) data-changed @endif @class([
                                'bg-red-50 dark:bg-red-950/40' => $messages,
                                'bg-cerulean-50 dark:bg-cerulean-900/20' => ! $messages && ($editIndex === $i || $changed),
                            ])>
                                @include($kind->view('cells'))
                                <td class="px-2 py-2 align-top max-sm:w-[5.25rem] max-sm:min-w-[5.25rem] sm:px-3">
                                    {{-- on a phone 2 × 2, so the table needs no side scrolling --}}
                                    <div class="grid grid-cols-2 justify-items-end gap-1 sm:flex sm:justify-end">
                                        <x-input.symbolknopf titel="{{ $name }} {{ $earlier }}" ton="grau" :disabled="$i === 0" wire:click="moveGpp('{{ $key }}', {{ $i }}, -1)"><x-icon name="chevron-down" class="h-4 w-4 rotate-180" /></x-input.symbolknopf>
                                        <x-input.symbolknopf titel="{{ $name }} {{ $later }}" ton="grau" :disabled="$i === $rowCount - 1" wire:click="moveGpp('{{ $key }}', {{ $i }}, 1)"><x-icon name="chevron-down" class="h-4 w-4" /></x-input.symbolknopf>
                                        @unless ($row['readonly'])
                                            <x-input.symbolknopf titel="{{ $name }} bearbeiten" ton="grau" wire:click="editGpp('{{ $key }}', {{ $i }})"><x-icon name="pencil" class="h-4 w-4" /></x-input.symbolknopf>
                                        @endunless
                                        <x-input.symbolknopf titel="{{ $name }} entfernen" ton="rot" wire:click="removeGpp('{{ $key }}', {{ $i }})"><x-icon name="trash" class="h-4 w-4" /></x-input.symbolknopf>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="mt-3 rounded-lg border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">{{ $kind->emptyText() }}</p>
        @endif

        @if ($editIndex !== null && isset($rows[$editIndex]))
            @php
                $e = $editIndex;
                $m = 'gpp.'.$key.'.'.$e;
                $p = $m.'.';
                $current = $rows[$e];
            @endphp
            <div class="mt-3 rounded-lg border border-cerulean-200 bg-cerulean-50/40 p-4 dark:border-cerulean-800 dark:bg-cerulean-900/10" wire:key="gpp-editor-{{ $key }}-{{ $e }}" data-gpp-editor
                x-init="$nextTick(() => $el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }))">
                <div class="mb-3 text-sm font-DINPro-bold text-gray-800 dark:text-gray-100">{{ $current['uid'] === '' ? $kind->newTitle() : $kind->editorTitle($current) }}</div>
                @include($kind->view('fields'))
                <div class="mt-3 flex justify-end">
                    <x-input.button type="button" size="sm" color="gray" wire:click="closeGpp" class="max-sm:min-h-11">Fertig</x-input.button>
                </div>
            </div>
        @endif

        <div class="mt-3">
            <x-input.button type="button" size="sm" color="gray" wire:click="addGpp('{{ $key }}')" :disabled="$atMax" class="max-sm:min-h-11"><x-icon name="plus" class="h-3.5 w-3.5" sw="2.2" />{{ $kind->addLabel() }}</x-input.button>
        </div>
    </div>
@endif
