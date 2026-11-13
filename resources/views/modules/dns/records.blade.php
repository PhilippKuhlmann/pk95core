@php
    use App\Modules\Dns\Fmt;
    use App\Modules\Dns\Records;
    use Illuminate\Support\Js;
    use Illuminate\Support\Str;
    $input = 'rounded-lg border-gray-300 bg-white py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100';
    $badge = 'inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium';
    $type = $form['type'] ?? 'A';
    $valueHelp = [
        'A' => 'IPv4-Adresse, etwa 192.168.1.10.',
        'AAAA' => 'IPv6-Adresse, etwa fd00::10.',
        'CNAME' => 'Der Name, auf den dieser Name zeigt, etwa nas.home.lan. Neben einem CNAME darf es für denselben Namen keinen anderen Eintrag geben.',
        'MX' => 'Hostname des Mailservers, etwa mail.home.lan (keine IP-Adresse).',
        'TXT' => 'Beliebiger Text, höchstens 4000 Zeichen, etwa für SPF oder Verifizierungen.',
        'SRV' => 'Hostname des Servers, der den Dienst anbietet (keine IP-Adresse).',
        'PTR' => 'Der Hostname, den die Rückwärtsauflösung liefern soll.',
    ];
    $nameHelp = match ($type) {
        'SRV' => 'Dienst, Protokoll und Domain, etwa _ldap._tcp.home.lan.',
        'PTR' => 'Rückwärts-Name, etwa 10.1.168.192.in-addr.arpa. Für A/AAAA-Einträge geht das einfacher mit „Rückwärtsauflösung“.',
        'AAAA', 'A', 'CNAME', 'TXT', 'MX' => 'Voller Hostname wie nas.home.lan oder *.home.lan für alle Namen darunter. Liegt der Name in einer Zone, gehört der Eintrag zu ihr.',
        default => '',
    };
    $placeholder = ['A' => '192.168.1.10', 'AAAA' => 'fd00::10', 'CNAME' => 'nas.home.lan', 'MX' => 'mail.home.lan', 'SRV' => 'ldap.home.lan', 'PTR' => 'nas.home.lan'];
    $zoneLabel = fn (int $id) => $id === 0 ? 'Einzeleintrag' : ($zoneNames[$id] ?? '#'.$id);
@endphp

<div>
    <x-page-header title="DNS-Einträge">
        <x-slot:sub>Eigene Zonen und Einträge beantwortet PK95 DNS selbst, vor Filtern und Upstreams. Sie gelten auch, wenn der Schutz pausiert ist.</x-slot:sub>
        @if ($editing === null && $zoneEditing === null)
            <x-input.button type="button" color="gray" wire:click="createZone"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Zone hinzufügen</x-input.button>
            <x-input.button type="button" wire:click="create"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Eintrag hinzufügen</x-input.button>
        @endif
    </x-page-header>

    @if ($loadError)
        <x-callout title="PK95 DNS antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    {{-- zone form --}}
    @if ($zoneEditing !== null)
        <x-panel class="m-3" wire:key="zone-form">
            <form wire:submit="saveZone">
                <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">{{ $zoneEditing === 'new' ? 'Zone hinzufügen' : 'Zone bearbeiten' }}</div>
                <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Für alle Namen in einer aktiven Zone antwortet PK95 DNS allein: Was hier nicht eingetragen ist, gibt es im Netz nicht. Vorhandene Einzeleinträge unter dem Zonennamen wandern beim Anlegen in die Zone.</p>
                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-[2fr_1fr_2fr]">
                    <x-field label="Zonenname" for="z-name" feld="zoneForm.name" hilfe="Etwa home.lan oder für Rückwärtsauflösung 1.168.192.in-addr.arpa.">
                        <x-input.text id="z-name" feld="zoneForm.name" wire:model.live.debounce.500ms="zoneForm.name" placeholder="home.lan" class="mt-1 w-full font-mono text-sm" />
                    </x-field>
                    <x-field label="TTL (Sekunden)" for="z-ttl" feld="zoneForm.ttl" hilfe="Für SOA, NS und negative Antworten. 0 bis 604800.">
                        <x-input.text id="z-ttl" feld="zoneForm.ttl" inputmode="numeric" wire:model="zoneForm.ttl" class="mt-1 w-full tabular-nums" />
                    </x-field>
                    <x-field label="Primärer Nameserver" for="z-ns" feld="zoneForm.primary_ns" hilfe="Name im SOA- und NS-Eintrag. Leer: ns.<Zone>.">
                        <x-input.text id="z-ns" feld="zoneForm.primary_ns" wire:model="zoneForm.primary_ns"
                            placeholder="{{ 'ns.'.(trim($zoneForm['name']) !== '' ? strtolower(trim($zoneForm['name'])) : 'home.lan') }}" class="mt-1 w-full font-mono text-sm" />
                    </x-field>
                </div>
                @if (Records::isPublicZone($zoneForm['name']))
                    <x-callout title="Öffentliche Domain" ton="gelb" class="mt-4">
                        {{ strtolower(trim($zoneForm['name'])) }} gibt es im Internet. Solange die Zone aktiv ist, beantwortet PK95 DNS alle Namen darunter selbst. Namen, die nur im öffentlichen DNS stehen (etwa www oder mail), sind im Netz dann nicht mehr erreichbar. Für einzelne Namen reichen Einträge ohne Zone.
                    </x-callout>
                @endif
                <x-input.checkbox label="Aktiv" hilfe="Eine ausgeschaltete Zone beantwortet nichts; ihre Namen gehen dann wie gewohnt an die Upstreams." wire:model="zoneForm.enabled" />
                <div class="mt-6 flex justify-end gap-2">
                    <x-input.button type="button" color="gray" wire:click="cancel">Abbrechen</x-input.button>
                    <x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button>
                </div>
            </form>
        </x-panel>
    @endif

    {{-- record form --}}
    @if ($editing !== null)
        <x-panel class="m-3" wire:key="record-form">
            <form wire:submit="save">
                <div class="flex flex-wrap items-center gap-2 text-lg font-CoconPro text-gray-900 dark:text-gray-100">
                    {{ $editing === 'new' ? 'Eintrag hinzufügen' : 'Eintrag bearbeiten' }}
                    @if (($form['managed_by'] ?? '') === 'core')
                        <span class="{{ $badge }} bg-violet-100 font-sans text-violet-800 dark:bg-violet-900/70 dark:text-violet-100">von Core</span>
                    @endif
                </div>
                @if (($form['managed_by'] ?? '') === 'core')
                    <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">PK95 Core hat diesen Eintrag für eine ausgerollte App angelegt und setzt ihn auf die neue LAN-IP, wenn sie sich ändert.</p>
                @endif
                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-[1fr_2fr_1fr]">
                    <x-field label="Typ" for="r-type" feld="form.type">
                        <x-input.select id="r-type" feld="form.type" wire:model.live="form.type" class="mt-1">
                            @foreach (Records::TYPE_LABELS as $t => $l)<option value="{{ $t }}">{{ $l }}</option>@endforeach
                        </x-input.select>
                    </x-field>
                    <x-field label="Name" for="r-name" feld="form.name" :hilfe="$nameHelp">
                        <x-input.text id="r-name" feld="form.name" wire:model="form.name" placeholder="{{ $type === 'SRV' ? '_ldap._tcp.home.lan' : ($type === 'PTR' ? '10.1.168.192.in-addr.arpa' : 'nas.home.lan') }}" class="mt-1 w-full font-mono text-sm" />
                    </x-field>
                    <x-field label="TTL (Sekunden)" for="r-ttl" feld="form.ttl" hilfe="Wie lange Geräte die Antwort zwischenspeichern. Standard 300.">
                        <x-input.text id="r-ttl" feld="form.ttl" inputmode="numeric" wire:model="form.ttl" class="mt-1 w-full tabular-nums" />
                    </x-field>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-4">
                    @if (in_array($type, ['MX', 'SRV'], true))
                        <x-field label="Priorität" for="r-prio" feld="form.priority" hilfe="Kleinere Zahl wird zuerst genutzt.">
                            <x-input.text id="r-prio" feld="form.priority" inputmode="numeric" wire:model="form.priority" class="mt-1 w-full tabular-nums" />
                        </x-field>
                    @endif
                    @if ($type === 'SRV')
                        <x-field label="Gewicht" for="r-weight" feld="form.weight" hilfe="Verteilung bei gleicher Priorität.">
                            <x-input.text id="r-weight" feld="form.weight" inputmode="numeric" wire:model="form.weight" class="mt-1 w-full tabular-nums" />
                        </x-field>
                        <x-field label="Port" for="r-port" feld="form.port" hilfe="1 bis 65535.">
                            <x-input.text id="r-port" feld="form.port" inputmode="numeric" wire:model="form.port" placeholder="389" class="mt-1 w-full tabular-nums" />
                        </x-field>
                    @endif
                    <x-field :label="$type === 'TXT' ? 'Text' : ($type === 'A' || $type === 'AAAA' ? 'Adresse' : 'Ziel')" for="r-value" feld="form.value" :hilfe="$valueHelp[$type] ?? null"
                        :class="match ($type) { 'MX' => 'md:col-span-3', 'SRV' => 'md:col-span-1', default => 'md:col-span-4' }">
                        @if ($type === 'TXT')
                            <x-input.textarea id="r-value" feld="form.value" rows="3" maxlength="4000" wire:model="form.value" class="mt-1" placeholder="v=spf1 mx -all" />
                        @else
                            <x-input.text id="r-value" feld="form.value" wire:model="form.value" placeholder="{{ $placeholder[$type] ?? '' }}" class="mt-1 w-full font-mono text-sm" />
                        @endif
                    </x-field>
                </div>

                <div class="mt-2 flex flex-col gap-1">
                    @if (in_array($type, ['A', 'AAAA'], true))
                        <x-input.checkbox label="Rückwärtsauflösung (PTR)" hilfe="PK95 DNS beantwortet auch die Frage, welcher Name zu dieser Adresse gehört. Ein eigener PTR-Eintrag für die Adresse hat Vorrang." wire:model="form.ptr" />
                    @endif
                    <x-input.checkbox label="Aktiv" wire:model="form.enabled" />
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <x-input.button type="button" color="gray" wire:click="cancel">Abbrechen</x-input.button>
                    <x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button>
                </div>
            </form>
        </x-panel>
    @endif

    {{-- zones --}}
    @if (! $loadError || $zones)
        <x-panel class="relative m-3 overflow-x-auto" polster="keins">
            <div class="flex flex-wrap items-start justify-between gap-3 p-4">
                <div>
                    <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Zonen</div>
                    <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Für Namen in einer aktiven Zone antwortet PK95 DNS allein (autoritativ). Einträge ohne Zone gelten nur für genau ihren Namen, alle anderen Namen gehen weiter an die Upstreams.</p>
                </div>
            </div>
            @if (! $zones)
                <p class="border-t border-gray-100 px-4 py-3 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">Noch keine Zonen. Einzelne Einträge funktionieren auch ohne Zone.</p>
            @else
                <table class="w-full border-t border-gray-100 text-left text-sm text-gray-900 dark:border-gray-700 dark:text-gray-100">
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr><th class="px-4 py-2">Aktiv</th><th class="px-4 py-2">Zone</th><th class="px-4 py-2 text-right">Einträge</th>
                            <th class="hidden px-4 py-2 md:table-cell">Seriennummer</th><th class="hidden px-4 py-2 lg:table-cell">Nameserver</th>
                            <th class="hidden px-4 py-2 text-right lg:table-cell">TTL</th><th class="px-4 py-2"><span class="sr-only">Aktionen</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($zones as $z)
                            @php
                                $count = (int) $z['record_count'];
                                $gone = $count > 0 ? ' und '.($count === 1 ? 'ihr Eintrag' : 'ihre '.Fmt::num($count).' Einträge') : '';
                            @endphp
                            <tr wire:key="zone-{{ $z['id'] }}">
                                <td class="px-4 py-3 align-top"><x-proxy.switch :on="$z['enabled']" :label="$z['name'].' aktiv'" wire:click="toggleZone({{ $z['id'] }})" /></td>
                                <td class="px-4 py-3 align-top">
                                    <button type="button" wire:click="showZone('{{ $z['id'] }}')" class="text-left font-mono text-cerulean-700 hover:underline dark:text-cerulean-300" data-tip="Einträge dieser Zone zeigen">{{ Fmt::breakable($z['name']) }}</button>
                                    @if (Records::isPublicZone($z['name']))
                                        <div class="mt-1 max-w-md text-xs text-amber-800 dark:text-amber-200">Öffentliche Domain: Namen, die hier fehlen, sind im Netz nicht erreichbar, auch wenn es sie im Internet gibt.</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right align-top tabular-nums">{{ Fmt::num($count) }}</td>
                                <td class="hidden px-4 py-3 align-top font-mono text-xs md:table-cell">{{ $z['serial'] }}</td>
                                <td class="hidden px-4 py-3 align-top font-mono text-xs lg:table-cell">{{ Fmt::breakable($z['primary_ns']) }}</td>
                                <td class="hidden px-4 py-3 text-right align-top tabular-nums lg:table-cell">{{ $z['ttl'] }}</td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex justify-end gap-1">
                                        <x-input.button type="button" size="sm" color="gray" wire:click="editZone({{ $z['id'] }})">Bearbeiten</x-input.button>
                                        <x-input.button type="button" size="sm" color="gray" aria-label="{{ $z['name'] }} entfernen"
                                            x-on:click="$store.confirm.ask({ title: 'Zone entfernen?', text: {{ Js::from('Die Zone '.$z['name'].$gone.' werden gelöscht.') }}, button: 'Entfernen', ok: () => $wire.deleteZone({{ $z['id'] }}) })">
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
    @endif

    {{-- records --}}
    <div class="mx-3 mt-4 flex flex-wrap items-center gap-2">
        <x-input.combobox wire:model.live="zone" label="Zone" :options="['0' => 'Einzeleinträge'] + collect($zones)->mapWithKeys(fn ($z) => [(string) $z['id'] => $z['name']])->all()"
            placeholder="Alle Einträge" reset="Alle Einträge" :search="true" class="w-full sm:w-56" />
        <x-input.combobox wire:model.live="type" label="Typ" :options="array_combine(Records::TYPES, Records::TYPES)" placeholder="Alle Typen" reset="Alle Typen" class="w-full sm:w-40" />
        <input type="search" wire:model.live.debounce.400ms="q" placeholder="Name oder Wert enthält …" aria-label="Suche" class="{{ $input }} w-60">
        @if ($filtered)
            <button type="button" wire:click="resetFilters" class="text-sm text-cerulean-700 hover:underline dark:text-cerulean-300">Filter zurücksetzen</button>
        @endif
    </div>

    @if (! $rows && ! $loadError)
        <x-panel class="m-3" polster="weit">
            <div class="text-center">
                <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $filtered ? 'Keine passenden Einträge' : 'Noch keine DNS-Einträge' }}</div>
                <p class="mx-auto mt-2 max-w-xl text-sm text-gray-500 dark:text-gray-400">
                    {{ $filtered ? 'Ändere die Filter oder setze sie zurück.' : 'Damit erreichst du Geräte im Netz über einen Namen, etwa nas.home.lan statt 192.168.1.10. Neben A- und AAAA-Einträgen gehen CNAME, MX, TXT, SRV und PTR.' }}
                </p>
            </div>
        </x-panel>
    @elseif ($rows)
        <x-panel class="relative m-3 overflow-x-auto" polster="keins">
            <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr><th class="px-4 py-3">Aktiv</th><th class="px-4 py-3">Name</th><th class="px-4 py-3">Typ</th><th class="px-4 py-3">Wert</th>
                        <th class="hidden px-4 py-3 text-right md:table-cell">TTL</th><th class="hidden px-4 py-3 xl:table-cell">Zone</th><th class="px-4 py-3"><span class="sr-only">Aktionen</span></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($rows as $r)
                        @php
                            $core = ($r['managed_by'] ?? '') === 'core';
                            $chips = match ($r['type']) {
                                'MX' => ['Priorität '.$r['priority']],
                                'SRV' => ['Priorität '.$r['priority'], 'Gewicht '.$r['weight'], 'Port '.$r['port']],
                                default => [],
                            };
                            $confirm = $core
                                ? ['title' => 'Eintrag von Core löschen?', 'text' => $r['name'].' ('.$r['type'].') hat PK95 Core für eine ausgerollte App angelegt. Core kann ihn wieder anlegen, etwa beim DNS-Abgleich nach einer neuen LAN-IP. Dauerhaft abschalten lässt sich das unter Automatik.']
                                : ['title' => 'Eintrag löschen?', 'text' => $r['name'].' ('.$r['type'].' → '.Str::limit($r['value'], 60).') wird gelöscht.'];
                        @endphp
                        <tr wire:key="rec-{{ $r['id'] }}">
                            <td class="px-4 py-3 align-top"><x-proxy.switch :on="$r['enabled']" :label="$r['name'].' '.$r['type'].' aktiv'" wire:click="toggle({{ $r['id'] }})" /></td>
                            <td class="min-w-36 px-4 py-3 align-top">
                                <span class="font-mono">{{ Fmt::breakable($r['name']) }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400 xl:hidden">{{ (int) ($r['zone_id'] ?? 0) === 0 ? 'Einzeleintrag' : 'Zone '.$zoneLabel((int) $r['zone_id']) }}</span>
                                @if ($core)
                                    <span class="{{ $badge }} ml-1 bg-violet-100 text-violet-800 dark:bg-violet-900/70 dark:text-violet-100" data-tip="Von PK95 Core für eine ausgerollte App angelegt">von Core<span class="sr-only">: für eine ausgerollte App angelegt</span></span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 align-top">
                                <span class="{{ $badge }} bg-gray-100 font-mono text-gray-800 dark:bg-gray-700 dark:text-gray-100">{{ $r['type'] }}</span>
                                @if (! empty($r['ptr']))
                                    <span class="{{ $badge }} bg-cerulean-100 text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-100" data-tip="Rückwärtsauflösung eingeschaltet">+PTR<span class="sr-only">: Rückwärtsauflösung eingeschaltet</span></span>
                                @endif
                            </td>
                            <td class="min-w-48 max-w-md px-4 py-3 align-top font-mono">
                                @if ($r['type'] === 'TXT')
                                    <span class="line-clamp-3 whitespace-pre-wrap break-words text-xs" data-tip="{{ $r['value'] }}">{{ $r['value'] }}</span>
                                @else
                                    <span>{{ Fmt::breakable($r['value']) }}</span>
                                @endif
                                @if ($chips)
                                    <span class="mt-1 flex flex-wrap gap-1 font-sans">
                                        @foreach ($chips as $chip)
                                            <span class="whitespace-nowrap rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ $chip }}</span>
                                        @endforeach
                                    </span>
                                @endif
                            </td>
                            <td class="hidden px-4 py-3 text-right align-top tabular-nums md:table-cell">{{ $r['ttl'] }}</td>
                            <td class="hidden px-4 py-3 align-top text-xs text-gray-600 dark:text-gray-300 xl:table-cell">{{ Fmt::breakable($zoneLabel((int) ($r['zone_id'] ?? 0))) }}</td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex justify-end gap-1">
                                    <x-input.button type="button" size="sm" color="gray" wire:click="edit({{ $r['id'] }})">Bearbeiten</x-input.button>
                                    <x-input.button type="button" size="sm" color="gray" aria-label="{{ $r['name'] }} {{ $r['type'] }} löschen"
                                        x-on:click="$store.confirm.ask({ title: {{ Js::from($confirm['title']) }}, text: {{ Js::from($confirm['text']) }}, button: 'Löschen', ok: () => $wire.delete({{ $r['id'] }}) })">
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
