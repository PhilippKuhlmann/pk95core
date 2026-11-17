@php
    use App\Http\Support\Format;
    use App\Modules\Ad\Scripts;
    use Illuminate\Support\Js;
@endphp

<div>
    <x-page-header title="Skripte">
        <x-slot:sub>Dateien in der Freigabe {{ $share }}: Anmeldeskripte für Benutzer und Skripte für Gruppenrichtlinien. Erlaubt sind .bat, .cmd, .ps1, .vbs und .kix.</x-slot:sub>
        @if (! $notReady && $editing === null)
            <x-input.button type="button" wire:click="create"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Skript anlegen</x-input.button>
        @endif
    </x-page-header>

    @include('modules.ad.partials.state')

    @if ($editing !== null)
        <x-modal wire:key="script-form" submit="save" size="2xl">
            <x-slot:title>{{ $editing === 'new' ? 'Skript anlegen' : $editing }}</x-slot:title>
            <div class="mt-2 grid grid-cols-1 gap-4">
                @if ($editing === 'new')
                    <x-field label="Dateiname" for="sc-name" feld="form.name" hilfe="Etwa logon.bat oder laufwerke.ps1." :required="true">
                        <x-input.text id="sc-name" feld="form.name" wire:model="form.name" maxlength="64" placeholder="logon.bat" class="mt-1 w-full font-mono text-sm" />
                    </x-field>
                @endif
                <x-field label="Inhalt" for="sc-content" feld="form.content" hilfe="Höchstens 256 KiB. Zeilenenden werden als CRLF gespeichert.">
                    <x-input.textarea id="sc-content" feld="form.content" rows="18" wire:model="form.content" spellcheck="false" class="mt-1 font-mono text-[13px]" />
                </x-field>
            </div>
        </x-modal>
    @endif

    @if (! $notReady)
        @if (! $rows && ! $loadError)
            <x-emptystate message="Noch keine Skripte in NETLOGON." />
        @elseif ($rows)
            <x-panel class="relative m-3 overflow-x-auto" polster="keins">
                <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100" data-scripts>
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr><th class="px-4 py-3">Datei</th><th class="px-4 py-3 text-right">Größe</th><th class="hidden px-4 py-3 sm:table-cell">Geändert</th>
                            <th class="px-4 py-3"><span class="sr-only">Aktionen</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($rows as $s)
                            <tr wire:key="script-{{ $s['name'] }}">
                                <td class="px-4 py-3 align-top">
                                    <button type="button" wire:click="edit({{ Js::from($s['name']) }})" class="text-left font-mono text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $s['name'] }}</button>
                                    <span class="block font-mono text-xs text-gray-500 dark:text-gray-400">{{ $share }}\{{ $s['name'] }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right align-top tabular-nums">{{ Format::bytes($s['size']) }}</td>
                                <td class="hidden whitespace-nowrap px-4 py-3 align-top tabular-nums text-gray-700 sm:table-cell dark:text-gray-300">{{ Scripts::when($s['changed_at']) }}</td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex justify-end gap-1">
                                        <x-input.symbolknopf titel="Bearbeiten" ton="grau" wire:click="edit({{ Js::from($s['name']) }})"><x-icon name="pencil" class="h-4 w-4" /></x-input.symbolknopf>
                                        <x-input.symbolknopf titel="{{ $s['name'] }} löschen" ton="rot"
                                            x-on:click="$store.confirm.ask({ title: 'Skript löschen?', text: {{ Js::from($s['name'].' wird aus NETLOGON gelöscht. Benutzer oder Gruppenrichtlinien, die es aufrufen, finden es dann nicht mehr.') }}, button: 'Löschen', ok: () => $wire.delete({{ Js::from($s['name']) }}) })">
                                            <x-icon name="trash" class="h-4 w-4" />
                                        </x-input.symbolknopf>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-panel>
        @endif
    @endif
</div>
