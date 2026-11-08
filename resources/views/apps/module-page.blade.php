<x-layouts::app :title="$title">
    {{-- not $component: Blade uses that name inside component slots --}}
    @livewire($pageComponent, ['installedApp' => $installedApp])
</x-layouts::app>
