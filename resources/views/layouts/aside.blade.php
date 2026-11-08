{{-- Mobile drawer backdrop (below lg) --}}
<div x-show="mobilOffen" x-cloak x-on:click="mobilUmschalten(false)" x-transition.opacity
    class="bg-gray-900/50 dark:bg-gray-900/80 fixed inset-0 z-30 lg:hidden" aria-hidden="true"></div>

<aside id="logo-sidebar"
    class="fixed top-0 left-0 z-40 w-64 h-screen pt-20 transition-transform -translate-x-full text-gray-900 bg-white border-r border-gray-200 lg:translate-x-0 dark:bg-gray-900 dark:border-gray-700"
    x-bind:class="{ 'lg:-translate-x-full!': menuEin, 'translate-x-0!': mobilOffen }" aria-label="Seitenleiste">
    <div class="flex justify-between flex-col h-full px-3 pb-4 overflow-y-auto bg-white dark:bg-gray-900">
        <div class="space-y-1">
            <ul class="space-y-1">
                <x-aside.toplink label="Dashboard" icon="home" :href="route('dashboard')" :aktiv="request()->routeIs('dashboard')" />
            </ul>

            {{-- grows and shrinks with the running apps (Livewire event "apps-changed") --}}
            <livewire:app-menu />

            <ul class="space-y-1">
                <li class="pt-3 mt-2 border-t border-gray-200 dark:border-gray-700">
                    <span class="px-2 text-[10.5px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Verwaltung</span>
                </li>
                <x-aside.toplink label="Apps" icon="apps" :href="route('apps.index')" :aktiv="request()->routeIs('apps.index')" />
                <x-aside.toplink label="Ereignisse" icon="list" :href="route('events.index')" :aktiv="request()->routeIs('events.*')" />
                <x-aside.toplink label="Benachrichtigungen" icon="signal" :href="route('notifications.index')" :aktiv="request()->routeIs('notifications.*')" />
                <x-aside.toplink label="Einstellungen" icon="cog" :href="route('settings')" :aktiv="request()->routeIs('settings')" />
            </ul>
        </div>

        <a href="{{ route('apps.index') }}"
            class="mt-10 pt-3 text-center text-xs text-gray-400 border-t border-gray-200 hover:text-cerulean-600 dark:border-gray-700 dark:text-gray-500">
            PK95 Core v{{ config('pk95.version') }}
        </a>
    </div>
</aside>
