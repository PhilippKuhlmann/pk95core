@php
    use App\Modules\Proxy\Fmt;
    use App\Modules\Proxy\Health;
    $logUrl = fn ($d) => route('apps.page', [$installedApp, 'log']).'?host='.urlencode($d);
    $cell = fn ($b) => $b['total'] <= 0 ? 'bg-gray-200 dark:bg-gray-700'
        : ($b['ok'] === $b['total'] ? 'bg-green-500' : ($b['ok'] === 0 ? 'bg-rose-500' : 'bg-amber-500'));
@endphp

<div wire:poll.20s>
    <x-page-header title="Erreichbarkeit">
        <x-slot:sub>{{ $summary }}</x-slot:sub>
    </x-page-header>

    @if ($loadError)
        <x-callout title="Verfügbarkeit konnte nicht geladen werden" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    @if (! $hosts && ! $loadError)
        <x-panel class="m-3" polster="weit">
            <div class="text-center">
                <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Noch keine Hosts</div>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Sobald ein Host aktiv ist, wird sein Ziel jede Minute geprüft.</p>
                <a href="{{ route('apps.page', [$installedApp, 'hosts']) }}" wire:navigate class="mt-4 inline-block text-sm text-cerulean-700 hover:underline dark:text-cerulean-300">Host einrichten</a>
            </div>
        </x-panel>
    @elseif ($hosts)
        <x-panel class="m-3 overflow-x-auto" polster="keins">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Host</th><th class="px-4 py-3">Status</th><th class="hidden px-4 py-3 md:table-cell">Letzte 24 Stunden</th>
                        <th class="px-4 py-3 text-right">24 Std.</th><th class="px-4 py-3 text-right">7 Tage</th><th class="px-4 py-3 text-right">30 Tage</th><th class="px-4 py-3 text-right">Ø Antwort</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($hosts as $x)
                        @php [$tone, $text, $sub] = Health::label($x, $now); @endphp
                        <tr wire:key="hh-{{ $x['host_id'] }}" @class(['opacity-60' => ! $x['enabled']])>
                            <td class="px-4 py-3 align-top">
                                @foreach ($x['domains'] as $d)
                                    <a href="{{ $logUrl($d) }}" wire:navigate class="block font-medium text-cerulean-700 hover:underline dark:text-cerulean-300">{{ $d }}</a>
                                @endforeach
                                <div class="font-mono text-xs text-gray-500">{{ $x['target'] }}</div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot($tone) }}"></span>{{ $text }}</div>
                                @if ($sub)<div class="text-xs text-gray-500 dark:text-gray-400">{{ $sub }}</div>@endif
                            </td>
                            <td class="hidden px-4 py-3 align-middle md:table-cell">
                                @if ($x['enabled'])
                                    @php
                                        $measured = count(array_filter($x['timeline'], fn ($b) => $b['total'] > 0));
                                        $bad = count(array_filter($x['timeline'], fn ($b) => $b['total'] > 0 && $b['ok'] < $b['total']));
                                    @endphp
                                    <div class="flex h-6 w-64 gap-px" role="img" aria-label="{{ $measured ? "Letzte 24 Stunden: {$bad} von {$measured} Abschnitten mit Fehlern" : 'Noch keine Messwerte' }}">
                                        @foreach ($x['timeline'] as $b)
                                            @php $failed = $b['total'] - $b['ok']; @endphp
                                            <i class="flex-1 rounded-sm {{ $cell($b) }}"
                                                data-tip="{{ Fmt::time($b['ts']) }} Uhr: {{ $b['total'] <= 0 ? 'keine Messung' : ($failed === 0 ? "alle {$b['total']} Prüfungen ok" : "{$failed} von {$b['total']} Prüfungen fehlgeschlagen") }}"></i>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right align-top tabular-nums">{{ Fmt::uptime($x['uptime_24h']) }}</td>
                            <td class="px-4 py-3 text-right align-top tabular-nums">{{ Fmt::uptime($x['uptime_7d']) }}</td>
                            <td class="px-4 py-3 text-right align-top tabular-nums">{{ Fmt::uptime($x['uptime_30d']) }}</td>
                            <td class="px-4 py-3 text-right align-top tabular-nums">{{ Fmt::ms($x['avg_ms']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="flex flex-wrap gap-4 border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                <span class="flex items-center gap-1.5"><i class="h-3 w-3 rounded-sm bg-green-500"></i>Alle Prüfungen ok</span>
                <span class="flex items-center gap-1.5"><i class="h-3 w-3 rounded-sm bg-amber-500"></i>Teilweise fehlgeschlagen</span>
                <span class="flex items-center gap-1.5"><i class="h-3 w-3 rounded-sm bg-rose-500"></i>Ausgefallen</span>
                <span class="flex items-center gap-1.5"><i class="h-3 w-3 rounded-sm bg-gray-200 dark:bg-gray-700"></i>Keine Messung</span>
            </div>
        </x-panel>

        <x-panel class="m-3">
            <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">Ausfälle und Erholungen</div>
            @if (! $events)
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Bisher keine Ausfälle erfasst.</p>
            @else
                <ol class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @foreach ($events as $e)
                        <li class="flex flex-wrap items-center gap-x-4 gap-y-1 py-2">
                            <span class="whitespace-nowrap text-xs text-gray-500 tabular-nums">{{ Fmt::stamp($e['ts']) }}</span>
                            <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ Fmt::dot($e['up'] ? 'good' : 'bad') }}"></span>{{ $e['up'] ? 'Wieder erreichbar' : 'Ausgefallen' }}</span>
                            <span class="font-medium">{{ $e['domain'] ?: 'gelöschter Host' }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $e['up'] ? (isset($e['lasted']) ? 'nach '.Fmt::duration($e['lasted']) : '') : $e['detail'] }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-panel>
    @endif
</div>
