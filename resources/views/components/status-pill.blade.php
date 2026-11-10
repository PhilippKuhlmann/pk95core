@props(['status'])

@php
    $map = [
        // ProxApps deployments and update runs
        'queued' => ['Eingeplant', 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'],
        'success' => ['Erfolgreich', 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100'],
        'cancelled' => ['Abgebrochen', 'bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100'],
        'removed' => ['Entfernt', 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'],
        'available' => ['Verfügbar', 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'],
        'installing' => ['Wird aktiviert', 'bg-cerulean-100 text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-200'],
        'updating' => ['Wird aktualisiert', 'bg-cerulean-100 text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-200'],
        'starting' => ['Startet', 'bg-cerulean-100 text-cerulean-800 dark:bg-cerulean-900 dark:text-cerulean-200'],
        'running' => ['Läuft', 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100'],
        'unhealthy' => ['Gestört', 'bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100'],
        'stopped' => ['Gestoppt', 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'],
        'failed' => ['Fehler', 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-100'],
        'removing' => ['Wird entfernt', 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'],
    ];
    [$label, $classes] = $map[$status] ?? [$status, 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide whitespace-nowrap '.$classes]) }}>
    @if (in_array($status, ['installing', 'updating', 'starting', 'removing'], true))
        <span class="h-1.5 w-1.5 rounded-full bg-cerulean-500 animate-pulse" aria-hidden="true"></span>
    @elseif ($status === 'running')
        <span class="h-1.5 w-1.5 rounded-full bg-green-500" aria-hidden="true"></span>
    @endif
    {{ $label }}
</span>
