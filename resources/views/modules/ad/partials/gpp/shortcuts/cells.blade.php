{{-- table cells of a shortcut row (columns: Shortcuts::columns) --}}
@php use App\Modules\Ad\Gpp\Shortcuts; @endphp
<td @class(['px-2 py-2 align-top sm:px-3', 'border-l-4 border-cerulean-500' => $changed])>
    <span class="font-DINPro-bold">{{ $row['name'] !== '' ? $row['name'] : '–' }}</span>
    @if ($changed)<span class="block text-xs text-cerulean-700 dark:text-cerulean-300">{{ $row['uid'] === '' ? 'neu' : 'geändert' }}</span>@endif
    <span class="block font-mono text-xs break-all text-gray-500 md:hidden dark:text-gray-400">{{ $row['target'] }}</span>
    <span class="block text-xs text-gray-500 lg:hidden dark:text-gray-400">{{ Shortcuts::LOCATIONS[$row['location']] ?? $row['location'] }}</span>
    @include('modules.ad.partials.gpp.notes')
</td>
<td class="hidden px-2 py-2 sm:px-3 align-top md:table-cell">
    <span class="font-mono text-xs break-all">{{ $row['target'] !== '' ? $row['target'] : '–' }}</span>
    @if ($row['target_type'] === 'file' && $row['arguments'] !== '')<span class="block font-mono text-xs text-gray-500 dark:text-gray-400">{{ $row['arguments'] }}</span>@endif
</td>
<td class="hidden px-2 py-2 sm:px-3 align-top text-gray-700 lg:table-cell dark:text-gray-300">{{ Shortcuts::LOCATIONS[$row['location']] ?? $row['location'] }}</td>
<td class="hidden px-2 py-2 sm:px-3 align-top sm:table-cell" data-audience>@include('modules.ad.partials.gpp.audience')</td>
<td class="hidden px-2 py-2 sm:px-3 align-top text-gray-700 md:table-cell dark:text-gray-300">{{ Shortcuts::ACTIONS[$row['action']] ?? $row['action'] }}</td>
