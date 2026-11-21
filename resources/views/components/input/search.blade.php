{{--
    Search field of a list: magnifier, type="search", Esc empties it (and tells Livewire).
    Bind it like an input: <x-input.search wire:model.live.debounce.300ms="q" placeholder="Hosts durchsuchen …" />
    The width comes from "class" (default: full width on phones, 18rem from sm on).
--}}
@props(['label' => null])

@php
    $label ??= $attributes->get('placeholder', 'Suche');
    $width = $attributes->get('class', 'w-full sm:w-72');
@endphp

<label class="relative block {{ $width }}" data-search>
    <span class="sr-only">{{ $label }}</span>
    <x-icon name="search" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" sw="2" />
    <input type="search" autocomplete="off" spellcheck="false"
        x-on:keydown.escape.prevent="if ($el.value !== '') { $el.value = ''; $el.dispatchEvent(new Event('input', { bubbles: true })) } else { $el.blur() }"
        {{ $attributes->except('class')->merge(['class' => 'w-full rounded-lg border border-gray-300 bg-white py-2 pl-8 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-cerulean-500 focus:ring-cerulean-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100']) }}>
</label>
