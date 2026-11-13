{{--
    Core itself as the first card on the Apps page: build, newer build in the registry, self
    update. $core comes from CoreUpdater::status(). While an update runs the card carries
    data-running; coreRestart (app.js) waits for the restart and reloads the page.
--}}
@php
    use App\Services\Updates\CoreUpdater;
    $running = $core['running'] !== null;
    $available = $core['available'];
    $last = $core['last'];
    $lastBad = $last && in_array($last['result'] ?? '', ['failed', 'rolled_back'], true);
    $lastAt = \App\Http\Support\Format::local($last['at'] ?? null);
    $chip = 'inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-gray-700 dark:text-gray-200';
@endphp

<x-panel class="flex flex-col ring-1 ring-cerulean-200 dark:ring-cerulean-900" wire:key="app-core"
    x-data="coreRestart({{ $running ? 'true' : 'false' }})" x-on:core-restarting.window="start()"
    data-core-card :data-running="$running">
    <div class="flex items-start gap-4">
        <img src="{{ asset('img/logo.svg') }}" alt="" width="48" height="48" class="h-12 w-12 shrink-0 rounded-lg" />
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xl font-CoconPro text-gray-900 dark:text-gray-100">PK95 Core</span>
                @if ($running)
                    <span class="inline-flex items-center gap-1 rounded bg-cerulean-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-200">
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-cerulean-500" aria-hidden="true"></span>Wird aktualisiert
                    </span>
                @elseif ($available)
                    <span class="inline-flex items-center rounded bg-amber-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-900 dark:bg-amber-900 dark:text-amber-100">Update verfügbar</span>
                @else
                    <x-status-pill status="running" />
                @endif
            </div>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Diese Oberfläche. Startet die Apps als Container und hält sie aktuell.</p>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-1.5 text-xs">
        {{-- like the app cards: image without its tag (":latest" says nothing), the version next to it --}}
        @if ($core['image'])
            <span class="{{ $chip }} font-mono" data-tip="{{ $core['image'] }}">{{ CoreUpdater::splitRef($core['image'])[0] }}</span>
        @endif
        <span class="{{ $chip }}">Version {{ $core['version'] }}</span>
        @if ($core['revision'])
            <span class="{{ $chip }}" data-tip="Commit, aus dem das laufende Image gebaut wurde">
                Build <span class="font-mono">{{ $core['revision'] }}</span>@if ($core['built']) · {{ $core['built']->format('d.m.Y') }}@endif
            </span>
        @endif
    </div>

    <div class="mt-3 text-sm">
        @if (! $core['docker'])
            <p class="text-gray-500 dark:text-gray-400">Läuft nicht als Docker-Container, Updates gibt es hier nicht.</p>
        @elseif ($running)
            <p class="flex items-start gap-2 text-cerulean-700 dark:text-cerulean-300">
                <span class="mt-1.5 h-2 w-2 shrink-0 animate-pulse rounded-full bg-cerulean-500" aria-hidden="true"></span>
                Das neue Image wird geladen, danach startet PK95 Core neu. Die Seite ist kurz nicht erreichbar und lädt dann von selbst neu.
            </p>
        @elseif ($available)
            <p class="text-gray-700 dark:text-gray-200">
                @if ($available['version'] ?? null)
                    installiert Version {{ $core['version'] }} → neu Version {{ $available['version'] }}
                    <span class="text-gray-500 dark:text-gray-400">({{ CoreUpdater::describe(['revision' => $available['revision'], 'created' => $available['created']]) }})</span>
                @else
                    Neu: {{ CoreUpdater::describe($available) }}.
                @endif
            </p>
        @else
            <p class="text-gray-500 dark:text-gray-400">
                Aktuell{{ $core['checked_at'] ? ', zuletzt geprüft '.$core['checked_at']->diffForHumans() : '' }}.
            </p>
        @endif

        @if (! $running && $last && $lastAt && $lastAt->gt(now()->subDay()))
            @if ($lastBad)
                <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
                    <span class="font-medium">Letztes Update fehlgeschlagen.</span>
                    {{ ($last['message'] ?? '') ?: ($last['result'] === 'rolled_back' ? 'Die vorige Version läuft weiter.' : '') }}
                    @if ($last['log'] ?? '')
                        <details class="mt-1">
                            <summary class="cursor-pointer text-xs">Protokoll</summary>
                            <pre class="mt-1 max-h-48 overflow-auto whitespace-pre-wrap text-xs">{{ $last['log'] }}</pre>
                        </details>
                    @endif
                </div>
            @elseif ($last['result'] === 'done')
                <p class="mt-1 text-xs text-green-700 dark:text-green-300">Aktualisiert {{ $lastAt->diffForHumans() }}.</p>
            @endif
        @endif

        @if ($core['docker'] && ! $core['compose'])
            <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">PK95 Core wurde nicht mit docker compose gestartet. Updates dann von Hand im LXC: <code>docker compose pull &amp;&amp; docker compose up -d</code></p>
        @endif
    </div>

    @if ($core['docker'] && ! $running)
        <div class="mt-auto flex flex-wrap items-center justify-end gap-2 pt-5">
            <x-input.button type="button" color="gray" wire:click="checkCore" wire:loading.attr="disabled" wire:target="checkCore">
                <x-icon name="refresh" class="h-4 w-4" sw="2" wire:loading.class="animate-spin" wire:target="checkCore" />Nach Updates suchen
            </x-input.button>
            @if ($available && $core['compose'] && ! $run)
                <x-input.button type="button"
                    x-on:click="$store.confirm.ask({ title: 'PK95 Core aktualisieren?', tone: 'blue', button: 'Aktualisieren', text: 'Core lädt das neue Image und startet neu. Die Oberfläche ist etwa eine Minute nicht erreichbar, die Apps laufen weiter. Startet die neue Version nicht, kehrt Core von selbst zur vorigen zurück.', ok: () => $wire.updateCore() })">
                    <x-icon name="rocket" class="h-4 w-4" sw="2" />Aktualisieren
                </x-input.button>
            @endif
        </div>
    @endif
</x-panel>
