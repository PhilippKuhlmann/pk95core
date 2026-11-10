@php
    use App\Services\Updates\UpdateService;
    $stepState = [
        'waiting' => ['wartet', 'bg-gray-300 dark:bg-gray-600'],
        'running' => ['läuft', 'animate-pulse bg-cerulean-500'],
        'done' => ['fertig', 'bg-green-500'],
        'failed' => ['fehlgeschlagen', 'bg-rose-500'],
    ];
@endphp

<div @if ($polling) wire:poll.3s="poll" @endif>
    <x-page-header title="Apps">
        <x-slot:sub>PK95 Core und die Apps aus dem Katalog. Aktivierte Apps laufen als eigene Container im Core-LXC und erscheinen in der Seitenleiste.</x-slot:sub>
        @if ($dockerOk)
            <x-input.button type="button" color="gray" wire:click="checkAll" wire:loading.attr="disabled" wire:target="checkAll">
                <x-icon name="refresh" class="h-4 w-4" sw="2" wire:loading.class="animate-spin" wire:target="checkAll" />Nach Updates suchen
            </x-input.button>
            <x-input.button type="button" :disabled="$updateCount === 0 || $run !== null"
                x-on:click="$store.confirm.ask({ title: 'Alle aktualisieren?', tone: 'blue', button: 'Alle aktualisieren', text: 'Core aktualisiert die Apps nacheinander, jede mit Sicherung vorher und Rückkehr zur vorigen Version, wenn sie danach nicht startet. PK95 Core kommt zuletzt, dann ist die Oberfläche etwa eine Minute nicht erreichbar.', ok: () => $wire.updateAll() })">
                <x-icon name="rocket" class="h-4 w-4" sw="2" />{{ 'Alle aktualisieren'.($updateCount && ! $run ? " ({$updateCount})" : '') }}
            </x-input.button>
        @endif
    </x-page-header>

    @if ($run)
        <x-callout title="Alle Updates laufen" class="m-3" data-update-run>
            Eins nach dem anderen, PK95 Core zuletzt. Du kannst die Seite verlassen, Core macht im Hintergrund weiter.
            <ol class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                @foreach ($progress as $i => $p)
                    <li class="inline-flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full {{ $stepState[$p['state']][1] }}" aria-hidden="true"></span>
                        <span class="font-medium">{{ $i + 1 }}. {{ $p['name'] }}</span><span>{{ $stepState[$p['state']][0] }}</span>
                    </li>
                @endforeach
            </ol>
        </x-callout>
    @elseif ($lastRun)
        <x-callout :title="$lastRun['title']" :ton="$lastRun['ok'] ? 'blau' : 'gelb'" class="m-3">
            <span class="whitespace-pre-line">{{ $lastRun['body'] }}</span>
        </x-callout>
    @endif

    @unless ($dockerOk)
        <x-callout title="Docker ist nicht erreichbar" ton="gelb" class="m-3">
            Ohne Zugriff auf den Docker-Socket ({{ config('pk95.docker.socket') }}) kann Core keine Apps starten.
        </x-callout>
    @endunless

    @if ($catalogError)
        <div class="m-3 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert">
            <x-icon name="warn" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>Katalog fehlerhaft: {{ $catalogError }}</span>
        </div>
    @endif

    <div class="m-3 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        @include('livewire.apps.partials.core-card')

        @foreach ($definitions as $def)
            @php($app = $installed[$def->id] ?? null)
            {{-- an installed app (it has a version) that is being installed again is being updated --}}
            @php($status = $app?->status === 'installing' && $app->version ? 'updating' : ($app?->status ?? 'available'))
            <x-panel class="flex flex-col" wire:key="app-{{ $def->id }}">
                <div class="flex items-start gap-4">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-cerulean-50 text-cerulean-600 dark:bg-gray-700 dark:text-cerulean-400">
                        <x-icon :name="$def->icon" class="h-6 w-6" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($app)
                                <a href="{{ route('apps.show', $app) }}" class="text-xl font-CoconPro text-gray-900 hover:text-cerulean-600 dark:text-gray-100 dark:hover:text-cerulean-400">{{ $def->name }}</a>
                            @else
                                <span class="text-xl font-CoconPro text-gray-900 dark:text-gray-100">{{ $def->name }}</span>
                            @endif
                            <x-status-pill :status="$status" />
                        </div>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $def->summary }}</p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-1.5 text-xs">
                    <span class="inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-0.5 font-mono text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ $def->imageRef($app?->tag) }}</span>
                    @if ($def->networkMode === 'host')
                        <span class="inline-flex items-center rounded bg-amber-100 px-2 py-0.5 text-amber-900 dark:bg-amber-900 dark:text-amber-100">Host-Netz</span>
                    @endif
                    @if ($app?->version)
                        <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-gray-700 dark:text-gray-200">Version {{ $app->version }}</span>
                    @endif
                </div>

                @if ($app?->step)
                    <div class="mt-3 flex items-center gap-2 text-sm text-cerulean-700 dark:text-cerulean-300">
                        <span class="h-2 w-2 shrink-0 animate-pulse rounded-full bg-cerulean-500" aria-hidden="true"></span>{{ $app->step }} …
                    </div>
                @elseif ($app?->available_version && ! $app->isTransitional())
                    @php($change = UpdateService::change($app))
                    <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm dark:border-amber-800/60 dark:bg-amber-950/40" data-update-badge>
                        <div class="font-DINPro-bold text-amber-900 dark:text-amber-100">Update verfügbar</div>
                        <div class="text-amber-800 dark:text-amber-200">installiert {{ $change['from'] }} → neu {{ $change['to'] }}</div>
                    </div>
                @endif
                @if ($app && ($hint = $hints[$def->id] ?? null))
                    <p @class(['mt-2 flex items-start gap-1.5 text-xs', 'text-amber-800 dark:text-amber-200' => $hint['tone'] === 'warn',
                        'text-cerulean-700 dark:text-cerulean-300' => $hint['tone'] === 'info']) data-update-hint>
                        <x-icon :name="$hint['tone'] === 'warn' ? 'warn' : 'info'" class="mt-px h-3.5 w-3.5 shrink-0" sw="2" />{{ $hint['text'] }}
                    </p>
                @endif
                @if ($app?->error)
                    <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ $app->error }}</div>
                @endif

                <div class="mt-auto flex flex-wrap items-center justify-end gap-2 pt-5">
                    @if (! $app)
                        <x-input.button type="button" wire:click="activate('{{ $def->id }}')" wire:loading.attr="disabled">
                            <x-icon name="power" class="h-4 w-4" sw="2" />Aktivieren
                        </x-input.button>
                    @elseif (! $app->isTransitional() || $app->status === 'starting')
                        @if ($app->available_version && ! $app->step && ! $app->isTransitional() && $run === null)
                            <x-input.button type="button"
                                x-on:click="$store.confirm.ask({ title: {{ \Illuminate\Support\Js::from($def->name.' aktualisieren?') }}, tone: 'blue', button: 'Aktualisieren', text: 'Core sichert die Daten, lädt das neue Image und startet die App neu. Klappt die Funktionsprüfung nicht, kehrt Core zur vorigen Version zurück.', ok: () => $wire.updateApp('{{ $def->id }}') })">
                                <x-icon name="rocket" class="h-4 w-4" sw="2" />Aktualisieren
                            </x-input.button>
                        @endif
                        @if (in_array($app->status, ['running', 'unhealthy', 'starting'], true))
                            <x-input.button type="button" color="gray" wire:click="restart('{{ $def->id }}')">
                                <x-icon name="refresh" class="h-4 w-4" sw="2" />Neustart
                            </x-input.button>
                            <x-input.button type="button" color="gray" wire:click="stop('{{ $def->id }}')">
                                <x-icon name="stop" class="h-4 w-4" sw="2" />Stoppen
                            </x-input.button>
                        @elseif ($app->status === 'stopped')
                            <x-input.button type="button" wire:click="start('{{ $def->id }}')">
                                <x-icon name="play" class="h-4 w-4" sw="2" />Starten
                            </x-input.button>
                        @elseif ($app->status === 'failed')
                            <x-input.button type="button" wire:click="activate('{{ $def->id }}')">
                                <x-icon name="refresh" class="h-4 w-4" sw="2" />Erneut aktivieren
                            </x-input.button>
                        @endif
                        <x-input.button type="button" color="gray"
                            x-on:click="$store.confirm.ask({ title: {{ \Illuminate\Support\Js::from($def->name.' entfernen?') }}, text: 'Der Container wird entfernt, die Daten (Volumes) bleiben erhalten. Beim erneuten Aktivieren sind sie wieder da.', button: 'Entfernen', ok: () => $wire.remove('{{ $def->id }}', true) })">
                            <x-icon name="trash" class="h-4 w-4" sw="2" />
                            <span class="sr-only">Entfernen</span>
                        </x-input.button>
                    @endif
                    @if ($app)
                        <x-input.linkbutton :href="route('apps.show', $app)" color="gray">Details</x-input.linkbutton>
                    @endif
                </div>
            </x-panel>
        @endforeach
    </div>

    @if (! $definitions)
        <x-emptystate message="Der Katalog ist leer. Apps liegen als catalog/<id>.yaml vor." />
    @endif
</div>
