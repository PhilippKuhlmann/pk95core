@php
    use App\Modules\Ad\Groups;
    use Illuminate\Support\Js;
    $input = 'rounded-lg border-gray-300 bg-white py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100';
    $badge = 'inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium';
    $builtin = (bool) ($details['builtin'] ?? false);
    $filtered = $q !== '' || $ou !== '' || $type !== '' || $scope !== '';
@endphp

<div>
    <x-page-header title="Gruppen">
        <x-slot:sub>Sicherheitsgruppen vergeben Rechte (etwa auf Freigaben), Verteilergruppen sind nur Listen. Domänen-Admin wird, wer in Domain Admins ist.</x-slot:sub>
        @if (! $notReady && $editing === null)
            <x-input.button type="button" wire:click="create"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Gruppe anlegen</x-input.button>
        @endif
    </x-page-header>

    @include('modules.ad.partials.state')

    @if ($editing !== null)
        <x-modal wire:key="group-form" submit="save" size="2xl" :save-label="$editing === 'new' ? 'Anlegen' : 'Speichern'">
            <x-slot:title>
                <span class="flex flex-wrap items-center gap-2">{{ $editing === 'new' ? 'Gruppe anlegen' : ($details['name'] ?? 'Gruppe bearbeiten') }}
                    @if ($builtin)<span class="{{ $badge }} bg-violet-100 font-sans text-violet-800 dark:bg-violet-900/70 dark:text-violet-100">Built-in</span>@endif
                </span>
            </x-slot:title>
            @if ($builtin)
                <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Eine eingebaute Gruppe: Name, Art, Bereich und Ort bleiben. Beschreibung und Mitglieder lassen sich ändern.</p>
            @endif
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-field label="Name" for="g-name" feld="form.name" hilfe="Bis 64 Zeichen, in der Domäne eindeutig." :required="true">
                    <x-input.text id="g-name" feld="form.name" wire:model="form.name" maxlength="64" :disabled="$builtin" class="mt-1 w-full" />
                </x-field>
                <x-field label="E-Mail" for="g-mail" feld="form.email">
                    <x-input.text id="g-mail" type="email" feld="form.email" wire:model="form.email" class="mt-1 w-full" />
                </x-field>
                <x-field label="Art" for="g-type" feld="form.type">
                    <x-input.select id="g-type" feld="form.type" wire:model="form.type" :disabled="$builtin" class="mt-1">
                        @foreach (Groups::TYPES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                    </x-input.select>
                </x-field>
                <x-field label="Bereich" for="g-scope" feld="form.scope" hilfe="Global: Konten dieser Domäne. Lokal: Rechte in dieser Domäne. Universal: über Domänen hinweg.">
                    <x-input.select id="g-scope" feld="form.scope" wire:model="form.scope" :disabled="$builtin" class="mt-1">
                        @foreach (Groups::SCOPES as $v => $l)
                            @if ($v !== 'builtin_local' || ($form['scope'] ?? '') === 'builtin_local')<option value="{{ $v }}">{{ $l }}</option>@endif
                        @endforeach
                    </x-input.select>
                </x-field>
                <x-field label="Organisationseinheit" feld="form.ou_id" hilfe="Leer beim Anlegen: der Container Users." class="md:col-span-2">
                    @if ($builtin)
                        <div class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $details['ou_path'] ?? '' }}</div>
                    @else
                        <x-input.combobox wire:model="form.ou_id" label="Organisationseinheit" :options="$ouOptions" placeholder="Users" :reset="$editing === 'new' ? 'Users (Standard)' : null" class="mt-1" />
                    @endif
                </x-field>
                <x-field label="Beschreibung" for="g-desc" feld="form.description" class="md:col-span-2">
                    <x-input.text id="g-desc" feld="form.description" wire:model="form.description" maxlength="1024" class="mt-1 w-full" />
                </x-field>
                <x-field label="Mitglieder" feld="form.member_ids" hilfe="Direkte Mitglieder: Benutzer, Gruppen und Computer." class="md:col-span-2">
                    <x-input.combobox wire:model="form.member_ids" label="Mitglieder" :options="$memberOptions" :multiple="true" :search="true" placeholder="Keine Mitglieder" many="Mitglieder" class="mt-1" />
                </x-field>
                @if ($primaryMembers = Groups::primaryMembers($details))
                    <div class="md:col-span-2" data-primary-members>
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-300">Mitglied über die primäre Gruppe</div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Für diese Konten ist {{ $details['name'] ?? 'die Gruppe' }} die primäre Gruppe. Sie bleiben Mitglied und lassen sich hier nicht entfernen; das geht nur, indem man ihnen eine andere primäre Gruppe gibt.</p>
                        <ul class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($primaryMembers as $m)
                                <li class="inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200" data-tip="Primäre Gruppe, nicht entfernbar">
                                    <x-icon name="lock" class="h-3 w-3" aria-hidden="true" />{{ $m['name'] }}@if (($m['username'] ?? '') !== '' && $m['username'] !== $m['name'])<span class="font-mono text-gray-500 dark:text-gray-400">{{ $m['username'] }}</span>@endif<span class="sr-only">(primäre Gruppe, nicht entfernbar)</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
            @if ($editing !== 'new')
                <table class="mt-4 w-full text-sm" data-group-details>
                    <x-kv label="Mitglied von">{{ collect($details['member_of'] ?? [])->pluck('name')->implode(', ') ?: '–' }}</x-kv>
                    <x-kv label="Benutzer insgesamt" :value="count($details['effective_users'] ?? []).' (auch über verschachtelte Gruppen)'" />
                    <x-kv label="SID" :value="$details['sid'] ?? null" mono />
                    <x-kv label="DN" :value="$details['dn'] ?? null" mono />
                </table>
            @endif
        </x-modal>
    @endif

    @if (! $notReady)
        <div class="mx-3 mt-2 flex flex-wrap items-center gap-2">
            <input type="search" wire:model.live.debounce.400ms="q" placeholder="Name enthält …" aria-label="Suche" class="{{ $input }} w-full sm:w-60">
            <x-input.combobox wire:model.live="ou" label="Organisationseinheit" :options="$ouOptions" placeholder="Alle OUs" reset="Alle OUs" :search="true" class="w-full sm:w-56" />
            <x-input.combobox wire:model.live="type" label="Art" :options="Groups::TYPES" placeholder="Alle Arten" reset="Alle Arten" class="w-full sm:w-48" />
            <x-input.combobox wire:model.live="scope" label="Bereich" :options="Groups::SCOPES" placeholder="Alle Bereiche" reset="Alle Bereiche" class="w-full sm:w-48" />
            @if ($filtered)
                <button type="button" wire:click="resetFilters" class="text-sm text-cerulean-700 hover:underline dark:text-cerulean-300">Filter zurücksetzen</button>
            @endif
        </div>

        @if (! $rows && ! $loadError)
            <x-emptystate :message="$filtered ? 'Keine passenden Gruppen.' : 'Noch keine Gruppen.'" />
        @elseif ($rows)
            <x-panel class="relative m-3 overflow-x-auto" polster="keins">
                <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100" data-groups>
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr><th class="px-4 py-3">Name</th><th class="hidden px-4 py-3 sm:table-cell">Art</th><th class="hidden px-4 py-3 md:table-cell">Organisationseinheit</th>
                            <th class="px-4 py-3 text-right">Mitglieder</th><th class="px-4 py-3"><span class="sr-only">Aktionen</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($rows as $g)
                            <tr wire:key="group-{{ $g['id'] }}">
                                <td class="min-w-48 px-4 py-3 align-top">
                                    <button type="button" wire:click="edit({{ Js::from($g['id']) }})" class="text-left font-DINPro-bold text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $g['name'] }}</button>
                                    @if ($g['builtin'])<span class="{{ $badge }} ml-1 bg-violet-100 text-violet-800 dark:bg-violet-900/70 dark:text-violet-100">Built-in</span>@endif
                                    @if ($g['description'])<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $g['description'] }}</span>@endif
                                    <span class="block text-xs text-gray-500 sm:hidden dark:text-gray-400">{{ Groups::TYPES[$g['type']] ?? $g['type'] }}, {{ Groups::SCOPES[$g['scope']] ?? $g['scope'] }}</span>
                                </td>
                                <td class="hidden px-4 py-3 align-top text-gray-700 sm:table-cell dark:text-gray-300">{{ Groups::TYPES[$g['type']] ?? $g['type'] }}<span class="block text-xs text-gray-500 dark:text-gray-400">{{ Groups::SCOPES[$g['scope']] ?? $g['scope'] }}</span></td>
                                <td class="hidden px-4 py-3 align-top text-gray-700 md:table-cell dark:text-gray-300">{{ $g['ou_path'] }}</td>
                                <td class="px-4 py-3 text-right align-top tabular-nums">{{ $g['member_count'] }}</td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex justify-end gap-1">
                                        <x-input.symbolknopf titel="Bearbeiten" ton="grau" wire:click="edit({{ Js::from($g['id']) }})"><x-icon name="pencil" class="h-4 w-4" /></x-input.symbolknopf>
                                        @unless ($g['protected'])
                                            <x-input.symbolknopf titel="{{ $g['name'] }} löschen" ton="rot"
                                                x-on:click="$store.confirm.ask({ title: 'Gruppe löschen?', text: {{ Js::from('Die Gruppe '.$g['name'].' wird gelöscht. Ihre Mitglieder bleiben, verlieren aber die Rechte, die über die Gruppe vergeben sind.') }}, button: 'Löschen', ok: () => $wire.delete({{ Js::from($g['id']) }}) })">
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
