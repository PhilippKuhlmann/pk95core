{{-- under the main cell of a preferences row: "Gilt für" on a phone, the read-only warning, the row's errors --}}
@include('modules.ad.partials.gpp.audience', ['small' => true])
@if ($row['readonly'])
    <span class="mt-1 flex items-start gap-1 text-xs text-amber-800 dark:text-amber-200" data-gpp-readonly>
        <x-icon name="lock" class="mt-0.5 h-3 w-3 shrink-0" />{{ $row['warning'] !== '' ? $row['warning'] : 'Mit der GPMC angelegt; hier nicht bearbeitbar.' }}
    </span>
@endif
@foreach ($messages as $message)
    <span class="mt-1 block text-xs text-red-700 dark:text-red-300" role="status">{{ $message }}</span>
@endforeach
