<div>
    <x-page-header title="Einstellungen" />

    <x-panel class="m-3 max-w-3xl">
        <form wire:submit="save">
            <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">Wartungsfenster</div>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Einmal am Tag zu dieser Uhrzeit sichert Core die Apps (wo „Tägliche Sicherung“ an ist) und spielt automatische Updates ein
                (wo die Update-Regel es erlaubt). Ein Update sichert vorher selbst.
            </p>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-field label="Uhrzeit" for="s-time" feld="maintenanceTime" :hilfe="$lastRun ? 'Zuletzt am '.\Illuminate\Support\Carbon::parse($lastRun)->format('d.m.Y') : 'Noch nie gelaufen'">
                    <x-input.text id="s-time" type="time" feld="maintenanceTime" wire:model="maintenanceTime" class="mt-1 w-full" />
                </x-field>
                <x-field label="Sicherungen pro App behalten" for="s-keep" feld="backupKeep" :hilfe="'Ältere werden gelöscht. Ablage: '.$dataDir.'/backups'">
                    <x-input.text id="s-keep" type="number" min="1" max="100" feld="backupKeep" wire:model="backupKeep" class="mt-1 w-full" />
                </x-field>
            </div>
            <div class="mt-6 flex flex-wrap justify-between gap-2">
                <x-input.button type="button" color="gray" wire:click="checkUpdates" wire:loading.attr="disabled">
                    <x-icon name="refresh" class="h-4 w-4" sw="2" />Jetzt nach Updates suchen
                </x-input.button>
                <x-input.button>Speichern</x-input.button>
            </div>
        </form>
    </x-panel>
</div>
