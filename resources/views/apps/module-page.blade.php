<x-layouts::app :title="$title">
    {{-- not $component: Blade uses that name inside component slots.
         Deferred: the page answers at once with its skeleton (LoadsLazily), the app is asked
         in the Livewire request that follows. --}}
    @livewire($pageComponent, ['installedApp' => $installedApp, 'defer' => true])
</x-layouts::app>
