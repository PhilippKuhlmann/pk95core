{{-- table cells of a local group row (columns: LocalGroups::columns) --}}
@php
    $memberNames = collect($memberOptions)->pluck('label', 'value')->all() + (array) $row['names'];
    $nameOf = fn ($id) => $memberNames[$id] ?? $id;
@endphp
<td @class(['px-2 py-2 align-top sm:px-3', 'border-l-4 border-cerulean-500' => $changed])>
    <span class="font-DINPro-bold">{{ $kind->rowLabel($row) }}</span>
    @if ($changed)<span class="block text-xs text-cerulean-700 dark:text-cerulean-300">{{ $row['uid'] === '' ? 'neu' : 'geändert' }}</span>@endif
</td>
<td class="px-2 py-2 align-top text-xs sm:min-w-48 sm:px-3">
    @if ($row['add_ids'])
        <span class="block text-gray-700 dark:text-gray-300"><span class="font-medium text-green-700 dark:text-green-300" aria-label="Hinzufügen">+</span> {{ implode(', ', array_map($nameOf, $row['add_ids'])) }}</span>
    @endif
    @if ($row['remove_ids'])
        <span class="block text-gray-700 dark:text-gray-300"><span class="font-medium text-red-700 dark:text-red-300" aria-label="Entfernen">−</span> {{ implode(', ', array_map($nameOf, $row['remove_ids'])) }}</span>
    @endif
    @if (! $row['add_ids'] && ! $row['remove_ids'])<span class="text-gray-500 dark:text-gray-400">–</span>@endif
    @if ($row['remove_others'])
        <span class="mt-1 inline-flex items-center gap-1 rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-900 dark:bg-amber-900/60 dark:text-amber-100" data-remove-others>
            <x-icon name="warn" class="h-3 w-3" />Alle anderen Mitglieder werden entfernt
        </span>
    @endif
    @include('modules.ad.partials.gpp.notes')
</td>
<td class="hidden px-2 py-2 sm:px-3 align-top sm:table-cell" data-audience>@include('modules.ad.partials.gpp.audience')</td>
