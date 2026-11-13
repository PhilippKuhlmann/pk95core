{{-- "Probleme & Warnungen": worst first, every entry links to the page that can fix it. --}}
<div wire:poll.60s>
    @if ($problems === [])
        <x-panel class="flex items-center gap-2 text-sm text-green-800 dark:text-green-200" data-all-ok>
            <x-icon name="check" class="h-5 w-5 shrink-0 text-green-600 dark:text-green-400" sw="2" />Alles in Ordnung
        </x-panel>
    @else
        <x-panel class="border-amber-300 dark:border-amber-700/70" data-problems>
            <div class="flex items-center gap-2 font-DINPro-bold text-gray-900 dark:text-gray-100">
                <x-icon name="warn" class="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" sw="2" />
                Probleme &amp; Warnungen <span class="font-normal text-gray-500 dark:text-gray-400">({{ count($problems) }})</span>
            </div>
            <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                @foreach ($problems as $p)
                    <li>
                        <a href="{{ $p['href'] }}" wire:navigate class="flex items-start gap-2.5 py-2 hover:text-cerulean-700 dark:hover:text-cerulean-300">
                            <span @class(['mt-1.5 h-2 w-2 shrink-0 rounded-full', 'bg-rose-500' => $p['tone'] === 'bad', 'bg-amber-500' => $p['tone'] !== 'bad']) aria-hidden="true"></span>
                            <span class="min-w-0 flex-1 text-gray-800 dark:text-gray-100">{{ $p['text'] }}</span>
                            <x-icon name="arrow-right" class="mt-0.5 h-4 w-4 shrink-0 text-gray-400" sw="2" />
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-panel>
    @endif
</div>
