{{-- The icon buttons of one share, in the table row and on the mobile card. Needs $sh, $driveLink. --}}
@php use Illuminate\Support\Js; @endphp
<div class="flex justify-end gap-1">
    @if ($driveLink)
        <x-input.symbolknopf titel="Als Netzlaufwerk verteilen" ton="grau" :href="$driveLink($sh)" data-drive-map><x-icon name="link" class="h-4 w-4" /></x-input.symbolknopf>
    @endif
    <x-input.symbolknopf titel="Vorgängerversionen" ton="grau" wire:click="openSnapshots({{ Js::from($sh['name']) }})"><x-icon name="clock" class="h-4 w-4" /></x-input.symbolknopf>
    <x-input.symbolknopf titel="Bearbeiten" ton="grau" wire:click="edit({{ Js::from($sh['name']) }})"><x-icon name="pencil" class="h-4 w-4" /></x-input.symbolknopf>
    <x-input.symbolknopf titel="{{ $sh['name'] }} löschen" ton="rot" wire:click="confirmDelete({{ Js::from($sh['name']) }})"><x-icon name="trash" class="h-4 w-4" /></x-input.symbolknopf>
</div>
