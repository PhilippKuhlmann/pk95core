{{--
    Searchable dropdown (ARIA combobox + listbox), Alpine component "combobox" in app.js.
      <x-input.combobox wire:model.live="host" label="Host" :options="$domains" placeholder="Alle Hosts" reset="Alle Hosts" />
    options: [value => label] or [['value' => …, 'label' => …, 'hint' => '4.201', 'group' => …], …]
    multiple: the bound property is a list; search: null = only with more than 6 options.
    The trigger and the menu are wire:ignore (Alpine draws them from data-options), so a
    Livewire re-render (polling) neither closes an open menu nor loses the search text.
--}}
@props([
    'options' => [],
    'label',
    'placeholder' => 'Auswählen …',
    'reset' => null,
    'multiple' => false,
    'search' => null,
    'many' => 'ausgewählt',
])

@php
    $items = [];
    foreach ($options as $key => $o) {
        $o = is_array($o) ? $o : ['value' => $key, 'label' => $o];
        $items[] = array_filter([
            'value' => (string) $o['value'],
            'label' => (string) ($o['label'] ?? $o['value']),
            'hint' => isset($o['hint']) && $o['hint'] !== '' ? (string) $o['hint'] : null,
            'group' => isset($o['group']) && $o['group'] !== '' ? (string) $o['group'] : null,
        ], fn ($v) => $v !== null);
    }
    $search ??= count($items) > 6;
    // stable across re-renders (Livewire morphs the same element)
    $id = 'cb-'.substr(md5($label.'|'.($attributes->wire('model')->value() ?? '')), 0, 8);
    $cfg = ['id' => $id, 'multiple' => (bool) $multiple, 'search' => (bool) $search, 'placeholder' => $placeholder, 'many' => $many];
@endphp

<div {{ $attributes->whereStartsWith('wire:model') }} {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'relative']) }}
    x-data="combobox(@js($cfg))" x-modelable="value" data-options="{{ json_encode($items, JSON_UNESCAPED_UNICODE) }}" data-combobox
    x-on:click.outside="close(false)" x-on:resize.window="place()" x-on:scroll.window.capture="place()">
    <div wire:ignore>
        <button type="button" x-ref="trigger" role="combobox" aria-haspopup="listbox" aria-controls="{{ $id }}-list" aria-label="{{ $label }}"
            :aria-expanded="open.toString()" x-on:click="toggle()" x-on:keydown="key($event)"
            class="flex w-full min-w-0 items-center justify-between gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-left text-sm shadow-xs focus:border-cerulean-500 focus:outline-hidden focus:ring-2 focus:ring-cerulean-500/40 dark:border-gray-700 dark:bg-gray-800">
            <span class="truncate" :class="empty ? 'text-gray-500 dark:text-gray-400' : 'text-gray-900 dark:text-gray-100'" x-text="display">{{ $placeholder }}</span>
            <x-icon name="chevron-down" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" ::class="open && 'rotate-180'" sw="2" />
        </button>

        <div x-ref="menu" x-show="open" x-cloak :style="style" x-transition.opacity.duration.100ms
            class="fixed z-50 flex flex-col overflow-hidden rounded-lg border border-gray-200 bg-white text-sm shadow-xl dark:border-gray-700 dark:bg-gray-800">
            @if ($search)
                <div class="border-b border-gray-100 p-2 dark:border-gray-700">
                    <input x-ref="search" type="text" x-model="query" x-on:input="active = 0; reveal()" x-on:keydown="key($event)" autocomplete="off"
                        role="combobox" aria-autocomplete="list" aria-expanded="true" aria-controls="{{ $id }}-list" :aria-activedescendant="items.length ? optId(active) : null"
                        aria-label="{{ $label }} durchsuchen" placeholder="Suchen …"
                        class="w-full rounded-md border border-gray-300 bg-white px-2.5 py-2 text-sm text-gray-900 focus:border-cerulean-500 focus:outline-hidden focus:ring-2 focus:ring-cerulean-500/40 max-sm:py-2.5 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
            @endif
            @if ($reset !== null)
                <button type="button" x-on:click="clear()" tabindex="-1"
                    class="border-b border-gray-100 px-3 py-2 text-left text-cerulean-700 hover:bg-gray-50 max-sm:py-3 dark:border-gray-700 dark:text-cerulean-300 dark:hover:bg-gray-700">{{ $reset }}</button>
            @endif
            <ul id="{{ $id }}-list" role="listbox" aria-label="{{ $label }}" @if ($multiple) aria-multiselectable="true" @endif tabindex="-1" class="min-h-0 flex-1 overflow-y-auto py-1">
                <template x-for="(o, i) in items" :key="o.value">
                    <li role="presentation">
                        <div x-show="o.head" x-text="o.head" aria-hidden="true" class="px-3 pt-2 pb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"></div>
                        <div role="option" :id="optId(i)" :aria-selected="isSelected(o.value).toString()" x-on:click="choose(o)" x-on:mousemove="active = i"
                            class="flex cursor-pointer items-center gap-2 px-3 py-2 max-sm:py-3"
                            :class="active === i ? 'bg-cerulean-50 text-cerulean-900 dark:bg-gray-700 dark:text-white' : 'text-gray-800 dark:text-gray-100'">
                            @if ($multiple)
                                <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded border"
                                    :class="isSelected(o.value) ? 'border-cerulean-600 bg-cerulean-600 text-white' : 'border-gray-300 dark:border-gray-500'">
                                    <x-icon name="check" class="h-3 w-3" sw="3" x-show="isSelected(o.value)" />
                                </span>
                            @else
                                <x-icon name="check" class="h-4 w-4 shrink-0 text-cerulean-600 dark:text-cerulean-400" sw="2.5" ::class="! isSelected(o.value) && 'invisible'" />
                            @endif
                            <span class="min-w-0 flex-1 truncate"><template x-for="p in parts(o.label)"><span :class="p.m && 'rounded-sm bg-amber-100 font-semibold text-gray-900 dark:bg-amber-500/30 dark:text-white'" x-text="p.t"></span></template></span>
                            <span x-show="o.hint" x-text="o.hint" class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400"></span>
                        </div>
                    </li>
                </template>
                <li x-show="items.length === 0" role="presentation" class="px-3 py-3 text-gray-500 dark:text-gray-400">Keine Treffer</li>
            </ul>
        </div>
    </div>
</div>
