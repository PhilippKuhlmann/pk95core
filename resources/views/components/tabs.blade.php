{{--
    Tabs of a page, in the look of the GPO dialog: a grey bar, the active tab white. Below sm the
    bar scrolls sideways itself (not the page).
      <x-tabs :tabs="['a' => 'Erster', 'b' => 'Zweiter']" :active="$tab" action="show" />
    action: Livewire method called with the key of the tab.
--}}
@props(['tabs' => [], 'active' => '', 'action' => 'show', 'label' => 'Bereiche'])

<div {{ $attributes->merge(['class' => 'max-w-full overflow-x-auto']) }}>
    <div role="tablist" aria-label="{{ $label }}" class="inline-flex min-w-full gap-1 rounded-lg bg-gray-100 p-1 sm:min-w-0 dark:bg-gray-900/60">
        @foreach ($tabs as $key => $tabLabel)
            <button type="button" role="tab" aria-selected="{{ $active === $key ? 'true' : 'false' }}" data-tab="{{ $key }}"
                wire:click="{{ $action }}('{{ $key }}')"
                @class(['flex min-h-9 flex-1 items-center justify-center whitespace-nowrap rounded-md px-3 py-1.5 text-sm transition-colors focus:outline-hidden focus-visible:ring-2 focus-visible:ring-cerulean-500 max-sm:min-h-11 sm:flex-none sm:px-4',
                    'bg-white font-DINPro-bold text-gray-900 shadow-xs dark:bg-gray-700 dark:text-white' => $active === $key,
                    'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white' => $active !== $key])>
                {{ $tabLabel }}
            </button>
        @endforeach
    </div>
</div>
