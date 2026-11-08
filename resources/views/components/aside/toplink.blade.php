@props(['href', 'label', 'icon', 'aktiv' => false, 'klein' => false])

{{-- A sidebar entry (DokuVault x-aside.toplink); "klein" for the pages inside an app group. --}}
<li>
    <a href="{{ $href }}" @if ($aktiv) aria-current="page" @endif
        @class([
            'group flex items-center w-full rounded-lg font-medium transition-colors',
            'p-2 text-sm' => ! $klein,
            'px-2 py-1.5 text-[13px]' => $klein,
            'bg-cerulean-50 text-cerulean-700 dark:bg-gray-800 dark:text-cerulean-400' => $aktiv,
            'text-gray-700 hover:bg-gray-100 hover:text-cerulean-700 dark:text-gray-200 dark:hover:bg-gray-800' => ! $aktiv && ! $klein,
            'text-gray-600 hover:bg-gray-100 hover:text-cerulean-700 dark:text-gray-300 dark:hover:bg-gray-800' => ! $aktiv && $klein,
        ])>
        <x-icon :name="$icon"
            @class([
                'shrink-0 transition-colors',
                'w-5 h-5' => ! $klein,
                'w-4 h-4' => $klein,
                'text-cerulean-600 dark:text-cerulean-400' => $aktiv,
                'text-gray-400 group-hover:text-cerulean-600 dark:text-gray-500' => ! $aktiv,
            ]) />
        <span @class(['flex-1 text-left truncate', 'ml-3' => ! $klein, 'ml-2.5' => $klein])>{{ $label }}</span>
    </a>
</li>
