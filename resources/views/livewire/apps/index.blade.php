<div @if ($polling) wire:poll.3s="poll" @endif>
    <x-page-header title="Apps">
        <x-slot:sub>Aktivierte Apps laufen als eigene Container im Core-LXC und erscheinen in der Seitenleiste.</x-slot:sub>
    </x-page-header>

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

    @if (! $definitions)
        <x-emptystate message="Der Katalog ist leer. Apps liegen als catalog/<id>.yaml vor." />
    @else
        <div class="m-3 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($definitions as $def)
                @php($app = $installed[$def->id] ?? null)
                @php($status = $app?->status ?? 'available')
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

                    @if ($app?->status === 'installing' && $app->step)
                        <div class="mt-3 text-sm text-cerulean-700 dark:text-cerulean-300">{{ $app->step }} …</div>
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
    @endif
</div>
