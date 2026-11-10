<div class="p-3 sm:p-5">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div class="text-3xl font-CoconPro text-gray-900 dark:text-gray-100">Dashboard</div>
        <x-input.linkbutton :href="route('apps.index')">
            <x-icon name="apps" class="w-4 h-4" sw="2" />Apps verwalten
        </x-input.linkbutton>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-6">
        <x-stat-tile :href="route('apps.index')" icon="apps" :value="$available" label="Apps im Katalog" />
        <x-stat-tile :href="route('apps.index')" icon="box" :value="$apps->count()" label="Aktiviert" />
        <x-stat-tile :href="route('apps.index')" icon="check" ton="gruen" :value="$running" label="Laufen" />
        <x-stat-tile :href="route('apps.index')" icon="warn" ton="rot" :value="$problems" label="Mit Problemen" />
    </div>

    @unless ($dockerOk)
        <x-callout title="Docker ist nicht erreichbar" ton="gelb" class="mb-5">
            Core steuert die App-Container über den Docker-Socket ({{ config('pk95.docker.socket') }}).
            Prüfe, ob er in den Core-Container eingebunden ist (siehe docker-compose.yml).
        </x-callout>
    @endunless

    @if ($apps->isEmpty())
        <x-callout title="Noch keine App aktiviert" class="mb-5">
            Aktiviere eine App aus dem Katalog. Core startet sie als eigenen Container und blendet ihre Menüpunkte in der Seitenleiste ein.
            <x-slot:aktionen>
                <x-input.linkbutton :href="route('apps.index')">
                    <x-icon name="plus" class="w-4 h-4" sw="2.2" />App aktivieren
                </x-input.linkbutton>
            </x-slot:aktionen>
        </x-callout>
    @else
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($apps as $app)
                <a href="{{ route('apps.show', $app) }}" wire:key="dash-{{ $app->app_id }}"
                    class="group flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-xs transition hover:border-cerulean-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-cerulean-500">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-cerulean-50 text-cerulean-600 dark:bg-gray-700 dark:text-cerulean-400">
                        <x-icon :name="$app->manifest['icon'] ?? $app->definition()?->icon ?? 'box'" class="h-5 w-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $app->displayName() }}</span>
                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                            {{ $app->version ? 'Version '.$app->version : $app->tag }}
                            @if ($app->last_seen_at) · zuletzt erreichbar {{ $app->last_seen_at->diffForHumans() }} @endif
                        </span>
                    </span>
                    <x-status-pill :status="$app->status" class="shrink-0" />
                </a>
            @endforeach
        </div>
    @endif

    @if ($events->isNotEmpty())
        <x-panel class="mt-5">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div class="text-2xl font-CoconPro text-gray-900 dark:text-gray-100">Letzte Ereignisse</div>
                <a href="{{ route('events.index') }}" class="text-sm text-cerulean-600 hover:text-cerulean-700 dark:text-cerulean-400">Alle anzeigen</a>
            </div>
            <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                @foreach ($events as $event)
                    <li class="flex gap-3 py-2">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ ['critical' => 'bg-rose-500', 'warning' => 'bg-amber-500'][$event->severity] ?? 'bg-cerulean-500' }}" aria-hidden="true"></span>
                        <span class="w-24 shrink-0 font-mono text-xs text-gray-500 dark:text-gray-400">{{ \App\Http\Support\Format::local($event->occurred_at)?->format('d.m. H:i') }}</span>
                        <span class="min-w-0 text-gray-800 dark:text-gray-100"><span class="font-DINPro-bold">{{ $event->appName() }}:</span> {{ $event->title }}</span>
                    </li>
                @endforeach
            </ul>
        </x-panel>
    @endif
</div>
