{{-- One group per running app: its overview and the pages its Core module renders. --}}
<ul class="space-y-1">
    @foreach ($groups as $group)
        <li class="pt-3 mt-2 border-t border-gray-200 dark:border-gray-700" data-app-menu="{{ $group['id'] }}" wire:key="menu-{{ $group['id'] }}">
            <span class="flex items-center gap-1.5 px-2 text-[10.5px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                <x-icon :name="$group['icon']" class="h-3.5 w-3.5" />{{ $group['label'] }}
            </span>
        </li>
        @foreach ($group['items'] as $item)
            <x-aside.toplink :label="$item['label']" :icon="$item['icon']" :href="$item['url']" :aktiv="$item['active']" />
        @endforeach
    @endforeach
</ul>
