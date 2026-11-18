{{-- editor fields of a printer row ($p = "gpp.printers.N.") --}}
@php
    use App\Modules\Ad\Gpp\Printers;
    $printerExample = '\\\\druckserver\\buero';
@endphp
<x-field label="Drucker" :for="'pr-path-'.$e" :feld="$p.'path'" hilfe="Freigegebener Drucker eines Druckservers als UNC-Pfad, keine TCP/IP-Drucker." :required="true">
    <x-input.text :id="'pr-path-'.$e" :feld="$p.'path'" wire:model.live.debounce.500ms="{{ $p }}path" maxlength="260" :placeholder="$printerExample" class="mt-1 w-full font-mono text-sm" />
</x-field>
<div class="mt-2">
    <x-input.checkbox label="Als Standarddrucker festlegen" wire:model.live="{{ $p }}default" />
</div>

@include('modules.ad.partials.gpp.condition')

<div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
    <x-field label="Aktion" :for="'pr-action-'.$e" :feld="$p.'action'" :hilfe="$kind->actionHelp()[$current['action']] ?? null">
        <x-input.select :id="'pr-action-'.$e" :feld="$p.'action'" wire:model.live="{{ $p }}action" class="mt-1">
            @foreach (Printers::ACTIONS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
        </x-input.select>
    </x-field>
</div>
