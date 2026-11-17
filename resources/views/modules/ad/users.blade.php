@php
    use App\Modules\Ad\Users;
    use Illuminate\Support\Js;
    $input = 'rounded-lg border-gray-300 bg-white py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100';
    $badge = 'inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium';
    $locked = (bool) ($details['protected'] ?? false);
    $now = now()->getTimestampMs();
@endphp

<div>
    <x-page-header title="Benutzer">
        <x-slot:sub>Konten der Domäne. Anmelden an Windows-Rechnern mit Benutzername und Kennwort oder dem Anmeldenamen (UPN).</x-slot:sub>
        @if (! $notReady && $editing === null)
            <x-input.button type="button" wire:click="create"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Benutzer anlegen</x-input.button>
        @endif
    </x-page-header>

    @include('modules.ad.partials.state')

    {{-- create / edit --}}
    @if ($editing !== null)
        <x-modal wire:key="user-form" submit="save" size="2xl" :save-label="$editing === 'new' ? 'Anlegen' : 'Speichern'">
            <x-slot:title>
                <span class="flex flex-wrap items-center gap-2">{{ $editing === 'new' ? 'Benutzer anlegen' : ($details['display_name'] ?? 'Benutzer bearbeiten') }}
                    @if ($details['builtin'] ?? false)<span class="{{ $badge }} bg-violet-100 font-sans text-violet-800 dark:bg-violet-900/70 dark:text-violet-100">Built-in</span>@endif
                </span>
            </x-slot:title>
            @if ($locked)
                <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Ein eingebautes Konto: Name, Ort und Anmeldename bleiben, wie sie sind{{ ($details['username'] ?? '') === 'Administrator' ? ', und es lässt sich nicht deaktivieren' : '' }}.</p>
            @endif

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-field label="Vorname" for="u-given" feld="form.given_name">
                    <x-input.text id="u-given" feld="form.given_name" wire:model="form.given_name" maxlength="64" class="mt-1 w-full" />
                </x-field>
                <x-field label="Nachname" for="u-sur" feld="form.surname">
                    <x-input.text id="u-sur" feld="form.surname" wire:model="form.surname" maxlength="64" class="mt-1 w-full" />
                </x-field>
                <x-field label="Anzeigename" for="u-display" feld="form.display_name" hilfe="Bis 64 Zeichen, ohne / und \. Leer: Vor- und Nachname. Zugleich der Objektname in der OU.">
                    <x-input.text id="u-display" feld="form.display_name" wire:model="form.display_name" maxlength="64" :disabled="$locked" class="mt-1 w-full" />
                </x-field>
                <x-field label="Benutzername" for="u-name" feld="form.username" hilfe="Für die Anmeldung als DOMÄNE\name, bis 20 Zeichen." :required="true">
                    <x-input.text id="u-name" feld="form.username" wire:model="form.username" maxlength="20" autocomplete="off" :disabled="$locked" class="mt-1 w-full font-mono text-sm" />
                </x-field>
                <x-field label="Anmeldename (UPN)" for="u-upn" feld="form.upn" hilfe="Leer: benutzername@domäne.">
                    <x-input.text id="u-upn" feld="form.upn" wire:model="form.upn" :disabled="$locked" class="mt-1 w-full font-mono text-sm" />
                </x-field>
                <x-field label="E-Mail" for="u-mail" feld="form.email">
                    <x-input.text id="u-mail" type="email" feld="form.email" wire:model="form.email" class="mt-1 w-full" />
                </x-field>
                <x-field label="Organisationseinheit" feld="form.ou_id" hilfe="Leer beim Anlegen: der Container Users." class="md:col-span-2">
                    @if ($locked)
                        <div class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $details['ou_path'] ?? '' }}</div>
                    @else
                        <x-input.combobox wire:model="form.ou_id" label="Organisationseinheit" :options="$ouOptions" placeholder="Users" :reset="$editing === 'new' ? 'Users (Standard)' : null" class="mt-1" />
                    @endif
                </x-field>
                <x-field label="Konto läuft ab" for="u-exp" feld="form.expires" hilfe="Letzter Tag, an dem man sich anmelden kann. Leer: nie.">
                    <x-input.text id="u-exp" type="date" feld="form.expires" wire:model="form.expires" class="mt-1 w-full" />
                </x-field>
                <x-field label="Beschreibung" for="u-desc" feld="form.description" class="md:col-span-3">
                    <x-input.text id="u-desc" feld="form.description" wire:model="form.description" maxlength="1024" class="mt-1 w-full" />
                </x-field>
                <x-field label="Gruppen" feld="form.group_ids" hilfe="Direkte Mitgliedschaften. Die primäre Gruppe (meist Domain Users) bleibt." class="md:col-span-3">
                    <x-input.combobox wire:model="form.group_ids" label="Gruppen" :options="$groupOptions" :multiple="true" :search="true" placeholder="Keine Gruppe" many="Gruppen" class="mt-1" />
                </x-field>
                @if ($editing === 'new')
                    <x-field label="Kennwort" for="u-pw" feld="form.password" hilfe="Muss der Kennwortrichtlinie der Domäne entsprechen." :required="true" class="md:col-span-2">
                        <x-input.text id="u-pw" type="password" autocomplete="new-password" feld="form.password" wire:model="form.password" class="mt-1 w-full" />
                    </x-field>
                @endif
            </div>

            <div class="mt-3 flex flex-col gap-1">
                <x-input.checkbox label="Aktiv" wire:model="form.enabled" :disabled="($details['username'] ?? '') === 'Administrator'" />
                <x-input.checkbox label="Kennwort bei der nächsten Anmeldung ändern" wire:model="form.must_change_password" />
                <x-input.checkbox label="Kennwort läuft nie ab" wire:model="form.password_never_expires" />
            </div>

            <details class="mt-4 rounded-lg border border-gray-200 dark:border-gray-700" @if (array_filter([$form['profile_path'] ?? '', $form['home_directory'] ?? '', $form['script_path'] ?? ''])) open @endif>
                <summary class="cursor-pointer px-4 py-2 text-sm font-DINPro-bold text-gray-700 dark:text-gray-200">Profil, Basisordner und Anmeldeskript</summary>
                <div class="grid grid-cols-1 gap-4 border-t border-gray-200 p-4 md:grid-cols-[2fr_2fr_1fr] dark:border-gray-700">
                    <x-field label="Profilpfad" for="u-profile" feld="form.profile_path" hilfe="Servergespeichertes Profil, UNC-Pfad.">
                        <x-input.text id="u-profile" feld="form.profile_path" wire:model="form.profile_path" placeholder="\\server\profiles\name" class="mt-1 w-full font-mono text-sm" />
                    </x-field>
                    <x-field label="Basisordner" for="u-home" feld="form.home_directory" hilfe="UNC-Pfad, wird als Laufwerk verbunden.">
                        <x-input.text id="u-home" feld="form.home_directory" wire:model="form.home_directory" placeholder="\\nas\home\name" class="mt-1 w-full font-mono text-sm" />
                    </x-field>
                    <x-field label="Laufwerk" for="u-drive" feld="form.home_drive">
                        <x-input.text id="u-drive" feld="form.home_drive" wire:model="form.home_drive" placeholder="H:" maxlength="2" class="mt-1 w-full font-mono text-sm uppercase" />
                    </x-field>
                    <x-field label="Anmeldeskript" for="u-script" feld="form.script_path" hilfe="Pfad in der NETLOGON-Freigabe, etwa logon.bat (siehe Skripte)." class="md:col-span-3">
                        <x-input.text id="u-script" feld="form.script_path" wire:model="form.script_path" placeholder="logon.bat" class="mt-1 w-full font-mono text-sm" />
                    </x-field>
                </div>
            </details>

            @if ($editing !== 'new')
                <table class="mt-4 w-full text-sm" data-user-details>
                    <x-kv label="Primäre Gruppe" :value="$details['primary_group']['name'] ?? null" />
                    <x-kv label="Letzte Anmeldung" :value="Users::when($details['last_logon'] ?? 0)" />
                    <x-kv label="Kennwort gesetzt" :value="Users::when($details['password_last_set'] ?? 0)" />
                    <x-kv label="Fehlversuche" :value="(string) ($details['bad_password_count'] ?? 0)" />
                    <x-kv label="Angelegt" :value="Users::when($details['created_at'] ?? 0)" />
                    <x-kv label="SID" :value="$details['sid'] ?? null" mono />
                    <x-kv label="DN" :value="$details['dn'] ?? null" mono />
                </table>
            @endif
        </x-modal>
    @endif

    {{-- password --}}
    @if ($passwordFor !== null)
        <x-modal wire:key="user-password" submit="savePassword" size="md" save-label="Kennwort setzen">
            <x-slot:title>Kennwort für {{ $passwordName }}</x-slot:title>
            <x-field label="Neues Kennwort" for="p-pw" feld="passwordForm.password" hilfe="Muss der Kennwortrichtlinie der Domäne entsprechen." class="mt-2">
                <x-input.text id="p-pw" type="password" autocomplete="new-password" feld="passwordForm.password" wire:model="passwordForm.password" class="mt-1 w-full" />
            </x-field>
            <x-input.checkbox label="Bei der nächsten Anmeldung ändern" wire:model="passwordForm.must_change_password" />
        </x-modal>
    @endif

    @if (! $notReady)
        <div class="mx-3 mt-2 flex flex-wrap items-center gap-2">
            <input type="search" wire:model.live.debounce.400ms="q" placeholder="Name, Benutzername oder E-Mail …" aria-label="Suche" class="{{ $input }} w-full sm:w-72">
            <x-input.combobox wire:model.live="ou" label="Organisationseinheit" :options="$ouOptions" placeholder="Alle OUs" reset="Alle OUs" :search="true" class="w-full sm:w-64" />
            @if ($q !== '' || $ou !== '')
                <button type="button" wire:click="resetFilters" class="text-sm text-cerulean-700 hover:underline dark:text-cerulean-300">Filter zurücksetzen</button>
            @endif
        </div>

        @if (! $rows && ! $loadError)
            <x-emptystate :message="$q !== '' || $ou !== '' ? 'Keine passenden Benutzer.' : 'Noch keine Benutzer.'" />
        @elseif ($rows)
            <x-panel class="relative m-3 overflow-x-auto" polster="keins">
                <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100" data-users>
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr><th class="px-4 py-3">Aktiv</th><th class="px-4 py-3">Name</th><th class="hidden px-4 py-3 md:table-cell">Organisationseinheit</th>
                            <th class="hidden px-4 py-3 lg:table-cell">Letzte Anmeldung</th><th class="px-4 py-3"><span class="sr-only">Aktionen</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($rows as $u)
                            @php
                                $expired = ($u['expires_at'] ?? 0) > 0 && $u['expires_at'] < $now;
                                $isAdmin = $u['builtin'] && $u['username'] === 'Administrator';
                            @endphp
                            <tr wire:key="user-{{ $u['id'] }}">
                                <td class="px-4 py-3 align-top">
                                    @if ($isAdmin)
                                        <span class="{{ $badge }} bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300" data-tip="Der Administrator lässt sich nicht deaktivieren">immer</span>
                                    @else
                                        <x-proxy.switch :on="$u['enabled']" :label="$u['username'].' aktiv'" wire:click="toggle({{ Js::from($u['id']) }}, {{ $u['enabled'] ? 'true' : 'false' }})" />
                                    @endif
                                </td>
                                <td class="min-w-48 px-4 py-3 align-top">
                                    <button type="button" wire:click="edit({{ Js::from($u['id']) }})" class="text-left font-DINPro-bold text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $u['display_name'] ?: $u['username'] }}</button>
                                    <span class="block font-mono text-xs text-gray-500 dark:text-gray-400">{{ $u['username'] }}@if ($u['email']) · {{ $u['email'] }}@endif</span>
                                    <span class="mt-1 flex flex-wrap gap-1">
                                        @if ($u['builtin'])<span class="{{ $badge }} bg-violet-100 text-violet-800 dark:bg-violet-900/70 dark:text-violet-100">Built-in</span>@endif
                                        @if (! $u['enabled'])<span class="{{ $badge }} bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200">Deaktiviert</span>@endif
                                        @if ($u['locked'])<span class="{{ $badge }} bg-red-100 text-red-800 dark:bg-red-900/60 dark:text-red-100">Gesperrt</span>@endif
                                        @if ($expired)<span class="{{ $badge }} bg-amber-100 text-amber-900 dark:bg-amber-900/60 dark:text-amber-100">Abgelaufen</span>
                                        @elseif (($u['expires_at'] ?? 0) > 0)<span class="{{ $badge }} bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200">Läuft ab {{ \App\Http\Support\Format::fromMs($u['expires_at'])->format('d.m.Y') }}</span>@endif
                                        @if ($u['must_change_password'])<span class="{{ $badge }} bg-cerulean-100 text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-100">Kennwort ändern</span>@endif
                                    </span>
                                    <span class="mt-1 block text-xs text-gray-500 md:hidden dark:text-gray-400">{{ $u['ou_path'] }}</span>
                                </td>
                                <td class="hidden px-4 py-3 align-top text-gray-700 md:table-cell dark:text-gray-300">{{ $u['ou_path'] }}</td>
                                <td class="hidden whitespace-nowrap px-4 py-3 align-top tabular-nums text-gray-700 lg:table-cell dark:text-gray-300">{{ Users::when($u['last_logon']) }}</td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex justify-end gap-1">
                                        @if ($u['locked'])
                                            <x-input.button type="button" size="sm" color="gray" wire:click="unlock({{ Js::from($u['id']) }})">Entsperren</x-input.button>
                                        @endif
                                        <x-input.symbolknopf titel="Kennwort setzen" ton="grau" wire:click="password({{ Js::from($u['id']) }}, {{ Js::from($u['username']) }})"><x-icon name="key" class="h-4 w-4" /></x-input.symbolknopf>
                                        <x-input.symbolknopf titel="Bearbeiten" ton="grau" wire:click="edit({{ Js::from($u['id']) }})"><x-icon name="pencil" class="h-4 w-4" /></x-input.symbolknopf>
                                        @unless ($u['protected'])
                                            <x-input.symbolknopf titel="{{ $u['username'] }} löschen" ton="rot"
                                                x-on:click="$store.confirm.ask({ title: 'Benutzer löschen?', text: {{ Js::from(($u['display_name'] ?: $u['username']).' ('.$u['username'].') wird gelöscht. Das lässt sich nicht rückgängig machen; ein neues Konto mit demselben Namen bekommt eine neue SID.') }}, button: 'Löschen', ok: () => $wire.delete({{ Js::from($u['id']) }}) })">
                                                <x-icon name="trash" class="h-4 w-4" />
                                            </x-input.symbolknopf>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($total > count($rows))
                    <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">{{ count($rows) }} von {{ $total }} angezeigt. Grenze die Suche ein.</p>
                @endif
            </x-panel>
        @endif
    @endif
</div>
