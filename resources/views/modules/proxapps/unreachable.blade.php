{{-- ProxApps did not answer before any data arrived (Bridged::render). --}}
<div>
    <x-page-header title="ProxApps" />
    <x-callout title="ProxApps antwortet nicht" ton="gelb" class="m-3">
        {{ $bridgeError }}
        <x-slot:aktionen>
            <x-input.button type="button" color="gray" size="sm" wire:click="retry" wire:loading.attr="disabled">Erneut versuchen</x-input.button>
            <x-input.linkbutton :href="route('apps.show', $installedApp)" color="gray">Zur App</x-input.linkbutton>
        </x-slot:aktionen>
    </x-callout>
</div>
