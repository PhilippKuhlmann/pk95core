{{-- Port of proxapps resources/views/livewire/deployments/index.blade.php, without a filter
     grouped: running right now, active apps (container exists), history. --}}
@php
    $grouped = $status === '' && isset($live, $running, $history);
    $section = 'mx-3 mt-5 flex items-baseline gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $iconOf = fn ($d) => (object) ['id' => $d->app_id, 'iconPath' => ($icons[$d->app_id] ?? false) ? $d->app_id : null];
@endphp

<div @if ($hasActive) wire:poll.5s @endif>
    <x-page-header title="Deployments">
        @if ($grouped)
            <x-slot:sub>{{ count($live) }} {{ count($live) === 1 ? 'App läuft' : 'Apps laufen' }}@if (count($running)) · {{ count($running) }} in Arbeit @endif</x-slot:sub>
        @endif
        <x-input.select wire:model.live="status" class="w-48 py-2! text-sm!" aria-label="Status filtern">
            <option value="">Übersicht</option>
            <option value="active">Laufend</option>
            <option value="success">Erfolgreich</option>
            <option value="failed">Fehlgeschlagen</option>
            <option value="cancelled">Abgebrochen</option>
            <option value="removed">Entfernt</option>
        </x-input.select>
        <x-input.linkbutton :href="$this->pageUrl('apps')">
            <x-icon name="rocket" class="w-4 h-4" sw="2" />App ausrollen
        </x-input.linkbutton>
    </x-page-header>

    @if ($grouped)
        @if (! count($live) && ! count($running) && $history->items->isEmpty())
            <x-emptystate message="Noch nichts ausgerollt.">
                <x-input.linkbutton :href="$this->pageUrl('apps')">Zum App-Katalog</x-input.linkbutton>
            </x-emptystate>
        @endif

        {{-- running right now: deploying or removing --}}
        @if (count($running))
            <div class="{{ $section }}"><span class="h-2 w-2 animate-pulse rounded-full bg-cerulean-500"></span>Läuft gerade</div>
            <div class="m-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                @foreach ($running as $d)
                    @php
                        $order = $d->status === 'removing' ? $stepOrder['remove'] : $stepOrder['deploy'];
                        $pos = array_search($d->step, collect($order)->all(), true);
                        $pct = $d->status === 'queued' || $pos === false ? 4 : (int) round(($pos + 0.5) / count($order) * 100);
                    @endphp
                    <a href="{{ $this->pageUrl('deployment', ['id' => $d->id]) }}" wire:key="run-{{ $d->id }}"
                        class="block rounded-xl border border-cerulean-300 bg-cerulean-50/60 p-4 shadow-xs transition hover:border-cerulean-500 dark:border-cerulean-800 dark:bg-cerulean-900/20">
                        <div class="flex items-start gap-3">
                            <x-app-icon :app="$iconOf($d)" :installed="$installedApp" class="h-10 w-10" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate font-medium text-gray-900 dark:text-gray-100">{{ $d->app_name }} <span class="font-mono text-sm text-gray-500">· {{ $d->hostname }}</span></span>
                                    <x-status-pill :status="$d->status" class="shrink-0" />
                                </div>
                                <div class="mt-1 text-sm text-cerulean-800 dark:text-cerulean-200">
                                    {{ $d->status === 'queued' ? 'Wartet auf den Start' : ($stepLabels[$d->step] ?? $d->step) }}
                                    @if ($pos !== false && $d->status !== 'queued')<span class="text-xs text-gray-500">(Schritt {{ $pos + 1 }} von {{ count($order) }})</span>@endif
                                </div>
                                <div class="mt-2 h-1.5 rounded-full bg-cerulean-100 dark:bg-cerulean-950">
                                    <div class="h-1.5 rounded-full bg-cerulean-500 transition-all" style="width: {{ $pct }}%"></div>
                                </div>
                                <div class="mt-1.5 text-xs text-gray-500"><span class="font-mono">CT {{ $d->vmid }}</span> · {{ $d->node }} · gestartet {{ \App\Http\Support\Format::dt($d->created_at) }}</div>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- apps whose container exists --}}
        @if (count($live))
            <div class="{{ $section }}"><span class="h-2 w-2 rounded-full bg-green-500"></span>Aktive Apps <span class="font-normal normal-case tracking-normal">({{ count($live) }})</span></div>
            <div class="m-3 grid grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3">
                @foreach ($live as $d)
                    <div wire:key="live-{{ $d->id }}" onclick="if (!event.target.closest('a')) window.location='{{ $this->pageUrl('deployment', ['id' => $d->id]) }}'"
                        class="cursor-pointer rounded-xl border border-gray-200 bg-white p-4 shadow-xs transition hover:border-cerulean-400 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-cerulean-600">
                        <div class="flex items-start gap-3">
                            <x-app-icon :app="$iconOf($d)" :installed="$installedApp" class="h-12 w-12" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2">
                                    <a href="{{ $this->pageUrl('deployment', ['id' => $d->id]) }}" class="truncate font-medium text-gray-900 hover:text-cerulean-600 dark:text-gray-100">{{ $d->app_name }}</a>
                                    <span class="flex shrink-0 items-center gap-1.5 text-xs text-green-700 dark:text-green-300"><span class="h-2 w-2 rounded-full bg-green-500"></span>Aktiv</span>
                                </div>
                                <div class="truncate font-mono text-sm text-gray-500 dark:text-gray-400">{{ $d->hostname }}</div>
                                <x-proxapps.domain :deployment-id="$d->id" class="mt-1 text-sm" />
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t border-gray-100 pt-2 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <span class="font-mono">{{ $d->ip ?: (($d->spec['net']['mode'] ?? '') === 'dhcp' ? 'DHCP' : '—') }}</span>
                            <span class="font-mono">CT {{ $d->vmid }}</span>
                            <span>{{ $d->node }}@if ($d->connection) · {{ $d->connection->name }}@endif</span>
                            <span>seit {{ \App\Http\Support\Format::dt($d->finished_at ?? $d->created_at) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @elseif (count($running) || ! $history->items->isEmpty())
            <div class="{{ $section }}">Aktive Apps</div>
            <p class="mx-3 mt-2 text-sm text-gray-500 dark:text-gray-400">Gerade läuft keine ausgerollte App.</p>
        @endif

        {{-- history: failed, cancelled, removed --}}
        @unless ($history->items->isEmpty())
            <div class="{{ $section }}">Verlauf <span class="font-normal normal-case tracking-normal">(fehlgeschlagen, abgebrochen, entfernt)</span></div>
            @include('modules.proxapps.partials.deployment-table', ['page' => $history])
        @endunless
    @elseif ($deployments->items->isEmpty())
        <x-emptystate :message="$status === '' ? 'Noch nichts ausgerollt.' : 'Keine Deployments mit diesem Status.'">
            @if ($status === '')
                <x-input.linkbutton :href="$this->pageUrl('apps')">Zum App-Katalog</x-input.linkbutton>
            @endif
        </x-emptystate>
    @else
        @include('modules.proxapps.partials.deployment-table', ['page' => $deployments])
    @endif
</div>
