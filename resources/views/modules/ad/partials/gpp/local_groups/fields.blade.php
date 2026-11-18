{{-- editor fields of a local group row ($p = "gpp.local_groups.N.") --}}
@php
    use App\Modules\Ad\Gpp\LocalGroups;
    $groupLabel = LocalGroups::GROUPS[$current['local_group']] ?? $current['local_group'];
    $usedGroups = collect($rows)->except($e)->pluck('local_group')->all();
    // the selection shows only "2 Konten"; the names stand below it
    $memberNames = collect($memberOptions)->pluck('label', 'value')->all() + (array) $current['names'];
    $chosen = fn (string $field) => implode(', ', array_map(fn ($id) => $memberNames[$id] ?? $id, (array) $current[$field]));
@endphp
<x-field label="Lokale Gruppe" :for="'lg-group-'.$e" :feld="$p.'local_group'" hilfe="Jede lokale Gruppe kommt je Gruppenrichtlinie höchstens einmal vor.">
    <x-input.select :id="'lg-group-'.$e" :feld="$p.'local_group'" wire:model.live="{{ $p }}local_group" class="mt-1 md:w-1/2">
        @foreach (LocalGroups::GROUPS as $v => $l)
            <option value="{{ $v }}" @disabled(in_array($v, $usedGroups, true))>{{ $l }}{{ in_array($v, $usedGroups, true) ? ' (schon in dieser Gruppenrichtlinie)' : '' }}</option>
        @endforeach
    </x-input.select>
</x-field>

<div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
    <x-field label="Hinzufügen" :feld="$p.'add_ids'" hilfe="Benutzer und Gruppen der Domäne, die Mitglied werden.">
        <x-input.combobox wire:model.live="{{ $p }}add_ids" label="Hinzufügen" :options="$memberOptions" :multiple="true" :search="true" placeholder="Niemand" many="Konten" class="mt-1" />
        <x-slot:unten>@if ($current['add_ids'])<p class="mt-1 text-xs text-gray-700 dark:text-gray-300" data-chosen-add>{{ $chosen('add_ids') }}</p>@endif</x-slot:unten>
    </x-field>
    <x-field label="Entfernen" :feld="$p.'remove_ids'" hilfe="Benutzer und Gruppen der Domäne, die nicht Mitglied sein sollen.">
        <x-input.combobox wire:model.live="{{ $p }}remove_ids" label="Entfernen" :options="$memberOptions" :multiple="true" :search="true" placeholder="Niemand" many="Konten" class="mt-1" />
        <x-slot:unten>@if ($current['remove_ids'])<p class="mt-1 text-xs text-gray-700 dark:text-gray-300" data-chosen-remove>{{ $chosen('remove_ids') }}</p>@endif</x-slot:unten>
    </x-field>
</div>

<div class="mt-3">
    <x-input.checkbox label="Alle anderen Mitglieder entfernen" hilfe="Danach sind nur noch die Konten unter „Hinzufügen“ Mitglied." wire:model.live="{{ $p }}remove_others" />
</div>
@if ($current['remove_others'])
    <x-callout title="Achtung: alle anderen Mitglieder werden entfernt" ton="gelb" class="mt-2" data-remove-others-warning>
        Auf jedem Rechner, für den diese Gruppenrichtlinie gilt, verlieren alle Mitglieder der lokalen Gruppe {{ $groupLabel }} ihre Mitgliedschaft, die hier nicht unter „Hinzufügen“ stehen, auch lokale Konten und Gruppen der Rechner.
        @if ($current['local_group'] === 'administrators') Domain Admins bleibt immer Mitglied. @endif
    </x-callout>
@elseif ($current['local_group'] === 'administrators')
    <p class="mt-2 text-xs text-gray-600 dark:text-gray-300" data-domain-admins-hint>Domain Admins bleibt immer Mitglied der Administratoren und lässt sich nicht entfernen.</p>
@endif

@include('modules.ad.partials.gpp.condition')
