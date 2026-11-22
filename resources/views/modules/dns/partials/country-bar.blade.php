{{-- Stacked bar of a country (CountrySplit::rows): green let through, red blocked; $max = the
     biggest total of the list. Without the split (PK95 DNS before 1.3.0) only the green part. --}}
@php $w = \App\Modules\Dns\CountrySplit::bar($row, $max); @endphp
<div class="mt-1 flex h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="img"
    aria-label="{{ \App\Modules\Dns\Fmt::num($row['passed']) }} durchgelassen{{ ($split ?? true) ? ', '.\App\Modules\Dns\Fmt::num($row['blocked']).' geblockt' : '' }}" data-country-bar>
    <div class="h-1.5 bg-green-500" style="width: {{ $w['passed'] }}%" data-passed></div>
    @if (($split ?? true) && $row['blocked'] > 0)
        <div class="h-1.5 bg-rose-500" style="width: {{ $w['blocked'] }}%" data-blocked></div>
    @endif
</div>
