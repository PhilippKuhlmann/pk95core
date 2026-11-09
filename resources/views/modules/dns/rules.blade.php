@php
    use App\Modules\Dns\Fmt;
    use App\Modules\Dns\Rules;
    $card = 'font-DINPro-bold text-gray-900 dark:text-gray-100';
    $syntax = [
        ['||example.org^', 'Blockiert example.org und alle Subdomains'],
        ['example.org', 'Blockiert genau example.org (auch |example.org^)'],
        ['*.example.org', 'Blockiert alle Subdomains, nicht example.org selbst'],
        ['@@||example.org^', 'Erlaubt example.org und alle Subdomains (@@ vor jeder Form erlaubt statt zu blockieren)'],
        ['||ads*.example.org^', '* steht für beliebige Zeichen im Namen'],
        ['/^ad[0-9]+\./', 'Regulärer Ausdruck (RE2) auf den Namen, mit @@ davor erlaubt er'],
        ['0.0.0.0 tracker.example', 'Hosts-Schreibweise: blockiert genau diese Namen (auch 127.0.0.1, ::, ::1)'],
        ['192.168.1.5 nas.lan', 'Hosts-Schreibweise mit anderer Adresse: antwortet mit dieser Adresse'],
        ['||example.org^$important', 'Gilt auch gegen Erlaubnisregeln'],
        ['||example.org^$dnstype=AAAA|HTTPS', 'Nur für diese Abfragetypen (~AAAA schließt einen Typ aus)'],
        ['! Kommentar', 'Kommentar, ebenso # am Zeilenanfang und leere Zeilen'],
    ];
@endphp

<div>
    <x-page-header title="Eigene Regeln">
        <x-slot:sub>{{ Fmt::plural($ruleCount, 'Regel', 'Regeln') }} aktiv</x-slot:sub>
    </x-page-header>

    @if ($loadError)
        <x-callout title="PK95 DNS antwortet nicht" ton="gelb" class="m-3">
            {{ $loadError }}
            <x-slot:aktionen>
                <x-input.button type="button" color="gray" wire:click="retry"><x-icon name="refresh" class="h-4 w-4" />Erneut versuchen</x-input.button>
            </x-slot:aktionen>
        </x-callout>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-[3fr_2fr]">
        <x-panel class="m-3">
            {{-- PUT /api/rules replaces the whole text: without the stored rules there is nothing to edit --}}
            @if ($loaded)
                <form wire:submit="save">
                    <x-field label="Regeln" for="r-text" feld="text" hilfe="Eine Regel pro Zeile. Eigene Regeln gehen den Listen vor: eine Erlaubnisregel hier schlägt jede Blockliste.">
                        <x-input.textarea id="r-text" feld="text" rows="18" wire:model="text" placeholder="||werbung.example.com^" class="mt-1" />
                    </x-field>
                    @if ($invalid)
                        <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700/60 dark:bg-amber-900/20">
                            <div class="font-DINPro-bold text-amber-900 dark:text-amber-200">{{ Fmt::plural(count($invalid), 'Zeile wird', 'Zeilen werden') }} ignoriert</div>
                            <ul class="mt-1 flex flex-col gap-1 text-amber-900 dark:text-amber-100">
                                @foreach ($invalid as $bad)
                                    <li><span class="font-semibold">Zeile {{ $bad['line'] }}:</span> <code class="break-all font-mono text-xs">{{ $bad['text'] }}</code> – {{ $bad['error'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <div class="mt-4"><x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button></div>
                </form>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">Die eigenen Regeln konnten nicht geladen werden. Sie lassen sich erst wieder bearbeiten, wenn PK95 DNS antwortet.</p>
            @endif
        </x-panel>

        <div>
            <x-panel class="m-3">
                <div class="{{ $card }}">Domain prüfen</div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Zeigt, was mit einer Anfrage passieren würde. Es wird kein Upstream gefragt.</p>
                <form wire:submit="runCheck" class="mt-3 flex flex-col gap-3">
                    <x-field label="Domain" for="c-name" feld="check.name">
                        <x-input.text id="c-name" feld="check.name" wire:model="check.name" placeholder="ads.example.net" class="mt-1 w-full font-mono text-sm" />
                    </x-field>
                    <div class="grid grid-cols-[auto_1fr] gap-3">
                        <x-field label="Typ" for="c-type" feld="check.type">
                            <x-input.select id="c-type" wire:model="check.type" class="mt-1">
                                @foreach (Rules::TYPES as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach
                            </x-input.select>
                        </x-field>
                        <x-field label="Client (optional)" for="c-client" feld="check.client">
                            <x-input.text id="c-client" feld="check.client" wire:model="check.client" placeholder="192.168.1.20" class="mt-1 w-full font-mono text-sm" />
                        </x-field>
                    </div>
                    <div><x-input.button type="submit" wire:loading.attr="disabled">Prüfen</x-input.button></div>
                </form>
                @if ($result)
                    <div class="mt-4 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700" wire:key="check-result">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="break-all font-mono text-gray-900 dark:text-gray-100">{{ $result['name'] }}</span>
                            <span class="rounded px-1.5 py-0.5 text-[11px] font-semibold {{ Fmt::badge($result['status']) }}">{{ $result['status'] === 'ok' ? 'Keine Regel greift' : Fmt::status($result['status']) }}</span>
                        </div>
                        <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-gray-900 dark:text-gray-100">
                            @if (($result['client_ip'] ?? '') !== '')
                                <dt class="text-gray-500 dark:text-gray-400">Client</dt><dd>{{ $result['client_name'] !== '' ? $result['client_name'].' ('.$result['client_ip'].')' : $result['client_ip'] }}</dd>
                            @endif
                            @if (($result['reason'] ?? '') !== '')
                                <dt class="text-gray-500 dark:text-gray-400">Grund</dt><dd>{{ Fmt::REASONS[$result['reason']] ?? $result['reason'] }}{{ ($result['list_name'] ?? '') !== '' ? ': '.$result['list_name'] : '' }}{{ ($result['service'] ?? '') !== '' ? ': '.$result['service'] : '' }}</dd>
                            @endif
                            @if (($result['rule'] ?? '') !== '')
                                <dt class="text-gray-500 dark:text-gray-400">Regel</dt><dd class="break-all font-mono text-xs">{{ $result['rule'] }}</dd>
                            @endif
                            @if (($result['answer'] ?? '') !== '')
                                <dt class="text-gray-500 dark:text-gray-400">Antwort</dt><dd class="break-all font-mono text-xs">{{ $result['answer'] }}</dd>
                            @endif
                        </dl>
                    </div>
                @endif
            </x-panel>

            <x-panel class="m-3">
                <div class="{{ $card }}">Schreibweise</div>
                <table class="mt-2 w-full text-left text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($syntax as [$rule, $meaning])
                            <tr><td class="whitespace-nowrap py-1.5 pr-3 align-top font-mono text-xs text-gray-900 dark:text-gray-100">{{ $rule }}</td><td class="py-1.5 text-gray-600 dark:text-gray-300">{{ $meaning }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Andere $-Optionen und kosmetische Regeln (##) werden nicht unterstützt und ignoriert. Domains mit Umlauten bitte in Punycode (xn--…) schreiben.</p>
            </x-panel>
        </div>
    </div>
</div>
