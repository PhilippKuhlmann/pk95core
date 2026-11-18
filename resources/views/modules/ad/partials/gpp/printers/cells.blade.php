{{-- table cells of a printer row (columns: Printers::columns) --}}
@php use App\Modules\Ad\Gpp\Printers; @endphp
<td @class(['px-2 py-2 align-top sm:min-w-48 sm:px-3', 'border-l-4 border-cerulean-500' => $changed])>
    <span class="font-mono text-xs break-all">{{ $row['path'] !== '' ? $row['path'] : '–' }}</span>
    @if ($row['default'])<span class="ml-1 inline-flex items-center rounded bg-cerulean-100 px-1.5 py-0.5 text-xs font-medium text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-100">Standard</span>@endif
    @if ($changed)<span class="block text-xs text-cerulean-700 dark:text-cerulean-300">{{ $row['uid'] === '' ? 'neu' : 'geändert' }}</span>@endif
    @include('modules.ad.partials.gpp.notes')
</td>
<td class="hidden px-2 py-2 sm:px-3 align-top sm:table-cell" data-audience>@include('modules.ad.partials.gpp.audience')</td>
<td class="hidden px-2 py-2 sm:px-3 align-top text-gray-700 md:table-cell dark:text-gray-300">{{ Printers::ACTIONS[$row['action']] ?? $row['action'] }}</td>
