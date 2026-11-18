{{-- editor fields of a shortcut row ($p = "gpp.shortcuts.N.") --}}
@php
    use App\Modules\Ad\Gpp\Shortcuts;
    $isFile = $current['target_type'] === 'file';
    $targetExample = $isFile ? '\\\\server\\programme\\app.exe' : 'https://intranet.example.de';
    // in a variable: "<" and ">" inside a component attribute would end the tag
    $nameHelp = 'So heißt die Verknüpfung, bis 100 Zeichen, ohne \\ / : * ? " < > |.';
@endphp
<div class="grid grid-cols-1 gap-4 md:grid-cols-2">
    <x-field label="Name" :for="'sc-name-'.$e" :feld="$p.'name'" :hilfe="$nameHelp" :required="true">
        <x-input.text :id="'sc-name-'.$e" :feld="$p.'name'" wire:model.live.debounce.500ms="{{ $p }}name" maxlength="100" class="mt-1 w-full" />
    </x-field>
    <x-field label="Ort" :for="'sc-loc-'.$e" :feld="$p.'location'">
        <x-input.select :id="'sc-loc-'.$e" :feld="$p.'location'" wire:model.live="{{ $p }}location" class="mt-1">
            @foreach (Shortcuts::LOCATIONS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
        </x-input.select>
    </x-field>
    <x-field label="Ziel" :for="'sc-type-'.$e" :feld="$p.'target_type'">
        <x-input.select :id="'sc-type-'.$e" :feld="$p.'target_type'" wire:model.live="{{ $p }}target_type" class="mt-1">
            @foreach (Shortcuts::TARGET_TYPES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
        </x-input.select>
    </x-field>
    <x-field :label="$isFile ? 'Pfad' : 'Adresse'" :for="'sc-target-'.$e" :feld="$p.'target'" :hilfe="$isFile ? 'UNC-Pfad, etwa einer Datei oder eines Programms auf einer Freigabe.' : 'Beginnt mit http:// oder https://.'" :required="true">
        <x-input.text :id="'sc-target-'.$e" :feld="$p.'target'" wire:model.live.debounce.500ms="{{ $p }}target" :placeholder="$targetExample" class="mt-1 w-full font-mono text-sm" />
    </x-field>
    @if ($isFile)
        <x-field label="Argumente" :for="'sc-args-'.$e" :feld="$p.'arguments'" hilfe="Optional, bis 260 Zeichen." class="md:col-span-2">
            <x-input.text :id="'sc-args-'.$e" :feld="$p.'arguments'" wire:model.live.debounce.500ms="{{ $p }}arguments" maxlength="260" class="mt-1 w-full font-mono text-sm" />
        </x-field>
    @endif
</div>
<details class="mt-3 rounded-lg border border-gray-200 dark:border-gray-700" @if ($current['icon_path'] !== '') open @endif>
    <summary class="cursor-pointer px-3 py-2 text-sm text-gray-700 dark:text-gray-300">Symbol</summary>
    <div class="grid grid-cols-1 gap-4 border-t border-gray-200 p-3 md:grid-cols-[1fr_8rem] dark:border-gray-700">
        <x-field label="Symboldatei" :for="'sc-icon-'.$e" :feld="$p.'icon_path'" hilfe="Optional, etwa eine .ico- oder .exe-Datei; leer: das Symbol des Ziels.">
            <x-input.text :id="'sc-icon-'.$e" :feld="$p.'icon_path'" wire:model.live.debounce.500ms="{{ $p }}icon_path" maxlength="260" class="mt-1 w-full font-mono text-sm" />
        </x-field>
        <x-field label="Index" :for="'sc-index-'.$e" :feld="$p.'icon_index'" hilfe="0 bis 999.">
            <x-input.text :id="'sc-index-'.$e" :feld="$p.'icon_index'" type="number" min="0" max="999" wire:model.live.debounce.500ms="{{ $p }}icon_index" class="mt-1 w-full tabular-nums" />
        </x-field>
    </div>
</details>

@include('modules.ad.partials.gpp.condition')

<div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
    <x-field label="Aktion" :for="'sc-action-'.$e" :feld="$p.'action'" :hilfe="$kind->actionHelp()[$current['action']] ?? null">
        <x-input.select :id="'sc-action-'.$e" :feld="$p.'action'" wire:model.live="{{ $p }}action" class="mt-1">
            @foreach (Shortcuts::ACTIONS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
        </x-input.select>
    </x-field>
</div>
