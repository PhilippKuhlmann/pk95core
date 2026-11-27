<x-layouts::app :title="$title">
    {{-- not $component: Blade uses that name inside component slots.
         Deferred: the page answers at once with its skeleton (LoadsLazily), the app is asked
         in the Livewire request that follows. That request has its own URL: query values the
         page reads in mount() come along as pageQuery (kept in the snapshot). --}}
    @livewire($pageComponent, ['installedApp' => $installedApp, 'defer' => true] + ($pageQuery ? ['pageQuery' => $pageQuery] : []))
</x-layouts::app>
