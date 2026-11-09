{{-- Deployment table (history or filtered list). $page: paginator from ProxApps (items, current_page, last_page, total). --}}
<div class="m-3">
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-xs dark:border-gray-700 dark:bg-gray-800">
        <table class="w-full min-w-max text-left text-sm text-gray-500 dark:text-gray-400 sm:min-w-0">
            <thead class="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300">
                <tr>
                    <th scope="col" class="px-4 py-2.5 font-semibold">Status</th>
                    <th scope="col" class="px-4 py-2.5 font-semibold">App</th>
                    <th scope="col" class="px-4 py-2.5 font-semibold">Hostname</th>
                    <th scope="col" class="px-4 py-2.5 font-semibold">VMID</th>
                    <th scope="col" class="px-4 py-2.5 font-semibold">IP</th>
                    <th scope="col" class="px-4 py-2.5 font-semibold">Node</th>
                    <th scope="col" class="px-4 py-2.5 font-semibold">Verbindung</th>
                    <th scope="col" class="px-4 py-2.5 font-semibold">Erstellt</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($page->items as $d)
                    <tr wire:key="dep-{{ $d->id }}" onclick="if (!event.target.closest('a')) window.location='{{ $this->pageUrl('deployment', ['id' => $d->id]) }}'"
                        @class([
                            'cursor-pointer border-b border-gray-100 bg-white transition-colors last:border-0 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:hover:bg-gray-700/50',
                            'opacity-60' => $d->status === 'removed',
                        ])>
                        <td class="px-4 py-2.5"><x-status-pill :status="$d->status" /></td>
                        <td class="px-4 py-2.5 text-gray-900 dark:text-gray-100">{{ $d->app_name }}</td>
                        <td class="px-4 py-2.5">
                            <a href="{{ $this->pageUrl('deployment', ['id' => $d->id]) }}" class="text-cerulean-500 hover:text-cerulean-600">{{ $d->hostname }}</a>
                        </td>
                        <td class="px-4 py-2.5 font-mono text-sm text-gray-900 dark:text-gray-100">{{ $d->vmid }}</td>
                        <td class="px-4 py-2.5 font-mono text-sm text-gray-900 dark:text-gray-100">
                            @if ($d->ip)
                                {{ $d->ip }}
                            @else
                                <span class="text-gray-500 dark:text-gray-400">{{ ($d->spec['net']['mode'] ?? '') === 'dhcp' ? 'DHCP' : '—' }}</span>
                            @endif
                            <x-proxapps.domain :deployment-id="$d->id" class="flex text-xs" />
                        </td>
                        <td class="px-4 py-2.5 text-gray-900 dark:text-gray-100">{{ $d->node }}</td>
                        <td class="px-4 py-2.5">
                            @if ($d->connection)
                                {{ $d->connection->name }}
                            @else
                                <span class="text-gray-500 dark:text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 font-mono text-sm text-gray-900 dark:text-gray-100">{{ \App\Http\Support\Format::dt($d->created_at) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@if ($page->last_page > 1)
    <div class="flex items-center justify-between px-3 pb-3 text-sm text-gray-600 dark:text-gray-300">
        <span>Seite {{ $page->current_page }} von {{ $page->last_page }} · {{ $page->total }} Deployments</span>
        <span class="flex gap-2">
            <x-input.button type="button" color="gray" size="sm" wire:click="goToPage({{ $page->current_page - 1 }})" :disabled="$page->current_page <= 1">Zurück</x-input.button>
            <x-input.button type="button" color="gray" size="sm" wire:click="goToPage({{ $page->current_page + 1 }})" :disabled="$page->current_page >= $page->last_page">Weiter</x-input.button>
        </span>
    </div>
@endif
