{{-- "Gilt für" of a preferences row: as a table cell, or (small) as a line in the main cell on a phone --}}
@php $small = $small ?? false; @endphp
<span @class([
    $small ? 'mt-0.5 flex items-center gap-1 text-xs sm:hidden' : 'inline-flex items-center gap-1 text-sm',
    'text-gray-600 dark:text-gray-300' => $small && $row['condition'] === 'all',
    'text-gray-700 dark:text-gray-300' => ! $small && $row['condition'] === 'all',
    'font-medium text-cerulean-800 dark:text-cerulean-200' => $row['condition'] === 'member',
    'font-medium text-amber-800 dark:text-amber-200' => $row['condition'] === 'not_member'])>
    @if ($row['condition'] !== 'all')<x-icon name="shield" :class="$small ? 'h-3 w-3 shrink-0' : 'h-3.5 w-3.5 shrink-0'" />@endif{{ $audience }}
</span>
