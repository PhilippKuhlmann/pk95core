@php
    use App\Modules\Proxy\ErrorPages;
    $htmlHint = 'Schreibe '.'{'.'{code}} für den Fehlercode und '.'{'.'{text}} für den Text. Bis zu 64 KB. Besucher sehen die Seite ohne Einschränkung, Skripte laufen also.';
@endphp

<div>
    <x-page-header title="Fehlerseiten" />

    @if ($loadError)
        <x-callout title="PK95 Proxy antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
    @endif

    <x-panel class="m-3">
        <p class="max-w-3xl text-sm text-gray-500 dark:text-gray-400">Das sehen Besucher im Browser, wenn Caddy selbst einen Fehler meldet, etwa bei einem ausgefallenen Dienst. Fehlerseiten, die eine Anwendung selbst sendet, bleiben unverändert. Apps und Schnittstellen bekommen weiterhin nur den Status.</p>
        <form wire:submit="save" class="mt-3">
            <x-input.checkbox label="Eigene Fehlerseiten anzeigen" wire:model.live="enabled" />

            <div @class(['mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2', 'hidden' => ! $enabled])>
                @foreach (ErrorPages::GROUPS as $key => [$label, $hint])
                    <fieldset class="rounded-lg border border-gray-200 p-4 dark:border-gray-700" wire:key="ep-{{ $key }}">
                        <legend class="px-1 text-sm font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $label }}</legend>
                        <x-input.hilfe>{{ $hint }}</x-input.hilfe>
                        <x-field label="Titel" for="ep-{{ $key }}-t" class="mt-3">
                            <x-input.text id="ep-{{ $key }}-t" maxlength="120" wire:model="pages.{{ $key }}.title" class="mt-1 w-full" />
                        </x-field>
                        <x-field label="Text" for="ep-{{ $key }}-x" class="mt-3">
                            <x-input.textarea id="ep-{{ $key }}-x" rows="2" maxlength="1000" wire:model="pages.{{ $key }}.text" class="mt-1 font-sans text-sm" />
                        </x-field>
                        <details class="mt-3" @if ($pages[$key]['html'] ?? '') open @endif>
                            <summary class="cursor-pointer text-sm text-cerulean-700 dark:text-cerulean-300">Eigene HTML-Seite</summary>
                            <x-field label="HTML, ersetzt Titel und Text" for="ep-{{ $key }}-h" class="mt-2" :hilfe="$htmlHint">
                                <x-input.textarea id="ep-{{ $key }}-h" rows="5" wire:model="pages.{{ $key }}.html" placeholder="<!doctype html> …" class="mt-1" />
                            </x-field>
                        </details>
                        <div class="mt-3 flex gap-2">
                            <x-input.button type="button" size="sm" color="gray" wire:click="preview('{{ $key }}')">Vorschau</x-input.button>
                            <x-input.button type="button" size="sm" color="gray" wire:click="resetGroup('{{ $key }}')">Zurücksetzen</x-input.button>
                        </div>
                    </fieldset>
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-input.button type="submit" wire:loading.attr="disabled">Speichern</x-input.button>
                <span class="text-xs text-gray-500">Die Vorschau zeigt den zuletzt gespeicherten Stand.</span>
            </div>
        </form>
    </x-panel>

    @if ($previewHtml !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.closePreview()">
            <div class="absolute inset-0 bg-gray-900/60" wire:click="closePreview" aria-hidden="true"></div>
            <div role="dialog" aria-modal="true" aria-label="Vorschau der Fehlerseite" class="relative flex h-[80vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-800">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-2 dark:border-gray-700">
                    <span class="font-DINPro-bold text-gray-900 dark:text-gray-100">Vorschau</span>
                    <x-input.button type="button" size="sm" color="gray" wire:click="closePreview">Schließen</x-input.button>
                </div>
                {{-- sandbox without allow-scripts: own HTML may contain scripts, they must not run inside Core --}}
                <iframe sandbox srcdoc="{{ $previewHtml }}" title="Vorschau" class="w-full flex-1 bg-white"></iframe>
            </div>
        </div>
    @endif
</div>
