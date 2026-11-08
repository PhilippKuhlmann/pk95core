@php
    $tone = [
        'info' => ['Info', 'bg-cerulean-100 text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-200'],
        'warning' => ['Warnung', 'bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100'],
        'critical' => ['Kritisch', 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-100'],
    ];
@endphp
<div wire:poll.30s>
    <x-page-header title="Ereignisse">
        <x-slot:sub>Was die Apps und Core melden. Benachrichtigungen dafür stellst du unter „Benachrichtigungen“ ein.</x-slot:sub>
        <x-input.select wire:model.live="app" class="w-48 text-sm" aria-label="App">
            <option value="">Alle Apps</option>
            @foreach ($apps as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </x-input.select>
        <x-input.select wire:model.live="severity" class="w-44 text-sm" aria-label="Schweregrad">
            <option value="">Alle Stufen</option>
            <option value="warning">ab Warnung</option>
            <option value="critical">nur Kritisch</option>
        </x-input.select>
    </x-page-header>

    @if ($events->isEmpty())
        <x-emptystate message="Keine Ereignisse." />
    @else
        <x-panel polster="keins" class="m-3 divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($events as $event)
                @php([$label, $classes] = $tone[$event->severity] ?? $tone['info'])
                <div class="flex flex-wrap items-start gap-3 px-4 py-3" wire:key="ev-{{ $event->id }}">
                    <span class="w-28 shrink-0 font-mono text-xs text-gray-500 dark:text-gray-400" title="{{ $event->occurred_at->format('d.m.Y H:i:s') }}">{{ $event->occurred_at->format('d.m. H:i:s') }}</span>
                    <span class="inline-flex shrink-0 rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $classes }}">{{ $label }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm text-gray-900 dark:text-gray-100">
                            <span class="font-DINPro-bold">{{ $apps[$event->app_id] ?? $event->app_id }}:</span> {{ $event->title }}
                        </div>
                        @if ($event->body)
                            <div class="mt-0.5 whitespace-pre-line text-sm text-gray-500 dark:text-gray-400">{{ $event->body }}</div>
                        @endif
                    </div>
                    <span class="shrink-0 font-mono text-xs text-gray-400 dark:text-gray-500">{{ $event->type }}</span>
                </div>
            @endforeach
        </x-panel>
        @if ($hasMore)
            <div class="m-3 text-center">
                <x-input.button type="button" color="gray" wire:click="more">Ältere laden</x-input.button>
            </div>
        @endif
    @endif
</div>
