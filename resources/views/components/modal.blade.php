{{--
    Form dialog over the page, opened and closed by the Livewire page (render it inside
    @if ($editing !== null)); the Alpine part "modal" (app.js) handles focus, Esc, backdrop,
    the question about unsaved changes and the page scroll.
      <x-modal title="Host bearbeiten" submit="save" wire:key="host-form"> fields </x-modal>
    submit: Livewire method of the form, "" = a dialog to look at (no form, only "Schließen");
    close: method that closes (default "cancel").
    Head with title and X, body scrolls, foot with "Abbrechen" and the save button stays.
    Below sm a full screen sheet. Layers: page bar 50 < dialog 55 < confirm and toasts 60 <
    tooltip 70. No transform on the dialog: comboboxes (position: fixed) and tooltips inside
    must not be placed or clipped relative to it.
--}}
@props([
    'title' => '',
    'submit' => 'save',
    'close' => 'cancel',
    'saveLabel' => 'Speichern',
    'size' => 'xl',
])

@php
    $id = 'modal-'.substr(md5((string) ($attributes->get('wire:key') ?? $submit)), 0, 8);
    $width = ['md' => 'sm:max-w-lg', 'lg' => 'sm:max-w-2xl', 'xl' => 'sm:max-w-3xl', '2xl' => 'sm:max-w-4xl'][$size] ?? 'sm:max-w-3xl';
@endphp

<div {{ $attributes->merge(['class' => 'fixed inset-0 z-[55] flex items-stretch justify-center sm:items-center sm:p-4']) }}
    x-data="modal(@js(['close' => $close]))" x-on:keydown.escape.prevent.stop="requestClose()" x-on:keydown.tab="trap($event)" data-modal>
    <div class="absolute inset-0 bg-gray-900/60 transition-opacity duration-150 dark:bg-black/70" :class="shown ? 'opacity-100' : 'opacity-0'"
        x-on:click="requestClose()" aria-hidden="true"></div>

    <div x-ref="panel" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title"
        class="relative flex h-dvh w-full flex-col bg-white shadow-xl transition-opacity duration-150 sm:h-auto sm:max-h-[calc(100dvh-2rem)] sm:rounded-xl sm:border sm:border-gray-200 dark:bg-gray-800 sm:dark:border-gray-700 {{ $width }}"
        :class="shown ? 'opacity-100' : 'opacity-0'">
        <div class="flex shrink-0 items-start justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-700">
            <h2 id="{{ $id }}-title" class="min-w-0 text-lg font-CoconPro text-gray-900 dark:text-gray-100">{{ $title }}</h2>
            <button type="button" x-on:click="requestClose()" aria-label="Schließen" data-tip="Schließen"
                class="-m-1.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-cerulean-500 max-sm:h-11 max-sm:w-11 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
                <x-icon name="x" class="h-5 w-5" sw="2" />
            </button>
        </div>

        {{-- with submit a form (changes are tracked), without one a dialog to look at: only "Schließen" --}}
        @if ($submit)
            <form wire:submit="{{ $submit }}" class="flex min-h-0 flex-1 flex-col">
        @else
            <div class="flex min-h-0 flex-1 flex-col">
        @endif
            <div x-ref="body" class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-4 [&>*:first-child]:mt-0" @if ($submit) x-on:input="dirty = true" x-on:change="dirty = true" @endif>
                {{ $slot }}
            </div>
            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-gray-200 px-5 py-3 max-sm:pb-[max(0.75rem,env(safe-area-inset-bottom))] dark:border-gray-700">
                @isset($actions)
                    {{-- below sm a row of its own above the buttons --}}
                    <div class="mr-auto flex flex-wrap items-center gap-2 max-sm:w-full max-sm:justify-between">{{ $actions }}</div>
                @endisset
                @if ($submit)
                    <x-input.button type="button" color="gray" x-on:click="requestClose()" class="max-sm:min-h-11">Abbrechen</x-input.button>
                    <x-input.button type="submit" wire:loading.attr="disabled" wire:target="{{ $submit }}" class="max-sm:min-h-11">{{ $saveLabel }}</x-input.button>
                @else
                    <x-input.button type="button" color="gray" x-on:click="requestClose()" class="max-sm:min-h-11">Schließen</x-input.button>
                @endif
            </div>
        @if ($submit)
            </form>
        @else
            </div>
        @endif
    </div>
</div>
