{{-- PK95 AD pages: the domain is not set up yet (409), or the app does not answer --}}
@if ($notReady)
    <x-callout title="Die Domäne ist noch nicht eingerichtet" class="m-3" data-ad-not-ready>
        Benutzer, Gruppen, Computer, Gruppenrichtlinien, DNS und Skripte gibt es erst, wenn PK95 AD als Domänencontroller eingerichtet ist.
        <x-slot:aktionen>
            <x-input.linkbutton :href="route('apps.page', [$installedApp, 'domain'])" wire:navigate size="sm">Zum Einrichtungsassistenten</x-input.linkbutton>
        </x-slot:aktionen>
    </x-callout>
@elseif ($loadError ?? null)
    <x-callout title="PK95 AD antwortet nicht" ton="gelb" class="m-3">{{ $loadError }}</x-callout>
@endif
