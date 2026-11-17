@php
    use Illuminate\Support\Js;
    $badge = 'inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium';
    $typeLabel = ['domain' => 'Domäne', 'container' => 'Container', 'builtin' => 'Builtin'];
    $protected = (bool) ($node['protected'] ?? false);
    $nameHelp = 'Bis 64 Zeichen, ohne , + " \ < > ; = / #.';
@endphp

<div>
    <x-page-header title="Organisationseinheiten">
        <x-slot:sub>Ordner für Benutzer, Gruppen und Computer. Gruppenrichtlinien werden mit der Domäne oder einer OU verknüpft und gelten für alles darin.</x-slot:sub>
        @if (! $notReady && $editing === null && $ous)
            <x-input.button type="button" wire:click="create({{ Js::from($ous[0]['id']) }})"><x-icon name="plus" class="h-4 w-4" sw="2.2" />OU anlegen</x-input.button>
        @endif
    </x-page-header>

    @include('modules.ad.partials.state')

    @if ($editing !== null)
        <x-modal wire:key="ou-form" submit="save" size="lg" :save-label="$editing === 'new' ? 'Anlegen' : 'Speichern'">
            <x-slot:title>{{ $editing === 'new' ? 'Organisationseinheit anlegen' : ($node['name'] ?? 'Organisationseinheit') }}</x-slot:title>
            @if ($protected)
                <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $typeLabel[$node['type']] ?? 'Eingebaut' }}: Name und Ort bleiben, nur die Beschreibung lässt sich ändern.</p>
            @endif
            <div class="mt-4 grid grid-cols-1 gap-4">
                <x-field label="Name" for="o-name" feld="form.name" :hilfe="$nameHelp" :required="true">
                    <x-input.text id="o-name" feld="form.name" wire:model="form.name" maxlength="64" :disabled="$protected" class="mt-1 w-full" />
                </x-field>
                @unless ($protected)
                    <x-field label="Liegt in" feld="form.parent_id">
                        <x-input.combobox wire:model="form.parent_id" label="Liegt in" :options="$parentOptions" class="mt-1" />
                    </x-field>
                @endunless
                <x-field label="Beschreibung" for="o-desc" feld="form.description">
                    <x-input.text id="o-desc" feld="form.description" wire:model="form.description" maxlength="1024" class="mt-1 w-full" />
                </x-field>
                @if ($editing !== 'new' && ($node['type'] ?? '') === 'ou')
                    <x-input.checkbox label="Vererbung blockieren" hilfe="Gruppenrichtlinien der übergeordneten OUs und der Domäne gelten hier nicht, außer sie sind erzwungen." wire:model="form.block_inheritance" />
                @endif
            </div>
        </x-modal>
    @endif

    @if ($deleting !== null)
        @php $target = $byId[$deleting] ?? ['name' => '', 'path' => '']; @endphp
        <x-modal wire:key="ou-delete" submit="delete" size="md" save-label="Löschen">
            <x-slot:title>Organisationseinheit löschen?</x-slot:title>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $target['path'] ?: $target['name'] }} wird gelöscht. Verknüpfungen von Gruppenrichtlinien mit dieser OU fallen weg.</p>
            <x-input.checkbox label="Mit allem darin löschen" hilfe="Auch Benutzer, Gruppen, Computer und Unter-OUs darin. Das lässt sich nicht rückgängig machen." wire:model="recursive" />
            <x-input.fehler feld="recursive" />
        </x-modal>
    @endif

    @if ($inheritanceFor !== null)
        <x-modal wire:key="ou-inheritance" submit="" size="lg">
            <x-slot:title>Gruppenrichtlinien für {{ ($byId[$inheritanceFor]['path'] ?? '') ?: ($byId[$inheritanceFor]['name'] ?? '') }}</x-slot:title>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Was für Benutzer und Computer hier gilt, in dieser Reihenfolge: Nummer 1 setzt sich bei widersprüchlichen Einstellungen durch.</p>
            @if (! empty($inheritance['block_inheritance']))
                <x-callout title="Vererbung blockiert" class="mt-3">Von oben gelten nur erzwungene Gruppenrichtlinien.</x-callout>
            @endif
            @if (empty($inheritance['items']))
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400" data-inheritance-empty>Hier gilt keine Gruppenrichtlinie.</p>
            @else
                <ol class="mt-4 divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm dark:divide-gray-700 dark:border-gray-700" data-inheritance>
                    @foreach ($inheritance['items'] as $item)
                        <li class="flex items-start gap-3 px-4 py-2">
                            <span class="w-6 shrink-0 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ $item['precedence'] }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $item['name'] }}</span>
                                @if ($item['enforced'])<span class="{{ $badge }} ml-1 bg-amber-100 text-amber-900 dark:bg-amber-900/60 dark:text-amber-100">Erzwungen</span>@endif
                                <span class="block text-xs text-gray-500 dark:text-gray-400">verknüpft mit {{ $item['from_name'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-modal>
    @endif

    @if ($ous)
        <x-panel class="relative m-3 overflow-x-auto" polster="keins">
            <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100" data-ous>
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr><th class="px-4 py-3">Name</th><th class="hidden px-4 py-3 md:table-cell">Beschreibung</th><th class="px-4 py-3"><span class="sr-only">Aktionen</span></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($ous as $o)
                        <tr wire:key="ou-{{ $o['id'] }}">
                            <td class="min-w-56 px-4 py-2.5 align-top">
                                <span class="flex items-start gap-2" style="padding-left: {{ min($o['depth'], 8) * 1.25 }}rem">
                                    <x-icon :name="$o['type'] === 'domain' ? 'globe' : ($o['type'] === 'ou' ? 'box' : 'list')" class="mt-0.5 h-4 w-4 shrink-0 text-gray-400" />
                                    <span class="min-w-0">
                                        @if ($o['type'] === 'builtin')
                                            <span class="text-gray-700 dark:text-gray-300">{{ $o['name'] }}</span>
                                        @else
                                            <button type="button" wire:click="edit({{ Js::from($o['id']) }})" class="text-left font-DINPro-bold text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $o['name'] }}</button>
                                        @endif
                                        @isset($typeLabel[$o['type']])<span class="{{ $badge }} ml-1 bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ $typeLabel[$o['type']] }}</span>@endisset
                                        @if ($o['block_inheritance'])<span class="{{ $badge }} ml-1 bg-amber-100 text-amber-900 dark:bg-amber-900/60 dark:text-amber-100">Vererbung blockiert</span>@endif
                                        @if ($o['description'])<span class="block text-xs text-gray-500 md:hidden dark:text-gray-400">{{ $o['description'] }}</span>@endif
                                    </span>
                                </span>
                            </td>
                            <td class="hidden px-4 py-2.5 align-top text-gray-600 md:table-cell dark:text-gray-300">{{ $o['description'] }}</td>
                            <td class="px-4 py-2.5 align-top">
                                <div class="flex justify-end gap-1">
                                    @if (in_array($o['type'], ['domain', 'ou'], true))
                                        <x-input.symbolknopf titel="OU darin anlegen" ton="grau" wire:click="create({{ Js::from($o['id']) }})"><x-icon name="plus" class="h-4 w-4" /></x-input.symbolknopf>
                                    @endif
                                    @if ($o['type'] !== 'builtin')
                                        <x-input.symbolknopf titel="Geltende Gruppenrichtlinien" ton="grau" wire:click="inheritance({{ Js::from($o['id']) }})"><x-icon name="shield" class="h-4 w-4" /></x-input.symbolknopf>
                                        <x-input.symbolknopf titel="Bearbeiten" ton="grau" wire:click="edit({{ Js::from($o['id']) }})"><x-icon name="pencil" class="h-4 w-4" /></x-input.symbolknopf>
                                    @endif
                                    @unless ($o['protected'])
                                        <x-input.symbolknopf titel="{{ $o['name'] }} löschen" ton="rot" wire:click="confirmDelete({{ Js::from($o['id']) }})"><x-icon name="trash" class="h-4 w-4" /></x-input.symbolknopf>
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
