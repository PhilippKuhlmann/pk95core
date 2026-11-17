@php
    use App\Modules\Ad\Computers;
    use Illuminate\Support\Js;
    $input = 'rounded-lg border-gray-300 bg-white py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100';
    $badge = 'inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium';
    $dc = (bool) ($details['is_dc'] ?? false);
@endphp

<div>
    <x-page-header title="Computer">
        <x-slot:sub>Rechner, die der Domäne beigetreten sind. Beitreten geht am Rechner selbst (Systemsteuerung → Domäne) mit einem Domänenkonto.</x-slot:sub>
    </x-page-header>

    @include('modules.ad.partials.state')

    @if ($editing !== null)
        <x-modal wire:key="computer-form" submit="save" size="lg">
            <x-slot:title>
                <span class="flex flex-wrap items-center gap-2">{{ $details['name'] ?? 'Computer' }}
                    @if ($dc)<span class="{{ $badge }} bg-violet-100 font-sans text-violet-800 dark:bg-violet-900/70 dark:text-violet-100">Domänencontroller</span>@endif
                </span>
            </x-slot:title>
            @if ($dc)
                <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Ein Domänencontroller bleibt aktiv und in Domain Controllers. Nur die Beschreibung lässt sich ändern.</p>
            @endif
            <div class="mt-4 grid grid-cols-1 gap-4">
                <x-field label="Beschreibung" for="c-desc" feld="form.description">
                    <x-input.text id="c-desc" feld="form.description" wire:model="form.description" maxlength="1024" class="mt-1 w-full" />
                </x-field>
                @unless ($dc)
                    <x-field label="Organisationseinheit" feld="form.ou_id" hilfe="Gruppenrichtlinien gelten nach der OU, in der der Computer liegt.">
                        <x-input.combobox wire:model="form.ou_id" label="Organisationseinheit" :options="$ouOptions" class="mt-1" />
                    </x-field>
                    <x-input.checkbox label="Aktiv" hilfe="Ein deaktivierter Computer kann sich nicht mehr an der Domäne anmelden." wire:model="form.enabled" />
                @endunless
            </div>
            <table class="mt-4 w-full text-sm" data-computer-details>
                <x-kv label="DNS-Name" :value="$details['dns_hostname'] ?? null" mono />
                <x-kv label="Betriebssystem" :value="trim(($details['os'] ?? '').' '.($details['os_version'] ?? ''))" />
                <x-kv label="Letzte Anmeldung" :value="Computers::when($details['last_logon'] ?? 0)" />
                <x-kv label="Beigetreten" :value="Computers::when($details['created_at'] ?? 0)" />
                <x-kv label="Gruppen">{{ collect($details['groups'] ?? [])->pluck('name')->implode(', ') ?: '–' }}</x-kv>
                <x-kv label="SID" :value="$details['sid'] ?? null" mono />
                <x-kv label="DN" :value="$details['dn'] ?? null" mono />
            </table>
        </x-modal>
    @endif

    @if (! $notReady)
        <div class="mx-3 mt-2 flex flex-wrap items-center gap-2">
            <input type="search" wire:model.live.debounce.400ms="q" placeholder="Name enthält …" aria-label="Suche" class="{{ $input }} w-full sm:w-60">
            <x-input.combobox wire:model.live="ou" label="Organisationseinheit" :options="$ouOptions" placeholder="Alle OUs" reset="Alle OUs" :search="true" class="w-full sm:w-64" />
            @if ($q !== '' || $ou !== '')
                <button type="button" wire:click="resetFilters" class="text-sm text-cerulean-700 hover:underline dark:text-cerulean-300">Filter zurücksetzen</button>
            @endif
        </div>

        @if (! $rows && ! $loadError)
            <x-emptystate :message="$q !== '' || $ou !== '' ? 'Keine passenden Computer.' : 'Noch kein Computer ist der Domäne beigetreten.'" />
        @elseif ($rows)
            <x-panel class="relative m-3 overflow-x-auto" polster="keins">
                <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100" data-computers>
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr><th class="px-4 py-3">Aktiv</th><th class="px-4 py-3">Name</th><th class="hidden px-4 py-3 md:table-cell">Betriebssystem</th>
                            <th class="hidden px-4 py-3 lg:table-cell">Organisationseinheit</th><th class="hidden px-4 py-3 lg:table-cell">Letzte Anmeldung</th>
                            <th class="px-4 py-3"><span class="sr-only">Aktionen</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($rows as $c)
                            <tr wire:key="computer-{{ $c['id'] }}">
                                <td class="px-4 py-3 align-top">
                                    @if ($c['is_dc'])
                                        <span class="{{ $badge }} bg-violet-100 text-violet-800 dark:bg-violet-900/70 dark:text-violet-100" data-tip="Domänencontroller bleiben aktiv">DC</span>
                                    @else
                                        <x-proxy.switch :on="$c['enabled']" :label="$c['name'].' aktiv'" wire:click="toggle({{ Js::from($c['id']) }}, {{ $c['enabled'] ? 'true' : 'false' }})" />
                                    @endif
                                </td>
                                <td class="min-w-40 px-4 py-3 align-top">
                                    <button type="button" wire:click="edit({{ Js::from($c['id']) }})" class="text-left font-DINPro-bold text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $c['name'] }}</button>
                                    <span class="block font-mono text-xs text-gray-500 dark:text-gray-400">{{ $c['dns_hostname'] }}</span>
                                    @if ($c['description'])<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $c['description'] }}</span>@endif
                                    <span class="block text-xs text-gray-500 md:hidden dark:text-gray-400">{{ $c['os'] }}</span>
                                </td>
                                <td class="hidden px-4 py-3 align-top text-gray-700 md:table-cell dark:text-gray-300">{{ $c['os'] }}<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $c['os_version'] }}</span></td>
                                <td class="hidden px-4 py-3 align-top text-gray-700 lg:table-cell dark:text-gray-300">{{ $c['ou_path'] }}</td>
                                <td class="hidden whitespace-nowrap px-4 py-3 align-top tabular-nums text-gray-700 lg:table-cell dark:text-gray-300">{{ Computers::when($c['last_logon']) }}</td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex justify-end gap-1">
                                        <x-input.symbolknopf titel="Bearbeiten" ton="grau" wire:click="edit({{ Js::from($c['id']) }})"><x-icon name="pencil" class="h-4 w-4" /></x-input.symbolknopf>
                                        @unless ($c['is_dc'])
                                            <x-input.symbolknopf titel="{{ $c['name'] }} entfernen" ton="rot"
                                                x-on:click="$store.confirm.ask({ title: 'Computer entfernen?', text: {{ Js::from($c['name'].' wird aus der Domäne entfernt. Danach kann sich dort niemand mehr mit einem Domänenkonto anmelden, bis der Rechner erneut beitritt.') }}, button: 'Entfernen', ok: () => $wire.delete({{ Js::from($c['id']) }}) })">
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
