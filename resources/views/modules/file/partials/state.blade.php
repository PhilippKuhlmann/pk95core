{{-- PK95 File pages: the server has not joined the domain yet (409), or the app does not answer --}}
@if ($notReady)
    <x-callout title="Der Dateiserver ist noch nicht eingerichtet" class="m-3" data-file-not-ready>
        Freigaben und Sitzungen gibt es erst, wenn PK95 File der Domäne von PK95 AD beigetreten ist.
        <x-slot:aktionen>
            <x-input.linkbutton :href="route('apps.page', [$installedApp, 'server'])" wire:navigate size="sm">Zum Einrichtungsassistenten</x-input.linkbutton>
        </x-slot:aktionen>
    </x-callout>
@elseif ($loadError ?? null)
    <x-callout title="PK95 File antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
@endif
