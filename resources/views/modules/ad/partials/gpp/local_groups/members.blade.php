{{-- members of a local group row: + added, − removed, "remove others" warning --}}
@php
    $memberNames = collect($memberOptions)->pluck('label', 'value')->all() + (array) $row['names'];
    $nameOf = fn ($id) => $memberNames[$id] ?? $id;
@endphp
@if ($row['add_ids'])
    <span class="block text-gray-700 dark:text-gray-300"><span class="font-medium text-green-700 dark:text-green-300" aria-label="Hinzufügen">+</span> {{ implode(', ', array_map($nameOf, $row['add_ids'])) }}</span>
@endif
@if ($row['remove_ids'])
    <span class="block text-gray-700 dark:text-gray-300"><span class="font-medium text-red-700 dark:text-red-300" aria-label="Entfernen">−</span> {{ implode(', ', array_map($nameOf, $row['remove_ids'])) }}</span>
@endif
@if (! $row['add_ids'] && ! $row['remove_ids'])<span class="text-gray-500 dark:text-gray-400">–</span>@endif
@if ($removes = \App\Modules\Ad\Gpp\LocalGroups::removesOthers($row))
    <span class="mt-1 inline-flex items-center gap-1 rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-900 dark:bg-amber-900/60 dark:text-amber-100" data-remove-others>
        <x-icon name="warn" class="h-3 w-3 shrink-0" />Alle anderen {{ $removes }} werden entfernt
    </span>
@endif
