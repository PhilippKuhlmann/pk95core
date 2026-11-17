@php
    use App\Modules\Ad\DnsRecords;
    use App\Modules\Dns\Fmt;
    use Illuminate\Support\Js;
    use Illuminate\Support\Str;
    $input = 'rounded-lg border-gray-300 bg-white py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100';
    $badge = 'inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium';
    $formType = $form['type'] ?? 'A';
    $zoneName = $current['name'] ?? '';
    $valueHelp = [
        'A' => 'IPv4-Adresse, etwa 192.168.1.20.',
        'AAAA' => 'IPv6-Adresse.',
        'CNAME' => 'Der Name, auf den dieser Name zeigt.',
        'MX' => 'Hostname des Mailservers (keine IP-Adresse).',
        'TXT' => 'Beliebiger Text.',
        'SRV' => 'Hostname des Servers, der den Dienst anbietet.',
        'PTR' => 'Der Hostname, den die Rückwärtsauflösung liefern soll.',
    ];
    $allTypes = [...DnsRecords::TYPES, 'NS', 'SOA'];
@endphp

<div>
    <x-page-header title="DNS der Domäne">
        <x-slot:sub>Die Zonen der AD, beantwortet von Samba. PK95 DNS leitet Anfragen für die Domäne dorthin weiter. Einträge, die der Domänencontroller braucht, sind schreibgeschützt.</x-slot:sub>
        @if (! $notReady && $editing === null && $current && empty($current['system']))
            <x-input.button type="button" wire:click="create"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Eintrag hinzufügen</x-input.button>
        @endif
    </x-page-header>

    @include('modules.ad.partials.state')

    @if ($editing !== null)
        <x-modal wire:key="ad-record-form" submit="save">
            <x-slot:title>{{ $editing === 'new' ? 'Eintrag hinzufügen' : 'Eintrag bearbeiten' }}</x-slot:title>
            @if (! empty($form['dynamic']))
                <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Ein Rechner hat diesen Eintrag selbst angemeldet. Nach dem Speichern ist er statisch und wird nicht mehr automatisch aktualisiert oder entfernt.</p>
            @endif
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-[1fr_2fr_1fr]">
                <x-field label="Typ" for="ar-type" feld="form.type">
                    <x-input.select id="ar-type" feld="form.type" wire:model.live="form.type" class="mt-1">
                        @foreach ($types as $t)<option value="{{ $t }}">{{ DnsRecords::TYPE_LABELS[$t] }}</option>@endforeach
                    </x-input.select>
                </x-field>
                <x-field label="Name" for="ar-name" feld="form.name" :hilfe="'Voller Hostname in der Zone, etwa nas.'.$zoneName.'. Der Zonenname selbst steht für die Domäne.'">
                    <x-input.text id="ar-name" feld="form.name" wire:model="form.name" placeholder="nas.{{ $zoneName }}" class="mt-1 w-full font-mono text-sm" />
                </x-field>
                <x-field label="TTL (Sekunden)" for="ar-ttl" feld="form.ttl" hilfe="0 bis 604800, Standard 900.">
                    <x-input.text id="ar-ttl" feld="form.ttl" inputmode="numeric" wire:model="form.ttl" class="mt-1 w-full tabular-nums" />
                </x-field>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-4">
                @if (in_array($formType, ['MX', 'SRV'], true))
                    <x-field label="Priorität" for="ar-prio" feld="form.priority" hilfe="Kleinere Zahl wird zuerst genutzt.">
                        <x-input.text id="ar-prio" feld="form.priority" inputmode="numeric" wire:model="form.priority" class="mt-1 w-full tabular-nums" />
                    </x-field>
                @endif
                @if ($formType === 'SRV')
                    <x-field label="Gewicht" for="ar-weight" feld="form.weight">
                        <x-input.text id="ar-weight" feld="form.weight" inputmode="numeric" wire:model="form.weight" class="mt-1 w-full tabular-nums" />
                    </x-field>
                    <x-field label="Port" for="ar-port" feld="form.port">
                        <x-input.text id="ar-port" feld="form.port" inputmode="numeric" wire:model="form.port" class="mt-1 w-full tabular-nums" />
                    </x-field>
                @endif
                <x-field :label="$formType === 'TXT' ? 'Text' : ($formType === 'A' || $formType === 'AAAA' ? 'Adresse' : 'Ziel')" for="ar-value" feld="form.value" :hilfe="$valueHelp[$formType] ?? null"
                    :class="match ($formType) { 'MX' => 'md:col-span-3', 'SRV' => 'md:col-span-1', default => 'md:col-span-4' }">
                    @if ($formType === 'TXT')
                        <x-input.textarea id="ar-value" feld="form.value" rows="3" wire:model="form.value" class="mt-1" />
                    @else
                        <x-input.text id="ar-value" feld="form.value" wire:model="form.value" class="mt-1 w-full font-mono text-sm" />
                    @endif
                </x-field>
            </div>
        </x-modal>
    @endif

    @if (! $notReady && $zones)
        <div class="mx-3 mt-2 flex flex-wrap items-center gap-2">
            <x-input.combobox wire:model.live="zone" label="Zone" :options="collect($zones)->map(fn ($z) => ['value' => $z['name'], 'label' => $z['name'], 'hint' => Fmt::num((int) $z['record_count'])])->all()" class="w-full sm:w-72" />
            <x-input.combobox wire:model.live="type" label="Typ" :options="array_combine($allTypes, $allTypes)" placeholder="Alle Typen" reset="Alle Typen" class="w-full sm:w-40" />
            <input type="search" wire:model.live.debounce.400ms="q" placeholder="Name oder Wert enthält …" aria-label="Suche" class="{{ $input }} w-full sm:w-60">
            @if ($type !== '' || $q !== '')
                <button type="button" wire:click="resetFilters" class="text-sm text-cerulean-700 hover:underline dark:text-cerulean-300">Filter zurücksetzen</button>
            @endif
        </div>
        @if (! empty($current['system']))
            <x-callout title="Systemzone" class="m-3">{{ $zoneName }} enthält nur Einträge, die Active Directory selbst verwaltet. Sie lassen sich hier ansehen, aber nicht ändern.</x-callout>
        @endif

        @if (! $rows && ! $loadError)
            <x-emptystate :message="$type !== '' || $q !== '' ? 'Keine passenden Einträge.' : 'Die Zone ist leer.'" />
        @elseif ($rows)
            <x-panel class="relative m-3 overflow-x-auto" polster="keins">
                <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100" data-ad-records>
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Typ</th><th class="px-4 py-3">Wert</th>
                            <th class="hidden px-4 py-3 text-right md:table-cell">TTL</th><th class="px-4 py-3"><span class="sr-only">Aktionen</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($rows as $r)
                            @php
                                $chips = match ($r['type']) {
                                    'MX' => ['Priorität '.$r['priority']],
                                    'SRV' => ['Priorität '.$r['priority'], 'Gewicht '.$r['weight'], 'Port '.$r['port']],
                                    default => [],
                                };
                                $readOnly = $r['system'] || in_array($r['type'], ['NS', 'SOA'], true);
                            @endphp
                            <tr wire:key="ad-rec-{{ $r['id'] }}" @if ($readOnly) data-read-only @endif>
                                <td class="min-w-36 px-4 py-3 align-top">
                                    <span class="font-mono">{{ Fmt::breakable($r['name']) }}</span>
                                    @if ($r['system'])<span class="{{ $badge }} ml-1 bg-violet-100 text-violet-800 dark:bg-violet-900/70 dark:text-violet-100" data-tip="Braucht der Domänencontroller, schreibgeschützt">System</span>@endif
                                    @if ($r['dynamic'])<span class="{{ $badge }} ml-1 bg-cerulean-100 text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-100" data-tip="Vom Rechner selbst angemeldet">dynamisch</span>@endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 align-top"><span class="{{ $badge }} bg-gray-100 font-mono text-gray-800 dark:bg-gray-700 dark:text-gray-100">{{ $r['type'] }}</span></td>
                                <td class="min-w-48 max-w-md px-4 py-3 align-top font-mono">
                                    @if ($r['type'] === 'TXT')
                                        <span class="line-clamp-3 whitespace-pre-wrap break-words text-xs" data-tip="{{ $r['value'] }}">{{ $r['value'] }}</span>
                                    @else
                                        <span>{{ Fmt::breakable($r['value']) }}</span>
                                    @endif
                                    @if ($chips)
                                        <span class="mt-1 flex flex-wrap gap-1 font-sans">
                                            @foreach ($chips as $chip)<span class="whitespace-nowrap rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ $chip }}</span>@endforeach
                                        </span>
                                    @endif
                                </td>
                                <td class="hidden px-4 py-3 text-right align-top tabular-nums md:table-cell">{{ $r['ttl'] }}</td>
                                <td class="px-4 py-3 align-top">
                                    @unless ($readOnly)
                                        <div class="flex justify-end gap-1">
                                            <x-input.symbolknopf titel="Bearbeiten" ton="grau" wire:click="edit({{ Js::from($r['id']) }})"><x-icon name="pencil" class="h-4 w-4" /></x-input.symbolknopf>
                                            <x-input.symbolknopf titel="{{ $r['name'] }} {{ $r['type'] }} löschen" ton="rot"
                                                x-on:click="$store.confirm.ask({ title: 'Eintrag löschen?', text: {{ Js::from($r['name'].' ('.$r['type'].' → '.Str::limit($r['value'], 60).') wird gelöscht.') }}, button: 'Löschen', ok: () => $wire.delete({{ Js::from($r['id']) }}) })">
                                                <x-icon name="trash" class="h-4 w-4" />
                                            </x-input.symbolknopf>
                                        </div>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-panel>
        @endif
    @endif
</div>
