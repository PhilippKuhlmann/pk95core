@php
    use App\Modules\Ad\Gpos;
    use Illuminate\Support\Js;
    $badge = 'inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium';
    $editingGpo = $editing !== null && $editing !== 'new' ? collect($gpos)->firstWhere('id', $editing) : null;
@endphp

<div>
    <x-page-header title="Gruppenrichtlinien">
        <x-slot:sub>Einstellungen für Windows-Rechner und Benutzer. Eine Gruppenrichtlinie gilt erst, wenn sie mit der Domäne oder einer OU verknüpft ist.</x-slot:sub>
        @if (! $notReady && $editing === null)
            <x-input.button type="button" wire:click="create"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Gruppenrichtlinie anlegen</x-input.button>
        @endif
    </x-page-header>

    @include('modules.ad.partials.state')

    {{-- create / rename / status --}}
    @if ($editing !== null)
        <x-modal wire:key="gpo-form" submit="save" size="md" :save-label="$editing === 'new' ? 'Anlegen' : 'Speichern'">
            <x-slot:title>{{ $editing === 'new' ? 'Gruppenrichtlinie anlegen' : ($editingGpo['name'] ?? 'Gruppenrichtlinie') }}</x-slot:title>
            <div class="mt-2 grid grid-cols-1 gap-4">
                <x-field label="Name" for="gpo-name" feld="form.name" :hilfe="($editingGpo['builtin'] ?? false) ? 'Eingebaute Gruppenrichtlinien behalten ihren Namen.' : 'Bis 200 Zeichen, eindeutig.'" :required="true">
                    <x-input.text id="gpo-name" feld="form.name" wire:model="form.name" maxlength="200" :disabled="(bool) ($editingGpo['builtin'] ?? false)" class="mt-1 w-full" />
                </x-field>
                @if ($editing !== 'new')
                    <x-field label="Status" for="gpo-status" feld="form.status" hilfe="Ein ausgeschalteter Teil gilt nicht, die Verknüpfungen bleiben.">
                        <x-input.select id="gpo-status" feld="form.status" wire:model="form.status" class="mt-1">
                            @foreach (Gpos::STATUS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </x-input.select>
                    </x-field>
                @endif
            </div>
        </x-modal>
    @endif

    {{-- links --}}
    @if ($linksFor !== null && $current)
        <x-modal wire:key="gpo-links" submit="" size="xl">
            <x-slot:title>Verknüpfungen von {{ $current['name'] }}</x-slot:title>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Die Gruppenrichtlinie gilt für Benutzer und Computer in diesen Containern und darunter. Erzwungen setzt sie sich gegen Richtlinien weiter unten und gegen blockierte Vererbung durch. Reihenfolge 1 hat am selben Container Vorrang.</p>
            @if (! $current['links'])
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">Noch nicht verknüpft: Die Gruppenrichtlinie gilt nirgends.</p>
            @else
                <ul class="mt-4 divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm dark:divide-gray-700 dark:border-gray-700" data-gpo-links>
                    @foreach ($current['links'] as $link)
                        @php
                            $where = $link['target_path'] !== '' ? $link['target_path'] : $link['target_name'].' (Domäne)';
                            $count = $linkCounts[$link['target_id']] ?? 1;
                            $args = Js::from($current['id']).', '.Js::from($link['target_id']);
                            // comparisons stay out of the component attributes: a ">" there ends the tag
                            $isFirst = $link['order'] <= 1;
                            $isLast = $link['order'] >= $count;
                        @endphp
                        <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3" wire:key="link-{{ $link['target_id'] }}">
                            <span class="min-w-40 flex-1 font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $where }}</span>
                            <span class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                <x-proxy.switch :on="$link['enabled']" :label="'Verknüpfung mit '.$where.' aktiv'" wire:click="updateLink({{ $args }}, 'enabled', {{ $link['enabled'] ? 'false' : 'true' }})" />Aktiv
                            </span>
                            <span class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                <x-proxy.switch :on="$link['enforced']" :label="'Verknüpfung mit '.$where.' erzwungen'" wire:click="updateLink({{ $args }}, 'enforced', {{ $link['enforced'] ? 'false' : 'true' }})" />Erzwungen
                            </span>
                            <span class="flex items-center gap-1">
                                <span class="w-14 text-xs tabular-nums text-gray-500 dark:text-gray-400" data-tip="Verknüpfungsreihenfolge an diesem Container">{{ $link['order'] }} von {{ $count }}</span>
                                <x-input.symbolknopf titel="Vorrang erhöhen" ton="grau" :disabled="$isFirst" wire:click="updateLink({{ $args }}, 'order', {{ $link['order'] - 1 }})"><x-icon name="chevron-down" class="h-4 w-4 rotate-180" /></x-input.symbolknopf>
                                <x-input.symbolknopf titel="Vorrang senken" ton="grau" :disabled="$isLast" wire:click="updateLink({{ $args }}, 'order', {{ $link['order'] + 1 }})"><x-icon name="chevron-down" class="h-4 w-4" /></x-input.symbolknopf>
                                <x-input.symbolknopf titel="Verknüpfung mit {{ $where }} entfernen" ton="rot"
                                    x-on:click="$store.confirm.ask({ title: 'Verknüpfung entfernen?', text: {{ Js::from($current['name'].' gilt danach nicht mehr für '.$where.'. Die Gruppenrichtlinie selbst bleibt.') }}, button: 'Entfernen', ok: () => $wire.removeLink({{ $args }}) })">
                                    <x-icon name="trash" class="h-4 w-4" />
                                </x-input.symbolknopf>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($linkOptions)
                <div class="mt-4 flex flex-wrap items-end gap-2">
                    <x-field label="Verknüpfen mit" feld="linkTarget" class="min-w-60 flex-1">
                        <x-input.combobox wire:model="linkTarget" label="Verknüpfen mit" :options="$linkOptions" placeholder="Domäne oder OU wählen" class="mt-1" />
                    </x-field>
                    <x-input.button type="button" size="feld" wire:click="addLink"><x-icon name="link" class="h-4 w-4" />Verknüpfen</x-input.button>
                </div>
            @endif
        </x-modal>
    @endif

    {{-- settings: tabs Computer / Benutzer / Netzlaufwerke, built from the catalog --}}
    @if ($settingsFor !== null)
        @include('modules.ad.partials.gpo-settings')
    @endif

    @if (! $notReady)
        @if (! $gpos && ! $loadError)
            <x-emptystate message="Noch keine Gruppenrichtlinien." />
        @elseif ($gpos)
            <x-panel class="relative m-3 overflow-x-auto" polster="keins">
                <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100" data-gpos>
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Verknüpft mit</th><th class="hidden px-4 py-3 lg:table-cell">Geändert</th>
                            <th class="px-4 py-3"><span class="sr-only">Aktionen</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($gpos as $gpo)
                            <tr wire:key="gpo-{{ $gpo['id'] }}">
                                <td class="min-w-48 px-4 py-3 align-top">
                                    <button type="button" wire:click="openSettings({{ Js::from($gpo['id']) }})" class="text-left font-DINPro-bold text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $gpo['name'] }}</button>
                                    <span class="mt-1 flex flex-wrap gap-1">
                                        @if ($gpo['builtin'])<span class="{{ $badge }} bg-violet-100 text-violet-800 dark:bg-violet-900/70 dark:text-violet-100">Built-in</span>@endif
                                        {{-- preferences lists (counts from "preference_counts", drive maps before 1.3.0 from "drive_count") --}}
                                        @foreach (\App\Modules\Ad\Gpp\Kinds::all() as $kindKey => $listKind)
                                            @php $listCount = (int) ($gpo['preference_counts'][$kindKey] ?? ($kindKey === 'drives' ? ($gpo['drive_count'] ?? 0) : 0)); @endphp
                                            @if ($listCount > 0)<span class="{{ $badge }} bg-cerulean-100 text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-100" data-gpp-count="{{ $kindKey }}">{{ $listKind->listCount($listCount) }}</span>@endif
                                        @endforeach
                                        @if ($gpo['status'] !== 'enabled')<span class="{{ $badge }} bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ Gpos::STATUS[$gpo['status']] ?? $gpo['status'] }}</span>@endif
                                        @unless ($gpo['sysvol_ok'] ?? true)<span class="{{ $badge }} bg-amber-100 text-amber-900 dark:bg-amber-900/60 dark:text-amber-100" data-tip="Der Ordner in SYSVOL fehlt oder hat eine andere Version als das Verzeichnis. Die Datenbankprüfung auf der Seite Domäne hilft.">SYSVOL prüfen</span>@endunless
                                    </span>
                                    <span class="mt-1 block font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ $gpo['guid'] }}</span>
                                </td>
                                <td class="min-w-40 px-4 py-3 align-top">
                                    @forelse ($gpo['links'] as $link)
                                        <span @class(['mr-1 mb-1 inline-flex items-center gap-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-800 dark:bg-gray-700 dark:text-gray-100', 'opacity-60 line-through' => ! $link['enabled']])>
                                            {{ $link['target_path'] !== '' ? $link['target_path'] : $link['target_name'] }}
                                            @if ($link['enforced'])<span class="font-DINPro-bold text-amber-700 dark:text-amber-300" data-tip="Erzwungen" aria-hidden="true">!</span><span class="sr-only">erzwungen</span>@endif
                                            @unless ($link['enabled'])<span class="sr-only">(deaktiviert)</span>@endunless
                                        </span>
                                    @empty
                                        <span class="text-xs text-gray-500 dark:text-gray-400">nicht verknüpft</span>
                                    @endforelse
                                </td>
                                <td class="hidden whitespace-nowrap px-4 py-3 align-top tabular-nums text-gray-700 lg:table-cell dark:text-gray-300">{{ Gpos::when($gpo['changed_at']) }}</td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex justify-end gap-1">
                                        <x-input.symbolknopf titel="Einstellungen" ton="grau" wire:click="openSettings({{ Js::from($gpo['id']) }})"><x-icon name="cog" class="h-4 w-4" /></x-input.symbolknopf>
                                        <x-input.symbolknopf titel="Verknüpfungen" ton="grau" wire:click="links({{ Js::from($gpo['id']) }})"><x-icon name="link" class="h-4 w-4" /></x-input.symbolknopf>
                                        <x-input.symbolknopf titel="Name und Status" ton="grau" wire:click="edit({{ Js::from($gpo['id']) }})"><x-icon name="pencil" class="h-4 w-4" /></x-input.symbolknopf>
                                        @unless ($gpo['builtin'])
                                            <x-input.symbolknopf titel="{{ $gpo['name'] }} löschen" ton="rot"
                                                x-on:click="$store.confirm.ask({ title: 'Gruppenrichtlinie löschen?', text: {{ Js::from($gpo['name'].' wird mit allen Verknüpfungen und dem Ordner in SYSVOL gelöscht.') }}, button: 'Löschen', ok: () => $wire.delete({{ Js::from($gpo['id']) }}) })">
                                                <x-icon name="trash" class="h-4 w-4" />
                                            </x-input.symbolknopf>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-panel>
        @endif

        {{-- inheritance --}}
        @if ($targetOptions)
            <x-panel class="m-3" data-gpo-inheritance>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Vererbung</div>
                        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Welche Gruppenrichtlinien für Objekte in einem Container gelten, in dieser Reihenfolge. Nummer 1 setzt sich durch.</p>
                    </div>
                    <x-input.combobox wire:model.live="target" label="Container" :options="$targetOptions" :search="true" class="w-full sm:w-64" />
                </div>
                @if (($targetNode['type'] ?? '') === 'ou')
                    <div class="mt-4 flex items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
                        <x-proxy.switch :on="$targetNode['block_inheritance']" label="Vererbung blockieren" wire:click="blockInheritance({{ Js::from($targetNode['id']) }}, {{ $targetNode['block_inheritance'] ? 'true' : 'false' }})" />
                        <span>Vererbung blockieren <span class="block text-xs text-gray-500 dark:text-gray-400">Gruppenrichtlinien von oben gelten hier nur, wenn sie erzwungen sind.</span></span>
                    </div>
                @endif
                @if (empty($inheritance['items']))
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">Hier gilt keine Gruppenrichtlinie.</p>
                @else
                    <ol class="mt-4 divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm dark:divide-gray-700 dark:border-gray-700">
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
            </x-panel>
        @endif
    @endif
</div>
