{{-- table cells of a drive map row (columns: DriveMaps::columns) --}}
@php use App\Modules\Ad\Gpp\DriveMaps; @endphp
<td @class(['whitespace-nowrap px-2 py-2 sm:px-3 align-top', 'border-l-4 border-cerulean-500' => $changed])>
    <span class="font-mono font-DINPro-bold">{{ $row['letter'] }}:</span>
    @if ($row['first_available'])<span class="block text-xs text-gray-500 dark:text-gray-400">erster freier ab {{ $row['letter'] }}</span>@endif
    @if ($changed)<span class="block text-xs text-cerulean-700 dark:text-cerulean-300">{{ $row['uid'] === '' ? 'neu' : 'geändert' }}</span>@endif
</td>
<td class="px-2 py-2 align-top sm:min-w-40 sm:px-3">
    <span class="font-mono text-xs break-all">{{ $row['path'] !== '' ? $row['path'] : '–' }}</span>
    @if ($row['label'] !== '')<span class="block text-xs text-gray-500 lg:hidden dark:text-gray-400">{{ $row['label'] }}</span>@endif
    @include('modules.ad.partials.gpp.notes')
</td>
<td class="hidden px-2 py-2 sm:px-3 align-top text-gray-700 lg:table-cell dark:text-gray-300">{{ $row['label'] !== '' ? $row['label'] : '–' }}</td>
<td class="hidden px-2 py-2 sm:px-3 align-top sm:table-cell" data-audience>@include('modules.ad.partials.gpp.audience')</td>
<td class="hidden px-2 py-2 sm:px-3 align-top text-gray-700 md:table-cell dark:text-gray-300">
    {{ DriveMaps::ACTIONS[$row['action']] ?? $row['action'] }}
    @if ($row['visibility'] !== 'unchanged')<span class="block text-xs text-gray-500 dark:text-gray-400">{{ DriveMaps::VISIBILITY[$row['visibility']] ?? $row['visibility'] }}</span>@endif
</td>
