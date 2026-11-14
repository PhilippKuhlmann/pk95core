@php
    use App\Modules\Proxy\Fmt;
    use App\Modules\Proxy\Streams;
    $access = $form['access_mode'] ?? 'internal';
@endphp

<div wire:poll.5s>
    <x-page-header title="Streams">
        @if ($editing === null)
            <x-input.button type="button" wire:click="create"><x-icon name="plus" class="h-4 w-4" sw="2.2" />Stream hinzufügen</x-input.button>
        @endif
    </x-page-header>

    @if ($loadError)
        <x-callout title="PK95 Proxy antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @elseif (! $serviceOk && $streams)
        <x-callout title="Stream-Dienst läuft nicht" ton="gelb" class="m-3">Der Stream-Dienst läuft nicht, die Weiterleitungen sind gerade nicht aktiv.</x-callout>
    @endif

    @if ($editing !== null)
        <x-modal wire:key="stream-form" submit="save">
            <x-slot:title>{{ $editing === 'new' ? 'Stream hinzufügen' : 'Stream bearbeiten' }}</x-slot:title>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-4">
                <x-field label="Name" for="s-name" class="md:col-span-2">
                    <x-input.text id="s-name" maxlength="60" wire:model="form.name" placeholder="Minecraft" class="mt-1 w-full" />
                </x-field>
                <x-field label="Port auf diesem Server" for="s-port">
                    <x-input.text id="s-port" type="number" min="1" max="65535" wire:model="form.listen_port" placeholder="25565" class="mt-1 w-full" />
                </x-field>
                <x-field label="Protokoll" for="s-proto">
                    <x-input.select id="s-proto" wire:model="form.protocol" class="mt-1">
                        @foreach (Streams::PROTOS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                    </x-input.select>
                </x-field>
                <x-field label="Ziel" for="s-target" class="md:col-span-3">
                    <x-input.text id="s-target" wire:model="form.target_host" placeholder="10.10.250.50" class="mt-1 w-full font-mono" />
                </x-field>
                <x-field label="Ziel-Port" for="s-tport">
                    <x-input.text id="s-tport" type="number" min="1" max="65535" wire:model="form.target_port" placeholder="25565" class="mt-1 w-full" />
                </x-field>
            </div>

            <x-form-section title="Zugriff" />
            <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-field label="Erreichbarkeit" for="s-access" :hilfe="Streams::ACCESS_HINTS[$access] ?? null">
                    <x-input.select id="s-access" wire:model.live="form.access_mode" class="mt-1">
                        @foreach (Streams::ACCESS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                    </x-input.select>
                </x-field>
                @if ($access === 'custom')
                    <x-field label="Erlaubte IP-Adressen und Bereiche" for="s-allow">
                        <x-input.textarea id="s-allow" rows="2" wire:model="form.ip_allow" placeholder="203.0.113.7" class="mt-1" />
                    </x-field>
                @endif
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-field label="Notiz" for="s-notes">
                    <x-input.text id="s-notes" maxlength="500" wire:model="form.notes" class="mt-1 w-full" />
                </x-field>
            </div>
            <x-input.checkbox label="Aktiv" wire:model="form.enabled" />
        </x-modal>
    @endif

    @if (! $streams && ! $loadError)
        <x-panel class="m-3" polster="weit">
            <div class="text-center">
                <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Noch keine Streams</div>
                <p class="mx-auto mt-2 max-w-xl text-sm text-gray-500 dark:text-gray-400">Ein Stream leitet einen TCP- oder UDP-Port an einen anderen Rechner weiter, zum Beispiel für einen Spieleserver oder SSH. Anders als ein Host funktioniert das ohne Domain und ohne Zertifikat.</p>
                @if ($editing === null)
                    <x-input.button type="button" class="mt-4" wire:click="create">Stream hinzufügen</x-input.button>
                @endif
            </div>
        </x-panel>
    @elseif ($streams)
        <x-panel class="m-3 overflow-x-auto" polster="keins">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Aktiv</th><th class="px-4 py-3">Name</th><th class="px-4 py-3">Port</th><th class="px-4 py-3">Ziel</th>
                        <th class="px-4 py-3">Zugriff</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Verbindungen</th><th class="px-4 py-3 text-right">Daten</th><th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($streams as $x)
                        @php
                            $st = $x['status'] ?? [];
                            $err = ($st['tcp_error'] ?? '') ?: ($st['udp_error'] ?? '');
                        @endphp
                        <tr wire:key="stream-{{ $x['id'] }}" @class(['opacity-60' => ! $x['enabled']])>
                            <td class="px-4 py-3 align-top"><x-proxy.switch :on="$x['enabled']" :label="$x['name'].' aktiv'" wire:click="toggle({{ $x['id'] }})" /></td>
                            <td class="px-4 py-3 align-top">
                                <div class="font-medium text-gray-900 dark:text-gray-100">{{ $x['name'] }}</div>
                                @if ($x['notes'])<div class="text-xs text-gray-500">{{ $x['notes'] }}</div>@endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 align-top">{{ $x['listen_port'] }} {{ Streams::PROTOS[$x['protocol']] ?? $x['protocol'] }}</td>
                            <td class="whitespace-nowrap px-4 py-3 align-top font-mono text-xs">{{ str_contains($x['target_host'], ':') ? '['.$x['target_host'].']' : $x['target_host'] }}:{{ $x['target_port'] }}</td>
                            <td class="px-4 py-3 align-top text-xs">{{ match ($x['access_mode']) { 'internal' => 'Nur intern', 'custom' => implode(', ', $x['ip_allow']), default => 'Öffentlich' } }}</td>
                            <td class="px-4 py-3 align-top">
                                @if (! $x['enabled'])
                                    <span class="text-gray-500 dark:text-gray-400">Deaktiviert</span>
                                @elseif (! isset($x['status']))
                                    <span class="flex items-center gap-2"><span class="h-2 w-2 animate-pulse rounded-full {{ Fmt::dot('warn') }}"></span>Wird gestartet</span>
                                @elseif ($err)
                                    <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot('bad') }}"></span>{{ $err }}</span>
                                @else
                                    <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot('good') }}"></span>Läuft</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 align-top text-xs">
                                <div>{{ Fmt::num($st['active'] ?? 0) }} offen, {{ Fmt::num($st['total'] ?? 0) }} gesamt</div>
                                <div class="text-gray-500">{{ Fmt::num($st['denied'] ?? 0) }} abgewiesen</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right align-top text-xs tabular-nums" data-tip="Vom Besucher zum Ziel und zurück, seit dem letzten Start des Dienstes">
                                <div>↑ {{ Fmt::bytes($st['bytes_in'] ?? 0) }}</div><div>↓ {{ Fmt::bytes($st['bytes_out'] ?? 0) }}</div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex justify-end gap-1">
                                    <x-input.button type="button" size="sm" color="gray" wire:click="edit({{ $x['id'] }})">Bearbeiten</x-input.button>
                                    <x-input.button type="button" size="sm" color="gray" aria-label="{{ $x['name'] }} löschen"
                                        x-on:click="$store.confirm.ask({ title: 'Stream löschen?', text: {{ Js::from($x['name'].' auf Port '.$x['listen_port'].' wird geschlossen, laufende Verbindungen werden getrennt.') }}, button: 'Löschen', ok: () => $wire.delete({{ $x['id'] }}) })">
                                        <x-icon name="trash" class="h-4 w-4" />
                                    </x-input.button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                Damit ein Stream aus dem Internet erreichbar ist, muss dein Router den Port an {{ $serverHost }} weiterleiten. Die Zähler beginnen nach jedem Neustart des Dienstes bei null. IP-Sperren und Länderregeln gelten auch hier.
            </p>
        </x-panel>

        @if ($recent)
            <x-panel class="m-3 overflow-x-auto">
                <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Letzte Verbindungen</div>
                <table class="mt-3 w-full text-left text-xs">
                    <thead class="text-gray-500"><tr><th class="py-1 pr-3">Ende</th><th class="py-1 pr-3">Stream</th><th class="py-1 pr-3">Adresse</th><th class="py-1 pr-3">Ergebnis</th><th class="py-1 pr-3 text-right">Dauer</th><th class="py-1 text-right">Daten</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($recent as $e)
                            <tr>
                                <td class="whitespace-nowrap py-1.5 pr-3 tabular-nums">{{ Fmt::stamp($e['ts']) }}</td>
                                <td class="py-1.5 pr-3">{{ $e['name'] }} ({{ strtoupper($e['protocol']) }})</td>
                                <td class="whitespace-nowrap py-1.5 pr-3 font-mono"><span data-tip="{{ Fmt::country($e['country'] ?? null) }}">{{ Fmt::flag($e['country'] ?? null) }}</span> {{ $e['ip'] }}</td>
                                <td class="py-1.5 pr-3">
                                    @if (($e['denied'] ?? ''))
                                        <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot('bad') }}"></span>Abgewiesen: {{ ($e['denied'] ?? '') }}</span>
                                    @else
                                        Verbunden
                                    @endif
                                </td>
                                <td class="py-1.5 pr-3 text-right tabular-nums">{{ ($e['denied'] ?? '') ? '–' : Fmt::num($e['seconds']).' s' }}</td>
                                <td class="whitespace-nowrap py-1.5 text-right tabular-nums">{{ ($e['denied'] ?? '') ? '–' : '↑ '.Fmt::bytes($e['bytes_in']).' ↓ '.Fmt::bytes($e['bytes_out']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-panel>
        @endif
    @endif
</div>
