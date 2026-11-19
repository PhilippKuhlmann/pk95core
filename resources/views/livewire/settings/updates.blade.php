@php
    use App\Services\Updates\UpdateService;
    $planned = collect($plan['apps'])->where('planned', true);
    $waiting = collect($plan['apps'])->where('planned', false)->filter(fn ($row) => ! in_array($row['reason'], ['nur melden', 'Updates aus'], true));
    $corePlanned = $plan['core']['planned'] ?? false;
    $coreWaiting = $plan['core'] !== null && ! $corePlanned && $plan['core']['reason'] !== 'nur melden';
    $installedLabel = fn ($app) => UpdateService::isVersion((string) $app->version) ? ltrim((string) $app->version, 'v') : ($app->version ?: '„'.$app->tag.'“');
@endphp

<div>
    <x-page-header title="Updates">
        <x-slot:sub>Was PK95 Core und die Apps selbst aktualisieren dürfen. Automatische Updates laufen im Wartungsfenster, die Apps zuerst, PK95 Core zuletzt.</x-slot:sub>
        <x-input.button type="button" color="gray" wire:click="checkUpdates" wire:loading.attr="disabled" wire:target="checkUpdates">
            <x-icon name="refresh" class="h-4 w-4" sw="2" wire:loading.class="animate-spin" wire:target="checkUpdates" />Nach Updates suchen
        </x-input.button>
        <x-input.button type="button" :disabled="$updateCount === 0 || $run !== null" data-update-all
            x-on:click="$store.confirm.ask({ title: 'Jetzt alle Updates installieren?', tone: 'blue', button: 'Alle installieren', text: 'Core aktualisiert die Apps nacheinander, jede mit Sicherung vorher und Rückkehr zur vorigen Version, wenn sie danach nicht startet. PK95 Core kommt zuletzt, dann ist die Oberfläche etwa eine Minute nicht erreichbar. Den Fortschritt zeigt die Seite Apps.', ok: () => $wire.updateAll() })">
            <x-icon name="rocket" class="h-4 w-4" sw="2" />{{ 'Jetzt alle installieren'.($updateCount && ! $run ? " ({$updateCount})" : '') }}
        </x-input.button>
    </x-page-header>

    <div class="m-3 max-w-4xl space-y-3">
        @if ($run)
            <x-callout :title="($run['automatic'] ?? false) ? 'Automatische Updates laufen' : 'Alle Updates laufen'" data-update-run>
                Eins nach dem anderen, PK95 Core zuletzt. <a href="{{ route('apps.index') }}" wire:navigate class="font-medium underline">Fortschritt unter Apps</a>
            </x-callout>
        @endif

        {{-- what the next maintenance window does --}}
        <x-panel data-next-window>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">Nächstes Wartungsfenster: {{ $window }}</div>
                <a href="{{ route('settings') }}" wire:navigate class="text-sm text-cerulean-700 hover:underline dark:text-cerulean-300">Uhrzeit ändern ({{ $maintenanceTime }} Uhr)</a>
            </div>
            @if ($planned->isEmpty() && ! $corePlanned)
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300" data-planned-none>Keine automatischen Updates geplant, nur die täglichen Sicherungen.</p>
            @else
                <div class="mt-2 text-sm text-gray-700 dark:text-gray-300">Geplant:</div>
                <ul class="mt-1 space-y-1 text-sm" data-planned>
                    @foreach ($planned as $row)
                        <li class="flex flex-wrap items-center gap-x-2"><span class="h-2 w-2 shrink-0 rounded-full bg-green-500" aria-hidden="true"></span>
                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ $row['name'] }}</span>
                            <span class="text-gray-600 dark:text-gray-300">{{ $row['from'] }} → {{ $row['to'] }}</span></li>
                    @endforeach
                    @if ($corePlanned)
                        <li class="flex flex-wrap items-center gap-x-2"><span class="h-2 w-2 shrink-0 rounded-full bg-green-500" aria-hidden="true"></span>
                            <span class="font-medium text-gray-900 dark:text-gray-100">PK95 Core</span>
                            <span class="text-gray-600 dark:text-gray-300">{{ $plan['core']['from'] }} → {{ $plan['core']['to'] }}, zuletzt</span></li>
                    @endif
                </ul>
            @endif
            @if ($waiting->isNotEmpty() || $coreWaiting)
                <div class="mt-3 text-sm text-gray-700 dark:text-gray-300">Wartet:</div>
                <ul class="mt-1 space-y-1 text-sm" data-waiting>
                    @foreach ($waiting as $row)
                        <li class="flex flex-wrap items-center gap-x-2"><span class="h-2 w-2 shrink-0 rounded-full bg-amber-500" aria-hidden="true"></span>
                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ $row['name'] }}</span>
                            <span class="text-gray-600 dark:text-gray-300">{{ $row['from'] }} → {{ $row['to'] }}</span>
                            <span class="text-amber-800 dark:text-amber-300">{{ $row['reason'] }}</span></li>
                    @endforeach
                    @if ($coreWaiting)
                        <li class="flex flex-wrap items-center gap-x-2"><span class="h-2 w-2 shrink-0 rounded-full bg-amber-500" aria-hidden="true"></span>
                            <span class="font-medium text-gray-900 dark:text-gray-100">PK95 Core</span>
                            <span class="text-gray-600 dark:text-gray-300">{{ $plan['core']['from'] }} → {{ $plan['core']['to'] }}</span>
                            <span class="text-amber-800 dark:text-amber-300">{{ $plan['core']['reason'] }}</span></li>
                    @endif
                </ul>
            @endif
            <p class="mt-3 flex gap-2 text-xs text-gray-500 dark:text-gray-400">
                <x-icon name="info" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>Vor jedem Update sichert Core die App. Startet sie danach nicht gesund, kommt die vorige Version mit ihren Daten zurück. PK95 Core kehrt ebenso zum vorigen Image zurück. Das Ergebnis steht unter Ereignisse.</span>
            </p>
        </x-panel>

        {{-- Core --}}
        <x-panel data-core-policy>
            <div class="text-lg font-CoconPro text-gray-900 dark:text-gray-100">PK95 Core</div>
            <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-[10rem_1fr]">
                <div class="text-sm">
                    <div class="text-gray-500 dark:text-gray-400">Installiert</div>
                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ UpdateService::isVersion((string) $core['version']) ? 'Version '.ltrim((string) $core['version'], 'v') : '„'.$core['version'].'“' }}</div>
                    <div class="mt-2 text-gray-500 dark:text-gray-400">Verfügbar</div>
                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ $plan['core']['to'] ?? 'aktuell' }}</div>
                </div>
                <x-field label="Core-Updates" for="u-core" hilfe="Eine neue Hauptversion (2.0) installiert Core nie von selbst, auch nicht bei „alle Versionen“: Sie kann die Versionen ändern, die zu den Apps passen.">
                    <x-input.select id="u-core" wire:model.live="corePolicy" class="mt-1 w-full">
                        @foreach ($corePolicies as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </x-input.select>
                </x-field>
            </div>
        </x-panel>

        {{-- apps --}}
        <x-panel polster="keins" data-app-policies>
            <div class="px-4 pt-4 text-lg font-CoconPro text-gray-900 dark:text-gray-100">Apps</div>
            @if ($apps->isEmpty())
                <p class="px-4 pt-2 pb-4 text-sm text-gray-600 dark:text-gray-300">Noch keine App installiert.</p>
            @else
                <table class="mt-2 w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr><th class="px-4 py-2">App</th><th class="hidden px-4 py-2 sm:table-cell">Installiert</th><th class="hidden px-4 py-2 sm:table-cell">Verfügbar</th><th class="hidden px-4 py-2 sm:table-cell">Update-Regel</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($apps as $app)
                            @php
                                $row = $reasons[$app->app_id] ?? null;
                                $available = $row ? $row['to'] : ($app->update_checked_at ? 'aktuell' : 'noch nicht geprüft');
                            @endphp
                            <tr wire:key="pol-{{ $app->app_id }}" data-app="{{ $app->app_id }}">
                                <td class="px-4 py-3 align-top">
                                    <a href="{{ route('apps.show', $app) }}" wire:navigate class="font-medium text-gray-900 hover:text-cerulean-700 dark:text-gray-100 dark:hover:text-cerulean-300">{{ $app->displayName() }}</a>
                                    <div class="text-xs text-gray-500 sm:hidden dark:text-gray-400">{{ $installedLabel($app) }} · {{ $available }}</div>
                                    @if ($row)
                                        <div @class(['mt-0.5 text-xs', 'text-green-700 dark:text-green-300' => $row['planned'], 'text-amber-800 dark:text-amber-300' => ! $row['planned']]) data-plan-state>
                                            {{ $row['planned'] ? 'im nächsten Wartungsfenster' : $row['reason'] }}
                                        </div>
                                    @endif
                                    {{-- on phones the policy sits under the name, at full width --}}
                                    <x-input.select :id="'u-app-m-'.$app->app_id" wire:model.live="policyOf.{{ $app->app_id }}" aria-label="Update-Regel für {{ $app->displayName() }}" class="mt-2 w-full text-sm sm:hidden">
                                        @foreach ($policies as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                    </x-input.select>
                                </td>
                                <td class="hidden px-4 py-3 align-top text-gray-700 sm:table-cell dark:text-gray-300">{{ $installedLabel($app) }}</td>
                                <td class="hidden px-4 py-3 align-top text-gray-700 sm:table-cell dark:text-gray-300">{{ $available }}</td>
                                <td class="hidden px-4 py-2 align-top sm:table-cell">
                                    <x-input.select :id="'u-app-'.$app->app_id" wire:model.live="policyOf.{{ $app->app_id }}" aria-label="Update-Regel für {{ $app->displayName() }}" class="w-full text-sm sm:w-[22rem]">
                                        @foreach ($policies as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                    </x-input.select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <div class="grid grid-cols-1 gap-4 border-t border-gray-200 p-4 sm:grid-cols-2 dark:border-gray-700">
                @if ($apps->isNotEmpty())
                    <x-field label="Für alle Apps setzen" for="u-all" feld="allPolicy">
                        <div class="mt-1 flex gap-2">
                            <x-input.select id="u-all" feld="allPolicy" wire:model="allPolicy" class="min-w-0 flex-1">
                                <option value="">Regel wählen …</option>
                                @foreach ($policies as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                            </x-input.select>
                            <x-input.button type="button" color="gray" wire:click="setAll">Setzen</x-input.button>
                        </div>
                    </x-field>
                @endif
                <x-field label="Standard für neu installierte Apps" for="u-default" hilfe="Gilt für Apps, die ab jetzt aktiviert werden.">
                    <x-input.select id="u-default" wire:model.live="appPolicy" class="mt-1 w-full">
                        @foreach ($policies as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </x-input.select>
                </x-field>
            </div>
        </x-panel>
    </div>
</div>
