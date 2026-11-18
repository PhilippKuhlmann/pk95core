{{-- table cells of a local group row (columns: LocalGroups::columns); on a phone the members stand under the group --}}
<td @class(['px-2 py-2 align-top sm:px-3', 'border-l-4 border-cerulean-500' => $changed])>
    <span class="font-DINPro-bold break-words">{{ $kind->rowLabel($row) }}</span>
    @if ($changed)<span class="block text-xs text-cerulean-700 dark:text-cerulean-300">{{ $row['uid'] === '' ? 'neu' : 'geändert' }}</span>@endif
    <span class="mt-1 block text-xs sm:hidden">@include('modules.ad.partials.gpp.local_groups.members')</span>
    @include('modules.ad.partials.gpp.notes')
</td>
<td class="hidden px-2 py-2 align-top text-xs sm:table-cell sm:min-w-48 sm:px-3">@include('modules.ad.partials.gpp.local_groups.members')</td>
<td class="hidden px-2 py-2 sm:px-3 align-top sm:table-cell" data-audience>@include('modules.ad.partials.gpp.audience')</td>
