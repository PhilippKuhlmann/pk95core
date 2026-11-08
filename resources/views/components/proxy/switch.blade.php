@props(['on' => false, 'label'])

{{-- On/off switch of the PK95 Proxy lists (wire:click on the element toggles on the server). --}}
<button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-label="{{ $label }}" wire:loading.attr="disabled"
    {{ $attributes->class(['relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors focus:outline-hidden focus:ring-2 focus:ring-cerulean-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800',
        'bg-cerulean-600' => $on, 'bg-gray-300 dark:bg-gray-600' => ! $on]) }}>
    <span @class(['inline-block h-5 w-5 translate-y-0.5 rounded-full bg-white shadow transition-transform',
        'translate-x-5.5' => $on, 'translate-x-0.5' => ! $on])></span>
</button>
