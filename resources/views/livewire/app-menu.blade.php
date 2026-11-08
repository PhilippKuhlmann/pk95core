{{-- One collapsible group per running app. The group of the current page is always open;
     other groups remember open/closed in the browser (localStorage). --}}
<ul class="space-y-0.5">
    @foreach ($groups as $group)
        @php
            $hasActive = collect($group['items'])->contains(fn ($item) => $item['active']);
        @endphp
        <li data-app-menu="{{ $group['id'] }}" wire:key="menu-{{ $group['id'] }}"
            x-data="{
                key: 'pk95-menu-{{ $group['id'] }}',
                open: {{ $hasActive ? 'true' : 'false' }},
                init() {
                    if (! this.open) { try { this.open = localStorage.getItem(this.key) === '1'; } catch (e) {} }
                },
                toggle() {
                    this.open = ! this.open;
                    try { localStorage.setItem(this.key, this.open ? '1' : '0'); } catch (e) {}
                },
            }">
            <button type="button" x-on:click="toggle()" x-bind:aria-expanded="open" aria-controls="app-menu-{{ $group['id'] }}"
                @class([
                    'group flex w-full items-center gap-3 rounded-lg p-2 text-sm font-medium transition-colors',
                    'text-cerulean-700 dark:text-cerulean-400' => $hasActive,
                    'text-gray-700 hover:bg-gray-100 hover:text-cerulean-700 dark:text-gray-200 dark:hover:bg-gray-800' => ! $hasActive,
                ])>
                <x-icon :name="$group['icon']" @class(['h-5 w-5 shrink-0', 'text-cerulean-600 dark:text-cerulean-400' => $hasActive, 'text-gray-400 group-hover:text-cerulean-600 dark:text-gray-500' => ! $hasActive]) />
                <span class="flex-1 truncate text-left">{{ $group['label'] }}</span>
                <x-icon name="chevron-down" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" x-bind:class="open ? 'rotate-180' : ''" />
            </button>
            <ul id="app-menu-{{ $group['id'] }}" x-show="open" @unless ($hasActive) x-cloak @endunless
                class="mb-1 ml-4 space-y-0.5 border-l border-gray-200 pl-2 dark:border-gray-700">
                @foreach ($group['items'] as $item)
                    <x-aside.toplink :label="$item['label']" :icon="$item['icon']" :href="$item['url']" :aktiv="$item['active']" klein />
                @endforeach
            </ul>
        </li>
    @endforeach
</ul>
