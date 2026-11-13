{{--
    Master dashboard. Status line and Core panel come from Core's own data; problems and the
    app tiles load lazily, each in its own request (a slow app only delays its own tile).
--}}
<div class="p-3 sm:p-5">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <div class="text-3xl font-CoconPro text-gray-900 dark:text-gray-100">Dashboard</div>
            {{-- status line (replaces the former 4 / 4 / 4 / 0 tiles) --}}
            <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-600 dark:text-gray-300" data-status-line>
                <span class="inline-flex items-center gap-1.5">
                    <span @class(['h-2 w-2 rounded-full', 'bg-green-500' => $running === $apps->count(), 'bg-amber-500' => $running !== $apps->count()]) aria-hidden="true"></span>
                    {{ $running }} von {{ $apps->count() }} {{ $apps->count() === 1 ? 'App läuft' : 'Apps laufen' }}
                </span>
                @if ($updates)
                    <a href="{{ route('apps.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-amber-800 hover:underline dark:text-amber-200">
                        <x-icon name="rocket" class="h-4 w-4" sw="2" />{{ $updates === 1 ? '1 Update verfügbar' : $updates.' Updates verfügbar' }}
                    </a>
                @else
                    <span class="inline-flex items-center gap-1.5"><x-icon name="check" class="h-4 w-4 text-green-600 dark:text-green-400" sw="2" />Alles aktuell</span>
                @endif
                <span class="inline-flex items-center gap-1.5">
                    <span @class(['h-2 w-2 rounded-full', 'bg-green-500' => $dockerOk, 'bg-rose-500' => ! $dockerOk]) aria-hidden="true"></span>Docker {{ $dockerOk ? 'erreichbar' : 'nicht erreichbar' }}
                </span>
            </div>
        </div>
        <x-input.linkbutton :href="route('apps.index')">
            <x-icon name="apps" class="h-4 w-4" sw="2" />Apps verwalten
        </x-input.linkbutton>
    </div>

    @unless ($dockerOk)
        <x-callout title="Docker ist nicht erreichbar" ton="gelb" class="mb-4">
            Core steuert die App-Container über den Docker-Socket ({{ config('pk95.docker.socket') }}).
            Prüfe, ob er in den Core-Container eingebunden ist (siehe docker-compose.yml).
        </x-callout>
    @endunless

    @if ($apps->isEmpty())
        <x-callout title="Noch keine App aktiviert" class="mb-4">
            Aktiviere eine App aus dem Katalog. Core startet sie als eigenen Container und blendet ihre Menüpunkte in der Seitenleiste ein.
            <x-slot:aktionen>
                <x-input.linkbutton :href="route('apps.index')">
                    <x-icon name="plus" class="h-4 w-4" sw="2.2" />App aktivieren
                </x-input.linkbutton>
            </x-slot:aktionen>
        </x-callout>
    @else
        {{-- problems first, clearly visible --}}
        <livewire:dashboard.problem-list />

        {{-- figures and charts of the last 24 h, one tile per app, and Core itself --}}
        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
            @foreach ($panels as $id)
                <livewire:dashboard.app-panel :app-id="$id" :key="'panel-'.$id" />
            @endforeach
            <x-panel class="flex h-full flex-col" data-core-panel>
                <div class="flex items-center gap-2">
                    <img src="{{ asset('img/logo.svg') }}" alt="" width="20" height="20" class="h-5 w-5 shrink-0" />
                    <span class="min-w-0 flex-1 truncate font-DINPro-bold text-gray-900 dark:text-gray-100">PK95 Core</span>
                    <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ \App\Services\Updates\CoreUpdater::versionLabel() }}</span>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Letzte Sicherung</div>
                        <div class="text-sm font-DINPro-bold text-gray-900 dark:text-gray-100" data-tip="{{ $lastBackup ? \App\Http\Support\Format::dt($lastBackup->created_at) : '' }}">{{ \App\Livewire\Dashboard::ago($lastBackup?->created_at) }}</div>
                        @if ($lastBackupApp)<div class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $lastBackupApp }}</div>@endif
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Nächstes Wartungsfenster</div>
                        <a href="{{ route('settings') }}" wire:navigate class="text-sm font-DINPro-bold text-gray-900 hover:text-cerulean-700 dark:text-gray-100 dark:hover:text-cerulean-300">{{ $maintenance ?? 'nicht festgelegt' }}</a>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Update</div>
                        @if ($coreUpdate)
                            <a href="{{ route('apps.index') }}" wire:navigate class="text-sm font-DINPro-bold text-amber-800 hover:underline dark:text-amber-200">verfügbar</a>
                        @else
                            <div class="text-sm font-DINPro-bold text-gray-900 dark:text-gray-100">aktuell</div>
                        @endif
                    </div>
                </div>
            </x-panel>
        </div>

        {{-- app cards: status, installed version, available update --}}
        <div class="mt-6 mb-2 text-xl font-CoconPro text-gray-900 dark:text-gray-100">Apps</div>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($apps as $app)
                <a href="{{ route('apps.show', $app) }}" wire:key="dash-{{ $app->app_id }}"
                    class="group flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-xs transition hover:border-cerulean-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-cerulean-500">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-cerulean-50 text-cerulean-600 dark:bg-gray-700 dark:text-cerulean-400">
                        <x-icon :name="$app->manifest['icon'] ?? $app->definition()?->icon ?? 'box'" class="h-5 w-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $app->displayName() }}</span>
                        <span class="flex flex-wrap items-center gap-x-2 text-xs text-gray-500 dark:text-gray-400">
                            <span>{{ $app->version ? 'Version '.ltrim($app->version, 'v') : '„'.$app->tag.'“' }}</span>
                            @if ($app->available_version)
                                <span class="rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-900 dark:bg-amber-900 dark:text-amber-100" data-update-badge>→ {{ ltrim($app->available_version, 'v') }}</span>
                            @endif
                        </span>
                    </span>
                    <x-status-pill :status="$app->status" class="shrink-0" />
                </a>
            @endforeach
        </div>
    @endif

    @if ($events->isNotEmpty())
        <x-panel class="mt-6">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div class="text-xl font-CoconPro text-gray-900 dark:text-gray-100">Letzte Ereignisse</div>
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
