@php
    use App\Modules\Dns\Fmt;
    $card = 'font-DINPro-bold text-gray-900 dark:text-gray-100';
    $muted = 'text-gray-500 dark:text-gray-400';
@endphp

<div>
    <x-page-header title="Clients">
        <x-slot:sub>Eigene Einstellungen für einzelne Geräte oder Netze. Die genaueste Angabe gewinnt: eine IP-Adresse vor dem kleinsten Netz.</x-slot:sub>
        @if ($editing === null)
            <x-input.button type="button" wire:click="create"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Client hinzufügen</x-input.button>
        @endif
    </x-page-header>

    @if ($loadError)
        <x-callout title="PK95 DNS antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    @if ($editing !== null)
        <x-panel class="m-3" wire:key="client-form">
            <form wire:submit="save">
                <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">{{ $editing === 'new' ? 'Client hinzufügen' : 'Client bearbeiten' }}</div>
                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-field label="Name" for="c-name" feld="form.name">
                        <x-input.text id="c-name" feld="form.name" maxlength="80" wire:model="form.name" placeholder="Kinder-Tablet" class="mt-1 w-full" />
                    </x-field>
                    <x-field label="IP-Adressen und Netze" for="c-ids" feld="form.ids" hilfe="Eine Angabe pro Zeile, etwa 192.168.1.40 oder 192.168.50.0/24. Höchstens 50.">
                        <x-input.textarea id="c-ids" feld="form.ids" rows="3" wire:model="form.ids" placeholder="192.168.1.40" class="mt-1" />
                    </x-field>
                </div>

                <x-form-section title="Filter und SafeSearch" />
                <x-input.checkbox label="Globale Einstellungen verwenden" hilfe="Filter und SafeSearch wie unter Einstellungen." wire:model.live="form.use_global_settings" />
                @unless ($form['use_global_settings'])
                    <div class="mt-2 flex flex-col gap-1 border-l-2 border-gray-200 pl-4 dark:border-gray-700">
                        <x-input.checkbox label="Filtern" hilfe="Aus: Für diesen Client wird nichts blockiert." wire:model="form.filtering_enabled" />
                        <x-input.checkbox label="SafeSearch erzwingen" wire:model.live="form.safesearch.enabled" />
                        @if ($form['safesearch']['enabled'])
                            <div class="ml-6 flex flex-wrap gap-x-5">
                                @foreach (Fmt::ENGINES as $key => $label)
                                    <x-input.checkbox :label="$label" wire:model="form.safesearch.{{ $key }}" />
                                @endforeach
                            </div>
                        @endif
                        <x-input.fehler feld="form.safesearch" />
                    </div>
                @endunless

                <x-form-section title="Blockierte Dienste" />
                <x-input.checkbox label="Global blockierte Dienste verwenden" wire:model.live="form.use_global_services" />
                @unless ($form['use_global_services'])
                    <div class="mt-2 grid grid-cols-1 gap-4 border-l-2 border-gray-200 pl-4 sm:grid-cols-2 xl:grid-cols-4 dark:border-gray-700">
                        @foreach ($serviceGroups as $group => $services)
                            <div wire:key="cg-{{ $group }}">
                                <div class="text-xs font-semibold uppercase tracking-wide {{ $muted }}">{{ Fmt::GROUPS[$group] }}</div>
                                @foreach ($services as $s)
                                    <label class="mt-1 flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200">
                                        <input type="checkbox" value="{{ $s['id'] }}" wire:model="form.blocked_services" class="rounded border-gray-300 text-rose-600 focus:ring-rose-500 dark:border-gray-600 dark:bg-gray-700">{{ $s['name'] }}
                                    </label>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    <x-input.fehler feld="form.blocked_services" />
                @endunless

                <div class="mt-6 flex justify-end gap-2">
                    <x-input.button type="button" color="gray" wire:click="cancel">Abbrechen</x-input.button>
                    <x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button>
                </div>
            </form>
        </x-panel>
    @endif

    @if ($clients)
        <x-panel class="m-3 overflow-x-auto" polster="keins">
            <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Adressen</th><th class="px-4 py-3">Filter</th><th class="px-4 py-3">SafeSearch</th><th class="px-4 py-3">Dienste</th><th class="px-4 py-3"><span class="sr-only">Aktionen</span></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($clients as $c)
                        <tr wire:key="client-{{ $c['id'] }}">
                            <td class="px-4 py-3 align-top font-medium">{{ $c['name'] }}</td>
                            <td class="px-4 py-3 align-top font-mono text-xs">{!! implode('<br>', array_map('e', $c['ids'])) !!}</td>
                            <td class="px-4 py-3 align-top">
                                @if ($c['use_global_settings'])<span class="{{ $muted }}">Global</span>
                                @else {{ $c['filtering_enabled'] ? 'An' : 'Aus' }} @endif
                            </td>
                            <td class="px-4 py-3 align-top">
                                @if ($c['use_global_settings'])<span class="{{ $muted }}">Global</span>
                                @else {{ ! empty($c['safesearch']['enabled']) ? 'An' : 'Aus' }} @endif
                            </td>
                            <td class="max-w-xs px-4 py-3 align-top text-xs">
                                @if ($c['use_global_services'])<span class="{{ $muted }}">Global</span>
                                @elseif (! $c['blocked_services'])<span class="{{ $muted }}">Keine blockiert</span>
                                @else {{ implode(', ', array_map(fn ($id) => $serviceNames[$id] ?? $id, $c['blocked_services'])) }} @endif
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex justify-end gap-1">
                                    <x-input.button type="button" size="sm" color="gray" wire:click="edit({{ $c['id'] }})">Bearbeiten</x-input.button>
                                    <x-input.button type="button" size="sm" color="gray" aria-label="{{ $c['name'] }} löschen"
                                        x-on:click="$store.confirm.ask({ title: 'Client löschen?', text: {{ Js::from($c['name'].' wird gelöscht. Für seine Adressen gelten danach die globalen Einstellungen.') }}, button: 'Löschen', ok: () => $wire.delete({{ $c['id'] }}) })">
                                        <x-icon name="trash" class="h-4 w-4" />
                                    </x-input.button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-panel>
    @elseif (! $loadError)
        <x-panel class="m-3" polster="weit">
            <div class="text-center">
                <div class="{{ $card }}">Noch keine Clients</div>
                <p class="mx-auto mt-2 max-w-xl text-sm {{ $muted }}">Ohne eigene Clients gelten für alle Geräte die globalen Einstellungen. Lege einen Client an, um etwa auf dem Tablet der Kinder SafeSearch zu erzwingen oder TikTok zu sperren.</p>
            </div>
        </x-panel>
    @endif

    <x-panel class="m-3 overflow-x-auto" polster="keins">
        <div class="p-4">
            <div class="{{ $card }}">Zuletzt gesehen</div>
            <p class="mt-1 text-sm {{ $muted }}">Geräte, die in den letzten 24 Stunden gefragt haben.</p>
        </div>
        @if (! $recent)
            <p class="border-t border-gray-100 px-4 py-3 text-sm dark:border-gray-700 {{ $muted }}">Noch keine Anfragen.</p>
        @else
            <table class="w-full border-t border-gray-100 text-left text-sm text-gray-900 dark:border-gray-700 dark:text-gray-100">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr><th class="px-4 py-2">Adresse</th><th class="px-4 py-2">Hostname</th><th class="px-4 py-2 text-right">Anfragen</th><th class="px-4 py-2 text-right">Blockiert</th><th class="px-4 py-2">Zuletzt</th><th class="px-4 py-2"><span class="sr-only">Aktionen</span></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($recent as $r)
                        <tr wire:key="recent-{{ $r['ip'] }}">
                            <td class="px-4 py-2 font-mono text-xs">{{ $r['ip'] }}</td>
                            <td class="break-all px-4 py-2">{!! $r['hostname'] !== '' ? e($r['hostname']) : '<span class="text-gray-500 dark:text-gray-400">–</span>' !!}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ Fmt::num($r['queries']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ Fmt::num($r['blocked']) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-xs">{{ Fmt::when($r['last_seen']) }}</td>
                            <td class="px-4 py-2 text-right">
                                @if ($r['client_id'])
                                    <span class="text-xs text-gray-600 dark:text-gray-300">{{ $r['name'] }}</span>
                                @elseif ($editing === null)
                                    <x-input.button type="button" size="sm" color="gray" wire:click="create({{ Js::from($r['ip']) }}, {{ Js::from($r['hostname'] !== '' ? explode('.', $r['hostname'])[0] : '') }})">Als Client anlegen</x-input.button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-panel>
</div>
