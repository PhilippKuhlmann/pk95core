{{-- editor fields of a drive map row ($p = "gpp.drives.N.") --}}
@php
    use App\Modules\Ad\Gpp\DriveMaps;
    $uncExample = '\\\\nas\\freigabe';
@endphp
<div class="grid grid-cols-1 gap-4 md:grid-cols-[8rem_1fr_1fr]">
    <x-field label="Buchstabe" :for="'d-letter-'.$e" :feld="$p.'letter'">
        <x-input.select :id="'d-letter-'.$e" :feld="$p.'letter'" wire:model.live="{{ $p }}letter" class="mt-1">
            @foreach (range('A', 'Z') as $letter)<option value="{{ $letter }}">{{ $letter }}:</option>@endforeach
        </x-input.select>
    </x-field>
    <x-field label="Pfad" :for="'d-path-'.$e" :feld="$p.'path'" hilfe="UNC-Pfad der Freigabe, bis 260 Zeichen." :required="true">
        <x-input.text :id="'d-path-'.$e" :feld="$p.'path'" wire:model.live.debounce.500ms="{{ $p }}path" maxlength="260" :placeholder="$uncExample" class="mt-1 w-full font-mono text-sm" />
    </x-field>
    <x-field label="Bezeichnung" :for="'d-label-'.$e" :feld="$p.'label'" hilfe="Name im Explorer, leer: der Freigabename.">
        <x-input.text :id="'d-label-'.$e" :feld="$p.'label'" wire:model.live.debounce.500ms="{{ $p }}label" maxlength="128" class="mt-1 w-full" />
    </x-field>
</div>

@include('modules.ad.partials.gpp.condition')

<div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
    <x-field label="Aktion" :for="'d-action-'.$e" :feld="$p.'action'" :hilfe="$kind->actionHelp()[$current['action']] ?? null">
        <x-input.select :id="'d-action-'.$e" :feld="$p.'action'" wire:model.live="{{ $p }}action" class="mt-1">
            @foreach (DriveMaps::ACTIONS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
        </x-input.select>
    </x-field>
    <x-field label="Im Explorer" :for="'d-vis-'.$e" :feld="$p.'visibility'">
        <x-input.select :id="'d-vis-'.$e" :feld="$p.'visibility'" wire:model.live="{{ $p }}visibility" class="mt-1">
            @foreach (DriveMaps::VISIBILITY as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
        </x-input.select>
    </x-field>
</div>
<div class="mt-2 flex flex-col gap-1">
    <x-input.checkbox label="Ersten freien Buchstaben ab diesem nehmen" hilfe="Sonst muss der Buchstabe in dieser Gruppenrichtlinie eindeutig sein." wire:model.live="{{ $p }}first_available" />
    <x-input.checkbox label="Wieder verbinden" hilfe="Das Laufwerk bleibt nach Abmelden und Neustart verbunden." wire:model.live="{{ $p }}reconnect" />
</div>
