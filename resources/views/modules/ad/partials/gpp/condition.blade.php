{{-- the condition of a preferences row: who gets it (all / members / all except members of a security group) --}}
@php
    $conditional = $current['condition'] !== 'all';
    $chosenGroup = $current['group'] !== '' ? ($groupNames[$current['group']] ?? ($current['group_name'] ?: null)) : null;
@endphp
<fieldset class="mt-4 rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-600 dark:bg-gray-800" data-gpp-condition>
    <legend class="flex items-center gap-1.5 px-1 text-sm font-DINPro-bold text-gray-900 dark:text-gray-100"><x-icon name="shield" class="h-4 w-4 text-cerulean-600 dark:text-cerulean-400" />Bedingung</legend>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-field label="Gilt für" :for="$key.'-cond-'.$e" :feld="$p.'condition'">
            <x-input.select :id="$key.'-cond-'.$e" :feld="$p.'condition'" wire:model.live="{{ $p }}condition" class="mt-1">
                @foreach ($kind->conditions() as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
            </x-input.select>
        </x-field>
        @if ($conditional)
            <x-field label="Sicherheitsgruppe" :feld="$p.'group'" :required="true" hilfe="Sicherheitsgruppen aus „Gruppen“.">
                <x-input.combobox wire:model.live="{{ $p }}group" label="Sicherheitsgruppe" :options="$groupOptions" placeholder="Gruppe wählen …" :search="true" class="mt-1" />
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
        {{ $conditional && $chosenGroup ? $kind->audience($current, $chosenGroup).' '.$kind->gets().'.' : ($conditional ? 'Wähle die Sicherheitsgruppe.' : $kind->everyone().', für die diese Gruppenrichtlinie gilt, '.$kind->gets().'.') }}
    </p>
</fieldset>
