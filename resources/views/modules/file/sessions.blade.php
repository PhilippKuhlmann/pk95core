@php
    use App\Modules\File\Sessions;
    use Illuminate\Support\Js;
    $modes = ['read' => 'Lesen', 'write' => 'Schreiben'];
@endphp

<div wire:poll.15s>
    <x-page-header title="Sitzungen">
        <x-slot:sub>Wer gerade mit dem Dateiserver verbunden ist und welche Dateien offen sind.</x-slot:sub>
    </x-page-header>

    @include('modules.file.partials.state')

    @if (! $notReady && ! $loadError)
        @if (! $sessions)
            <x-emptystate message="Gerade ist niemand verbunden." />
        @else
            <x-panel class="relative m-3 overflow-x-auto" polster="keins">
                <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100" data-sessions>
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr><th class="px-4 py-3">Benutzer</th><th class="px-4 py-3">Rechner</th><th class="hidden px-4 py-3 md:table-cell">Freigaben</th>
                            <th class="hidden px-4 py-3 lg:table-cell">Verbunden seit</th><th class="hidden px-4 py-3 text-right sm:table-cell">Offene Dateien</th><th class="px-4 py-3"><span class="sr-only">Aktionen</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($sessions as $s)
                            <tr wire:key="session-{{ $s['id'] }}">
                                <td class="px-4 py-3 align-top font-medium">{{ $s['user'] ?? '–' }}</td>
                                <td class="px-4 py-3 align-top">
                                    {{ ($s['hostname'] ?? '') !== '' ? $s['hostname'] : ($s['machine'] ?? '–') }}
                                    <span class="block font-mono text-xs text-gray-500 dark:text-gray-400">{{ $s['machine'] ?? '' }} · {{ str_replace('_', '.', (string) ($s['protocol'] ?? '')) }}@if ($s['encrypted'] ?? false) · verschlüsselt @elseif ($s['signed'] ?? false) · signiert @endif</span>
                                </td>
                                <td class="hidden px-4 py-3 align-top text-gray-700 md:table-cell dark:text-gray-300">{{ implode(', ', (array) ($s['shares'] ?? [])) ?: '–' }}</td>
                                <td class="hidden px-4 py-3 align-top text-gray-700 lg:table-cell dark:text-gray-300">{{ Sessions::when($s['connected_at'] ?? 0) }}</td>
                                <td class="hidden px-4 py-3 text-right align-top tabular-nums sm:table-cell">{{ (int) ($s['open_files'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-right align-top">
                                    <x-input.button type="button" size="sm" color="gray" data-disconnect
                                        x-on:click="$store.confirm.ask({ title: 'Sitzung trennen?', text: {{ Js::from('Die Verbindung von '.($s['user'] ?? '?').' wird getrennt. Offene Dateien dieses Rechners werden ohne Speichern geschlossen; Windows verbindet beim nächsten Zugriff neu.') }}, button: 'Trennen', ok: () => $wire.disconnect({{ Js::from((string) $s['id']) }}) })">
                                        Sitzung trennen
                                    </x-input.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-panel>
        @endif

        <div class="mx-3 mt-6 flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-DINPro-bold text-gray-900 dark:text-gray-100">Offene Dateien</h2>
            @if ($shareOptions)
                <x-input.combobox wire:model.live="share" label="Freigabe" :options="$shareOptions" placeholder="Alle Freigaben" reset="Alle Freigaben" class="w-full sm:w-56" />
            @endif
        </div>
        <p class="mx-3 mt-1 text-xs text-gray-500 dark:text-gray-400">Eine einzelne Datei lässt sich nicht schließen. Hängt eine Datei, trenne die Sitzung, zu der sie gehört.</p>
        @if ($filesError)
            <p class="m-3 text-sm text-amber-800 dark:text-amber-300">{{ $filesError }}</p>
        @elseif (! $files)
            <x-emptystate message="Keine offenen Dateien." />
        @else
            <x-panel class="relative m-3 overflow-x-auto" polster="keins">
                <table class="w-full text-left text-sm text-gray-900 dark:text-gray-100" data-open-files>
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr><th class="px-4 py-3">Datei</th><th class="px-4 py-3">Benutzer</th><th class="hidden px-4 py-3 sm:table-cell">Zugriff</th><th class="hidden px-4 py-3 md:table-cell">Geöffnet</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($files as $f)
                            <tr>
                                <td class="px-4 py-3 align-top"><span class="font-medium">{{ $f['share'] ?? '' }}</span><span class="block wrap-anywhere font-mono text-xs text-gray-600 dark:text-gray-300">{{ $f['path'] ?? '' }}</span></td>
                                <td class="px-4 py-3 align-top">{{ $f['user'] ?? '' }}</td>
                                <td class="hidden px-4 py-3 align-top sm:table-cell">{{ $modes[$f['mode'] ?? ''] ?? ($f['mode'] ?? '') }}</td>
                                <td class="hidden px-4 py-3 align-top text-gray-700 md:table-cell dark:text-gray-300">{{ Sessions::when($f['opened_at'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-panel>
        @endif
    @endif
</div>
