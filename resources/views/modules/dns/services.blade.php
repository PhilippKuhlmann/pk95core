@php
    use App\Modules\Dns\Fmt;
    $dirty = collect($blocked)->sort()->values()->all() !== collect($saved)->sort()->values()->all();
@endphp

<div>
    <x-page-header title="Dienste">
        <x-slot:sub>Blockiert ganze Dienste für das ganze Netz. Einzelne Clients können eigene Dienste haben.</x-slot:sub>
    </x-page-header>

    @if ($loadError)
        <x-callout title="PK95 DNS antwortet nicht" ton="gelb" class="m-3">
            {{ $loadError }}
            @unless ($loaded)
                <x-slot:aktionen>
                    <x-input.button type="button" color="gray" wire:click="retry"><x-icon name="refresh" class="h-4 w-4" />Erneut versuchen</x-input.button>
                </x-slot:aktionen>
            @endunless
        </x-callout>
    @endif

    @if ($groups)
        <form wire:submit="save">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($groups as $group => $services)
                    @php $on = count(array_intersect(array_column($services, 'id'), $blocked)); @endphp
                    <x-panel class="m-3" wire:key="grp-{{ $group }}">
                        <div class="flex items-center justify-between gap-2">
                            <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">{{ Fmt::GROUPS[$group] }}</div>
                            <div class="flex items-center gap-2 text-xs">
                                <span class="text-gray-500 dark:text-gray-400">{{ $on }} von {{ count($services) }}</span>
                                <button type="button" wire:click="group('{{ $group }}', {{ $on === count($services) ? 'false' : 'true' }})" aria-label="{{ $on === count($services) ? 'Keinen Dienst in '.Fmt::GROUPS[$group].' sperren' : 'Alle Dienste in '.Fmt::GROUPS[$group].' sperren' }}" class="text-cerulean-700 hover:underline dark:text-cerulean-300">
                                    {{ $on === count($services) ? 'Keinen' : 'Alle' }}
                                </button>
                            </div>
                        </div>
                        <div class="mt-3 grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                            @foreach ($services as $s)
                                <label class="flex items-center gap-2 py-1 text-sm text-gray-800 dark:text-gray-200" wire:key="svc-{{ $s['id'] }}">
                                    <input type="checkbox" value="{{ $s['id'] }}" wire:model.live="blocked" class="rounded border-gray-300 text-rose-600 focus:ring-rose-500 dark:border-gray-600 dark:bg-gray-700">
                                    {{ $s['name'] }}
                                </label>
                            @endforeach
                        </div>
                    </x-panel>
                @endforeach
            </div>
            <x-input.fehler feld="blocked" class="mx-3" />
            <div class="sticky bottom-0 m-3 flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-white/95 p-3 shadow-xs dark:border-gray-700 dark:bg-gray-800/95">
                <x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button>
                <span class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $blocked ? Fmt::plural(count($blocked), 'Dienst', 'Dienste').' ausgewählt' : 'Kein Dienst ausgewählt' }}{{ $dirty ? ' · nicht gespeichert' : '' }}
                </span>
            </div>
        </form>
    @endif
</div>
