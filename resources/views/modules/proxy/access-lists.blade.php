@php
    use App\Modules\Proxy\AccessLists;
    $hours = AccessLists::HOURS;
    if ($editing !== null && ! isset($hours[(int) ($form['session_hours'] ?? 12)])) {
        $hours[(int) $form['session_hours']] = $form['session_hours'].' Stunden';
    }
@endphp

<div>
    <x-page-header title="Zugriffslisten">
        @if ($editing === null)
            <x-input.button type="button" wire:click="create"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Liste hinzufügen</x-input.button>
        @endif
    </x-page-header>

    @if ($loadError)
        <x-callout title="PK95 Proxy antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    @if ($editing !== null)
        <x-panel class="m-3" wire:key="al-form">
            <form wire:submit="save">
                <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">{{ $editing === 'new' ? 'Zugriffsliste hinzufügen' : 'Zugriffsliste bearbeiten' }}</div>

                @if ($internal)
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Diese Netze gelten überall als intern: für Hosts mit dieser Liste, für Streams mit „Nur intern“ und für die Wartungs-Ausnahme. Außerdem werden sie nie automatisch gesperrt.</p>
                @else
                    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-field label="Name" for="al-name">
                            <x-input.text id="al-name" maxlength="60" wire:model="form.name" placeholder="Familie" class="mt-1 w-full" />
                        </x-field>
                    </div>

                    <x-form-section title="Bedingung" />
                    <div class="mt-2 flex flex-col gap-1 text-sm text-gray-700 dark:text-gray-300">
                        <label class="inline-flex items-center gap-2"><input type="radio" value="all" wire:model="form.mode" class="border-gray-300 text-cerulean-600 focus:ring-cerulean-500">Beides nötig: erlaubte IP-Adresse und Anmeldung</label>
                        <label class="inline-flex items-center gap-2"><input type="radio" value="any" wire:model="form.mode" class="border-gray-300 text-cerulean-600 focus:ring-cerulean-500">Eine Bedingung genügt: erlaubte IP-Adresse oder Anmeldung</label>
                        <x-input.hilfe>Gesperrte Adressen kommen nie durch. Steht bei „Beides nötig“ keine erlaubte Adresse in der Liste, reicht die Anmeldung von überall.</x-input.hilfe>
                    </div>

                    <x-form-section title="Benutzer">
                        <x-input.button type="button" size="sm" color="gray" wire:click="addUser"><x-icon name="plus" class="h-3.5 w-3.5" />Benutzer hinzufügen</x-input.button>
                    </x-form-section>
                    <div class="mt-3 flex flex-col gap-2">
                        @foreach ($form['users'] ?? [] as $i => $u)
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_auto]" wire:key="al-user-{{ $i }}">
                                <x-input.text wire:model="form.users.{{ $i }}.username" aria-label="Benutzername" autocomplete="off" maxlength="64" placeholder="Benutzername" class="w-full" />
                                <x-input.text type="password" wire:model="form.users.{{ $i }}.password" aria-label="Passwort" autocomplete="new-password" maxlength="72"
                                    placeholder="{{ $u['new'] ? 'Passwort, mindestens 8 Zeichen' : 'Unverändert lassen' }}" class="w-full" />
                                <x-input.button type="button" size="feld" color="gray" wire:click="removeUser({{ $i }})">Entfernen</x-input.button>
                            </div>
                        @endforeach
                        <x-input.hilfe>Passwörter werden mit bcrypt gespeichert. Ein neues Passwort beendet die Sitzungen des Benutzers. Ohne Benutzer gibt es keine Login-Seite, dann zählen nur die IP-Regeln.</x-input.hilfe>
                    </div>
                @endif

                <x-form-section title="IP-Regeln" />
                <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-field :label="$internal ? 'Interne Netze' : 'Erlaubt'" for="al-allow">
                        <x-input.textarea id="al-allow" rows="3" wire:model="form.allow" placeholder="192.168.1.0/24" class="mt-1" />
                    </x-field>
                    @unless ($internal)
                        <x-field label="Gesperrt" for="al-deny">
                            <x-input.textarea id="al-deny" rows="3" wire:model="form.deny" placeholder="203.0.113.7" class="mt-1" />
                        </x-field>
                    @endunless
                </div>
                <x-input.hilfe>Eine Angabe pro Zeile, einzelne Adressen oder Bereiche wie 192.168.1.0/24.</x-input.hilfe>

                @unless ($internal)
                    <x-form-section title="Login-Seite" />
                    <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-field label="Titel" for="al-title">
                            <x-input.text id="al-title" maxlength="80" wire:model="form.title" placeholder="Anmeldung" class="mt-1 w-full" />
                        </x-field>
                        <x-field label="Anmeldung gilt für" for="al-hours">
                            <x-input.select id="al-hours" wire:model="form.session_hours" class="mt-1">
                                @foreach ($hours as $v => $label)<option value="{{ $v }}">{{ $label }}</option>@endforeach
                            </x-input.select>
                        </x-field>
                    </div>
                @endunless

                <div class="mt-6 flex justify-end gap-2">
                    <x-input.button type="button" color="gray" wire:click="cancel">Abbrechen</x-input.button>
                    <x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button>
                </div>
            </form>
        </x-panel>
    @endif

    @if (! $lists && ! $loadError)
        <x-panel class="m-3" polster="weit">
            <div class="text-center">
                <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Noch keine Zugriffslisten</div>
                <p class="mx-auto mt-2 max-w-xl text-sm text-gray-500 dark:text-gray-400">Eine Zugriffsliste schützt Hosts mit Benutzern und IP-Regeln. Statt des Browser-Dialogs sehen Besucher eine eigene Login-Seite. Du kannst festlegen, ob beides nötig ist oder eine Bedingung genügt.</p>
            </div>
        </x-panel>
    @elseif ($lists)
        <x-panel class="m-3 overflow-x-auto" polster="keins">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Bedingung</th><th class="px-4 py-3">Benutzer</th><th class="px-4 py-3">IP-Regeln</th><th class="px-4 py-3">Verwendet von</th><th class="px-4 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($lists as $l)
                        <tr wire:key="al-{{ $l['id'] }}">
                            <td class="px-4 py-3 align-top">
                                <div class="font-medium text-gray-900 dark:text-gray-100">{{ $l['name'] }}</div>
                                @if ($l['is_internal'])<div class="text-xs text-gray-500">Legt deine internen Netze fest</div>@endif
                            </td>
                            <td class="px-4 py-3 align-top">{{ $l['is_internal'] ? 'Nur IP-Regeln' : (AccessLists::MODES[$l['mode']] ?? $l['mode']) }}</td>
                            <td class="px-4 py-3 align-top">{!! $l['users'] ? e(implode(', ', array_column($l['users'], 'username'))) : '<span class="text-gray-400">Keine</span>' !!}</td>
                            <td class="px-4 py-3 align-top text-xs">
                                @if ($l['allow'])<div>Erlaubt: {{ implode(', ', $l['allow']) }}</div>@endif
                                @if ($l['deny'])<div>Gesperrt: {{ implode(', ', $l['deny']) }}</div>@endif
                                @if (! $l['allow'] && ! $l['deny'])<span class="text-gray-400">Keine</span>@endif
                            </td>
                            <td class="px-4 py-3 align-top text-xs">{!! $l['used_by'] ? e(implode(', ', $l['used_by'])) : '<span class="text-gray-400">Noch kein Host</span>' !!}</td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex justify-end gap-1">
                                    <x-input.button type="button" size="sm" color="gray" wire:click="edit({{ $l['id'] }})">Bearbeiten</x-input.button>
                                    @unless ($l['is_internal'])
                                        <x-input.button type="button" size="sm" color="gray" aria-label="{{ $l['name'] }} löschen"
                                            x-on:click="$store.confirm.ask({ title: 'Liste löschen?', text: @js($l['name'].' wird gelöscht.'), button: 'Löschen', ok: () => $wire.delete({{ $l['id'] }}) })">
                                            <x-icon name="trash" class="h-4 w-4" />
                                        </x-input.button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-panel>
    @endif
</div>
