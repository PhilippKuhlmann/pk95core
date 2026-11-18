{{-- the collapsible section of a preferences list ($kind) in its tab; open with rows, changes, errors or an open editor --}}
@php
    $sectionKey = $kind->key();
    $sectionSupported = ! empty($gppSupported[$sectionKey]);
    $sectionCount = $sectionSupported ? count($gpp[$sectionKey] ?? []) : 0;
    $sectionErrors = $errors->has('gpp.'.$sectionKey) || $errors->has('gpp.'.$sectionKey.'.*');
    $sectionOpen = $sectionCount > 0 || $sectionErrors || $this->gppChanges($sectionKey)[1] > 0 || $this->gppEditingIndex($sectionKey) !== null;
@endphp
<section x-data="{ open: @js($sectionOpen) }" wire:key="sec-gpp-{{ $sectionKey }}-{{ $filterKey }}{{ $sectionErrors ? '-e' : '' }}"
    class="mb-3 overflow-hidden rounded-lg border border-cerulean-200 dark:border-cerulean-800" data-gpo-group="{{ $kind->title() }}" data-gpp-section="{{ $sectionKey }}">
    <h3>
        <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()"
            class="flex w-full items-center gap-3 bg-cerulean-50/60 px-4 py-3 text-left hover:bg-cerulean-50 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cerulean-500 dark:bg-cerulean-900/15 dark:hover:bg-cerulean-900/25">
            <span class="min-w-0 flex-1 font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $kind->title() }}</span>
            <span @class(['text-xs', 'text-cerulean-700 dark:text-cerulean-300' => $sectionCount > 0, 'text-gray-500 dark:text-gray-400' => $sectionCount === 0]) data-group-count>
                {{ $sectionSupported ? $kind->count($sectionCount) : 'ab PK95 AD '.$kind->since() }}
            </span>
            <x-icon name="chevron-down" class="h-4 w-4 shrink-0 text-gray-500 transition-transform dark:text-gray-400" ::class="open && 'rotate-180'" />
        </button>
    </h3>
    <div x-show="open" x-cloak class="border-t border-cerulean-200 p-4 dark:border-cerulean-800">
        @include('modules.ad.partials.gpp.list')
    </div>
</section>
