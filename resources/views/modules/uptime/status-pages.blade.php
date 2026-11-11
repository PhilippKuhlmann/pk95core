<div>
    <x-page-header title="Statusseiten">
        <x-slot:sub>Öffentliche Seiten von PK95 Uptime unter {{ $publicBase !== '' ? $publicBase : '' }}/status/&lt;adresse&gt;. Sie zeigen nur die Anzeigenamen, nie die Ziele.</x-slot:sub>
        @if ($editing === null)
            <x-input.button type="button" wire:click="create"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Statusseite anlegen</x-input.button>
        @endif
    </x-page-header>

    @if ($loadError)
        <x-callout title="PK95 Uptime antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    {{-- public address (Core setting for the links), proxy host and local DNS record (Linker::linkStatusPages) --}}
    @if ($editing === null)
        @php
            $linkState = $statusLink && $statusLink->state !== \App\Models\AppLink::REMOVED && $publicBase !== '' ? $statusLink->state : null;
            $stateTone = [
                'active' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100',
                'pending_dns' => 'bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100',
                'failed' => 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-100',
            ];
        @endphp
        <x-panel class="m-3" data-public-address>
            <form wire:submit="savePublicUrl" class="flex flex-wrap items-start gap-3">
                <x-field label="Öffentliche Adresse der Statusseiten" for="sp-public" feld="publicUrl" class="min-w-60 flex-1"
                    hilfe="Seiten liegen unter <Adresse>/status/<adresse-der-seite>. Leer lassen, wenn sie nur intern sein sollen.">
                    <x-input.text id="sp-public" feld="publicUrl" wire:model="publicUrl" placeholder="https://status.example.de" class="mt-1 w-full font-mono" />
                </x-field>
                <div class="flex gap-2 sm:mt-6">
                    <x-input.button type="submit" size="feld">Speichern</x-input.button>
                    @if ($publicBase !== '')
                        <x-input.button type="button" color="gray" size="feld" wire:click="setupPublic" wire:loading.attr="disabled" wire:target="setupPublic"
                            title="Proxy-Host und lokalen DNS-Eintrag anlegen bzw. prüfen">Einrichten</x-input.button>
                    @endif
                </div>
            </form>
            @if ($publicBase !== '' && $portMissing)
                {{-- the container is older than the published status port: recreate once --}}
                <p class="mt-3 flex items-start gap-1.5 text-sm text-amber-800 dark:text-amber-200" data-port-missing>
                    <x-icon name="warn" class="mt-0.5 h-4 w-4 shrink-0" sw="2" />
                    <span>PK95 Uptime öffnet den Port für die Statusseiten noch nicht. Erstelle Uptime einmal neu
                        (<a href="{{ route('apps.show', $installedApp) }}" wire:navigate class="underline">App-Details</a> → „Neu erstellen“), dann sind die Seiten über den Proxy erreichbar.</span>
                </p>
            @endif
            @if ($publicBase !== '')
                <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                    @if ($linkState)
                        <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $stateTone[$linkState] ?? $stateTone['failed'] }}">{{ \App\Models\AppLink::STATES[$linkState] ?? $linkState }}</span>
                        <span class="font-mono text-xs text-gray-600 dark:text-gray-300">{{ $statusLink->domain }} → {{ $statusLink->target }}</span>
                    @else
                        <span class="text-gray-600 dark:text-gray-300">Noch nicht eingerichtet: „Einrichten“ legt den Proxy-Host und den lokalen DNS-Eintrag an.</span>
                    @endif
                </div>
                @foreach (array_filter([$linkState ? $statusLink->message : null, $linkState ? $statusLink->dns_message : null]) as $note)
                    <p class="mt-1 text-xs text-amber-800 dark:text-amber-200">{{ $note }}</p>
                @endforeach
            @endif
        </x-panel>
    @endif

    @if ($editing !== null)
        <x-panel class="m-3" wire:key="page-form">
            <form wire:submit="save">
                <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">{{ $editing === 'new' ? 'Neue Statusseite' : 'Statusseite bearbeiten' }}</div>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <x-field label="Titel" for="p-title" feld="form.title" required>
                        <x-input.text id="p-title" feld="form.title" wire:model="form.title" class="mt-1 w-full" />
                    </x-field>
                    <x-field label="Adresse" for="p-slug" feld="form.slug" hilfe="/status/… – Kleinbuchstaben, Ziffern, Bindestriche" required>
                        <x-input.text id="p-slug" feld="form.slug" wire:model="form.slug" class="mt-1 w-full font-mono" />
                    </x-field>
                    <x-field label="Zeitraum der Balken" for="p-range" feld="form.range">
                        <x-input.select id="p-range" wire:model="form.range" class="mt-1">
                            @foreach ($ranges as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </x-input.select>
                    </x-field>
                    <x-field label="Beschreibung" for="p-desc" feld="form.description" class="md:col-span-3">
                        <x-input.text id="p-desc" feld="form.description" wire:model="form.description" class="mt-1 w-full" />
                    </x-field>
                    <div class="md:col-span-3">
                        <x-input.checkbox label="Öffentlich erreichbar" wire:model="form.enabled" />
                    </div>
                </div>

                <x-form-section title="Monitore auf der Seite" />
                <x-input.fehler feld="form.monitors" class="mt-2" />
                @if ($monitors->isEmpty())
                    <div class="mt-3 text-sm text-gray-500 dark:text-gray-400">Noch keine Monitore vorhanden.</div>
                @else
                    <div class="mt-3 divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($monitors as $m)
                            <div class="grid grid-cols-1 items-center gap-2 px-3 py-2 md:grid-cols-[1fr_1fr_1fr]" wire:key="pm-{{ $m['id'] }}">
                                <x-input.checkbox :label="$m['name']" wire:model.live="form.monitors.{{ $m['id'] }}.selected" />
                                @if (! empty($form['monitors'][$m['id']]['selected']))
                                    <x-input.text wire:model="form.monitors.{{ $m['id'] }}.publicName" placeholder="Anzeigename" class="w-full text-sm" />
                                    <x-input.text wire:model="form.monitors.{{ $m['id'] }}.group" placeholder="Gruppe (optional)" class="w-full text-sm" />
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="mt-6 flex justify-end gap-2">
                    <x-input.button type="button" color="gray" wire:click="cancel">Abbrechen</x-input.button>
                    <x-input.button wire:loading.attr="disabled">Speichern</x-input.button>
                </div>
            </form>
        </x-panel>
    @endif

    @if (! $loadError && ! $pages && $editing === null)
        <x-emptystate message="Noch keine Statusseite." />
    @endif

    @if ($pages)
        <x-panel polster="keins" class="m-3 divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($pages as $page)
                <div class="flex flex-wrap items-center gap-3 px-4 py-3" wire:key="sp-{{ $page['id'] }}">
                    {{-- min-w-48: on phones the buttons move to their own line instead of squeezing the title --}}
                    <div class="min-w-48 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $page['title'] }}</span>
                            @if ($page['enabled'])
                                <span class="rounded bg-green-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-green-800 dark:bg-green-900 dark:text-green-100">Öffentlich</span>
                            @else
                                <span class="rounded bg-gray-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-600 dark:bg-gray-700 dark:text-gray-300">Aus</span>
                            @endif
                        </div>
                        <div class="font-mono text-xs text-gray-500 dark:text-gray-400">
                            /status/{{ $page['slug'] }} · {{ count($page['monitors']) }} Monitore · {{ $ranges[$page['range']] ?? $page['range'] }}
                        </div>
                        @if (! $page['enabled'])
                            <div class="mt-1 text-xs text-gray-600 dark:text-gray-300" data-public-hint>Nicht öffentlich: In der Seite „Öffentlich erreichbar“ einschalten.</div>
                        @elseif ($publicBase === '')
                            <div class="mt-1 text-xs text-amber-800 dark:text-amber-200" data-public-hint>Keine öffentliche Adresse eingerichtet: oben eintragen, dann gibt es hier den Link.</div>
                        @endif
                    </div>
                    @if ($page['enabled'] && $publicBase !== '')
                        @php $publicPage = $publicBase.'/status/'.rawurlencode($page['slug']); @endphp
                        <span class="flex items-center gap-1" x-data="{ copied: false }">
                            <x-input.linkbutton :href="$publicPage" target="_blank" rel="noopener" color="gray" size="sm" title="{{ $publicPage }}">
                                <x-icon name="external" class="h-4 w-4" />Öffnen
                            </x-input.linkbutton>
                            <x-input.symbolknopf x-on:click="$kopieren({{ \Illuminate\Support\Js::from($publicPage) }})" titel="Link kopieren">
                                <x-icon name="copy" class="h-4 w-4" x-show="! copied" /><x-icon name="check" class="h-4 w-4 text-green-600" x-show="copied" x-cloak />
                            </x-input.symbolknopf>
                        </span>
                    @endif
                    <span class="flex gap-1">
                        <x-input.symbolknopf wire:click="edit('{{ $page['id'] }}')" titel="Bearbeiten"><x-icon name="pencil" class="h-4 w-4" /></x-input.symbolknopf>
                        <x-input.symbolknopf titel="Löschen"
                            x-on:click="$store.confirm.ask({ title: {{ \Illuminate\Support\Js::from('Statusseite „'.$page['title'].'“ löschen?') }}, text: 'Die öffentliche Adresse ist danach nicht mehr erreichbar.', button: 'Löschen', ok: () => $wire.delete('{{ $page['id'] }}') })">
                            <x-icon name="trash" class="h-4 w-4" />
                        </x-input.symbolknopf>
                    </span>
                </div>
            @endforeach
        </x-panel>
    @endif
</div>
