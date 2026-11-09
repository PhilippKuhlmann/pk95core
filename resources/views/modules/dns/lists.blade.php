@php
    use App\Modules\Dns\Fmt;
    use App\Modules\Dns\Lists;
    $intro = [
        'block' => 'Domains aus diesen Listen werden blockiert. Die Listen werden regelmäßig neu geladen (Einstellungen → Listen).',
        'allow' => 'Domains aus diesen Listen werden nie blockiert, auch wenn eine Blockliste sie enthält.',
    ];
@endphp

<div @if ($poll) wire:poll.2s @endif>
    <x-page-header title="Blocklisten">
        @if ($editing === null)
            <x-input.button type="button" color="gray" wire:click="refresh"><x-icon name="refresh" class="h-4 w-4" />Alle neu laden</x-input.button>
            <x-input.button type="button" wire:click="create('block')"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Liste hinzufügen</x-input.button>
        @endif
    </x-page-header>

    @if ($loadError)
        <x-callout title="PK95 DNS antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    @if ($editing !== null)
        <x-panel class="m-3" wire:key="list-form">
            <form wire:submit="save">
                <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">{{ $editing === 'new' ? 'Liste hinzufügen' : 'Liste bearbeiten' }}</div>

                @if ($catalog)
                    <x-form-section title="Bekannte Listen" first />
                    <div class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($catalog as $c)
                            <button type="button" wire:click="pick({{ Js::from($c['url']) }})" wire:key="cat-{{ md5($c['url']) }}"
                                @class(['rounded-lg border px-3 py-2 text-left text-sm transition-colors',
                                    'border-cerulean-500 bg-cerulean-50 dark:border-cerulean-500 dark:bg-cerulean-900/30' => $form['url'] === $c['url'],
                                    'border-gray-200 hover:border-cerulean-400 hover:bg-gray-50 dark:border-gray-700 dark:hover:border-cerulean-500 dark:hover:bg-gray-700/50' => $form['url'] !== $c['url']])>
                                <span class="flex items-center justify-between gap-2">
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $c['name'] }}</span>
                                    <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $c['kind'] === 'allow' ? 'Erlaubnisliste' : 'Blockliste' }}</span>
                                </span>
                                @if (($c['description'] ?? '') !== '')<span class="mt-0.5 block text-xs text-gray-600 dark:text-gray-300">{{ $c['description'] }}</span>@endif
                            </button>
                        @endforeach
                    </div>
                    <x-input.hilfe>Ein Klick füllt das Formular aus. Du kannst auch die Adresse einer eigenen Liste eintragen.</x-input.hilfe>
                @endif

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-[1fr_2fr_auto]">
                    <x-field label="Name" for="l-name" feld="form.name">
                        <x-input.text id="l-name" feld="form.name" maxlength="80" wire:model="form.name" placeholder="AdGuard DNS filter" class="mt-1 w-full" />
                    </x-field>
                    <x-field label="Adresse" for="l-url" feld="form.url" hilfe="Listen im Adblock-, Hosts- oder Domain-Format, höchstens 64 MB.">
                        <x-input.text id="l-url" feld="form.url" type="url" wire:model="form.url" placeholder="https://…" class="mt-1 w-full font-mono text-sm" />
                    </x-field>
                    <x-field label="Art" for="l-kind" feld="form.kind">
                        <x-input.select id="l-kind" feld="form.kind" wire:model="form.kind" class="mt-1">
                            <option value="block">Blockliste</option>
                            <option value="allow">Erlaubnisliste</option>
                        </x-input.select>
                    </x-field>
                </div>
                <x-input.checkbox label="Aktiv" hilfe="Aktive Listen werden sofort geladen und bei jeder Aktualisierung neu abgerufen." wire:model="form.enabled" />

                <div class="mt-6 flex justify-end gap-2">
                    <x-input.button type="button" color="gray" wire:click="cancel">Abbrechen</x-input.button>
                    <x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button>
                </div>
            </form>
        </x-panel>
    @endif

    @foreach (Lists::KINDS as $kind => $title)
        @php $items = $groups[$kind] ?? collect(); @endphp
        <x-panel class="m-3 overflow-x-auto" polster="keins" wire:key="kind-{{ $kind }}">
            <div class="flex flex-wrap items-start justify-between gap-3 p-4">
                <div>
                    <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $title }}</div>
                    <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $intro[$kind] }}</p>
                </div>
                @if ($editing === null && $kind === 'allow')
                    <x-input.button type="button" size="sm" color="gray" wire:click="create('allow')"><x-icon name="plus" class="h-3.5 w-3.5" />Erlaubnisliste hinzufügen</x-input.button>
                @endif
            </div>
            @if ($items->isEmpty())
                <p class="border-t border-gray-100 px-4 py-3 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">Noch keine {{ $title }}.</p>
            @else
                <table class="w-full border-t border-gray-100 text-left text-sm text-gray-900 dark:border-gray-700 dark:text-gray-100">
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr><th class="px-4 py-2">Aktiv</th><th class="px-4 py-2">Name</th><th class="px-4 py-2 text-right">Regeln</th><th class="px-4 py-2">Stand</th><th class="px-4 py-2"><span class="sr-only">Aktionen</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($items as $l)
                            <tr wire:key="list-{{ $l['id'] }}">
                                <td class="px-4 py-3 align-top"><x-proxy.switch :on="$l['enabled']" :label="$l['name'].' aktiv'" wire:click="toggle({{ $l['id'] }})" /></td>
                                <td class="max-w-md px-4 py-3 align-top">
                                    <div class="font-medium">{{ $l['name'] }}</div>
                                    <div class="truncate font-mono text-xs text-gray-500 dark:text-gray-400" title="{{ $l['url'] }}">{{ $l['url'] }}</div>
                                    @if (($l['last_error'] ?? '') !== '')
                                        <div class="mt-1 text-xs text-rose-700 dark:text-rose-300">{{ $l['last_error'] }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right align-top tabular-nums">{{ Fmt::num($l['rule_count']) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 align-top text-xs">
                                    @if (! empty($l['updating']))
                                        <span class="inline-flex items-center gap-1.5 text-amber-800 dark:text-amber-200"><span class="h-2 w-2 animate-pulse rounded-full {{ Fmt::dot('warn') }}"></span>Wird geladen …</span>
                                    @elseif ($l['updated_at'])
                                        {{ Fmt::dateTime($l['updated_at']) }}
                                    @else
                                        <span class="text-gray-500 dark:text-gray-400">Noch nicht geladen</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex justify-end gap-1">
                                        <x-input.button type="button" size="sm" color="gray" wire:click="refresh({{ $l['id'] }})" aria-label="{{ $l['name'] }} neu laden" title="Neu laden" :disabled="! $l['enabled'] || ! empty($l['updating'])">
                                            <x-icon name="refresh" class="h-4 w-4" />
                                        </x-input.button>
                                        <x-input.button type="button" size="sm" color="gray" wire:click="edit({{ $l['id'] }})">Bearbeiten</x-input.button>
                                        <x-input.button type="button" size="sm" color="gray" aria-label="{{ $l['name'] }} entfernen"
                                            x-on:click="$store.confirm.ask({ title: 'Liste entfernen?', text: {{ Js::from($l['name'].' wird entfernt. Ihre Regeln gelten danach nicht mehr.') }}, button: 'Entfernen', ok: () => $wire.delete({{ $l['id'] }}) })">
                                            <x-icon name="trash" class="h-4 w-4" />
                                        </x-input.button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-panel>
    @endforeach
</div>
