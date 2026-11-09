<div>
    <x-page-header title="Lokale Einträge">
        <x-slot:sub>Eigene DNS-Einträge für Geräte und Dienste im Netz. Sie gelten auch, wenn der Schutz pausiert ist.</x-slot:sub>
        @if ($editing === null)
            <x-input.button type="button" wire:click="create"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Eintrag hinzufügen</x-input.button>
        @endif
    </x-page-header>

    @if ($loadError)
        <x-callout title="PK95 DNS antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    @if ($editing !== null)
        <x-panel class="m-3" wire:key="rw-form">
            <form wire:submit="save">
                <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">{{ $editing === 'new' ? 'Eintrag hinzufügen' : 'Eintrag bearbeiten' }}</div>
                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-field label="Name" for="rw-domain" feld="form.domain" hilfe="Ein Hostname wie nas.home.lan oder *.home.lan für alle Subdomains (nicht home.lan selbst).">
                        <x-input.text id="rw-domain" feld="form.domain" wire:model="form.domain" placeholder="nas.home.lan" class="mt-1 w-full font-mono text-sm" />
                    </x-field>
                    <x-field label="Antwort" for="rw-answer" feld="form.answer" hilfe="IPv4- oder IPv6-Adresse, oder ein anderer Name (wird als CNAME beantwortet).">
                        <x-input.text id="rw-answer" feld="form.answer" wire:model="form.answer" placeholder="192.168.1.10" class="mt-1 w-full font-mono text-sm" />
                    </x-field>
                </div>
                <x-input.checkbox label="Aktiv" wire:model="form.enabled" />
                <div class="mt-6 flex justify-end gap-2">
                    <x-input.button type="button" color="gray" wire:click="cancel">Abbrechen</x-input.button>
                    <x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button>
                </div>
            </form>
        </x-panel>
    @endif

    @if (! $rows && ! $loadError)
        <x-panel class="m-3" polster="weit">
            <div class="text-center">
                <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Noch keine lokalen Einträge</div>
                <p class="mx-auto mt-2 max-w-xl text-sm text-gray-500 dark:text-gray-400">Damit erreichst du Geräte im Netz über einen Namen, etwa nas.home.lan statt 192.168.1.10. Ein Eintrag mit *.home.lan gilt für alle Subdomains.</p>
            </div>
        </x-panel>
    @elseif ($rows)
        <x-panel class="m-3 overflow-x-auto" polster="keins">
            <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr><th class="px-4 py-3">Aktiv</th><th class="px-4 py-3">Name</th><th class="px-4 py-3">Antwort</th><th class="px-4 py-3"><span class="sr-only">Aktionen</span></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($rows as $r)
                        <tr wire:key="rw-{{ $r['id'] }}">
                            <td class="px-4 py-3"><x-proxy.switch :on="$r['enabled']" :label="$r['domain'].' aktiv'" wire:click="toggle({{ $r['id'] }})" /></td>
                            <td class="break-all px-4 py-3 font-mono">{{ $r['domain'] }}</td>
                            <td class="break-all px-4 py-3 font-mono">{{ $r['answer'] }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1">
                                    <x-input.button type="button" size="sm" color="gray" wire:click="edit({{ $r['id'] }})">Bearbeiten</x-input.button>
                                    <x-input.button type="button" size="sm" color="gray" aria-label="{{ $r['domain'] }} löschen"
                                        x-on:click="$store.confirm.ask({ title: 'Eintrag löschen?', text: @js($r['domain'].' → '.$r['answer'].' wird gelöscht.'), button: 'Löschen', ok: () => $wire.delete({{ $r['id'] }}) })">
                                        <x-icon name="trash" class="h-4 w-4" />
                                    </x-input.button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-panel>
    @endif
</div>
