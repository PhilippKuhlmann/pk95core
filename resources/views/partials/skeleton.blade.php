{{--
    Loading skeleton of a page, drawn from a declarative list of parts (the PLACEHOLDER of a
    page, see App\Modules\Concerns\LoadsLazily). It uses the containers, margins and grids of
    the real pages, so nothing jumps when the page arrives. It shows only after a short delay
    (.skeleton in app.css): a fast page never flashes it. Parts:

      ['root', 'classes']                       classes of the page root (e.g. 'p-3 sm:p-5')
      ['header', 'Titel', actions, back]        x-page-header; title null = a bar; actions = number of buttons
                                                right, or width classes of one wide group (e.g. 'w-64');
                                                'sub' => lines of the sub line below the title
      ['filters', n, 'mx-3 mt-2']               filter bar: a search field and n-1 selects (margin classes)
      ['tabs', n]                               x-tabs (mx-3 mt-2)
      ['tiles', n, 'grid classes', 'h-…']       figure tiles in a grid (m-3 gap-3)
      ['panel', 'h-…']                          one x-panel (m-3) of about that height
      ['panels', n, 'h-…', 'md:grid-cols-2']    panels side by side (grid of m-3 panels)
      ['cards', n, 'h-…', 'md:grid-cols-2']     cards in a grid (m-3 gap-3)
      ['table', rows, cols, lines]              table panel: head and rows (lines per row 1|2)
      ['form', fields]                          a form panel with n fields in two columns
      ['text']                                  a hint line (mx-3 mt-2)
      ['card', 'Titel', 'h-…']                  a single panel without margin (dashboard tiles)
--}}
@php
    $parts = collect($parts);
    $root = $parts->first(fn ($p) => $p[0] === 'root')[1] ?? '';
    $tileCols = [
        1 => 'grid-cols-1',
        2 => 'grid-cols-2',
        3 => 'grid-cols-2 md:grid-cols-3',
        4 => 'grid-cols-2 md:grid-cols-4',
        5 => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5',
        6 => 'grid-cols-2 md:grid-cols-3 xl:grid-cols-6',
    ];
    // bar widths that vary from row to row, so a table does not look like a grid paper
    $widths = ['w-3/4', 'w-1/2', 'w-2/3', 'w-5/6', 'w-2/5', 'w-3/5'];
    $panel = 'skeleton-surface rounded-xl border border-gray-200 bg-white shadow-xs dark:border-gray-700 dark:bg-gray-800';
@endphp
<div class="skeleton {{ $root }}" aria-busy="true" data-skeleton>
    <span class="sr-only" role="status">Wird geladen …</span>
    @foreach ($parts as $part)
        @switch($part[0])
            @case('header')
                <div class="flex w-full flex-wrap items-center justify-between gap-3 px-3 pt-4 pb-1" aria-hidden="true">
                    <div class="flex min-w-0 flex-col">
                        @if ($part[3] ?? false)
                            <span class="skeleton-bar mb-1 h-5 w-24"></span>
                        @endif
                        @if (($part[1] ?? null) !== null)
                            <h1 class="text-2xl font-CoconPro text-gray-900 dark:text-gray-100">{{ $part[1] }}</h1>
                        @else
                            <span class="skeleton-bar my-1 h-6 w-56"></span>
                        @endif
                        @for ($i = 0; $i < (int) ($part['sub'] ?? 0); $i++)
                            <span class="{{ $i === 0 ? 'mt-0.5' : '' }} flex h-5 items-center"><span class="skeleton-bar h-3 {{ $i === 0 ? 'w-40' : 'w-64' }} max-w-full"></span></span>
                        @endfor
                    </div>
                    @if (is_string($part[2] ?? null))
                        <div class="flex flex-wrap items-center gap-2 sm:gap-3"><span class="skeleton-bar h-9 {{ $part[2] }} rounded-lg"></span></div>
                    @elseif (($part[2] ?? 0) > 0)
                        <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                            @for ($i = 0; $i < $part[2]; $i++)
                                <span class="skeleton-bar h-9 {{ $i === 0 ? 'w-32' : 'w-24' }} rounded-lg"></span>
                            @endfor
                        </div>
                    @endif
                </div>
                @break

            @case('filters')
                <div class="{{ $part[2] ?? 'mx-3 mt-2' }} flex flex-wrap items-center gap-2" aria-hidden="true">
                    @for ($i = 0; $i < ($part[1] ?? 2); $i++)
                        <span class="{{ $panel }} h-[38px] w-full {{ $i === 0 ? 'sm:w-64' : 'sm:w-48' }} rounded-lg"></span>
                    @endfor
                </div>
                @break

            @case('tabs')
                <div class="mx-3 mt-2 max-w-full overflow-x-hidden" aria-hidden="true">
                    <div class="inline-flex min-w-full gap-1 rounded-lg bg-gray-100 p-1 sm:min-w-0 dark:bg-gray-900/60">
                        @for ($i = 0; $i < ($part[1] ?? 3); $i++)
                            <span class="{{ $i === 0 ? 'bg-white shadow-xs dark:bg-gray-700' : '' }} flex min-h-9 flex-1 items-center justify-center rounded-md px-4 max-sm:min-h-11 sm:w-28 sm:flex-none">
                                <span class="skeleton-bar h-3 w-14"></span>
                            </span>
                        @endfor
                    </div>
                </div>
                @break

            @case('tiles')
                <div class="m-3 grid {{ $part[2] ?? ($tileCols[$part[1] ?? 4] ?? $tileCols[4]) }} gap-3" aria-hidden="true">
                    @for ($i = 0; $i < ($part[1] ?? 4); $i++)
                        <div class="{{ $panel }} {{ $part[3] ?? 'h-[46px]' }} flex items-center gap-2 rounded-lg px-2.5 py-2">
                            <span class="skeleton-bar h-7 w-7 shrink-0 rounded-md"></span>
                            <span class="flex min-w-0 flex-1 flex-col gap-1.5">
                                <span class="skeleton-bar h-3.5 w-10"></span>
                                <span class="skeleton-bar h-2.5 w-20 max-w-full"></span>
                            </span>
                        </div>
                    @endfor
                </div>
                @break

            @case('panel')
            @case('panels')
                @php
                    [$count, $height, $cols] = $part[0] === 'panel' ? [1, $part[1] ?? 'h-48', null] : [$part[1] ?? 2, $part[2] ?? 'h-48', $part[3] ?? 'md:grid-cols-2'];
                @endphp
                {{-- a single panel stands in the page flow (its margins collapse like those of the
                     real panels), several stand in a grid like on the pages --}}
                @if ($cols !== null)
                    <div class="grid grid-cols-1 {{ $cols }}" aria-hidden="true">
                @endif
                @for ($i = 0; $i < $count; $i++)
                    <div class="{{ $panel }} {{ $height }} m-3 flex flex-col overflow-hidden p-5" aria-hidden="true">
                        <span class="skeleton-bar h-4 w-40 max-w-full shrink-0"></span>
                        <span class="skeleton-bar mt-3 h-3 w-full max-w-md shrink-0"></span>
                        <span class="skeleton-bar mt-2 h-3 {{ $widths[$i % 6] }} max-w-sm shrink-0"></span>
                        <span class="skeleton-bar mt-4 min-h-0 flex-1 rounded-lg opacity-60"></span>
                    </div>
                @endfor
                @if ($cols !== null)
                    </div>
                @endif
                @break

            @case('cards')
                <div class="m-3 grid grid-cols-1 gap-3 {{ $part[3] ?? 'md:grid-cols-2' }}" aria-hidden="true">
                    @for ($i = 0; $i < ($part[1] ?? 4); $i++)
                        <div class="{{ $panel }} {{ $part[2] ?? 'h-32' }} flex flex-col gap-2 overflow-hidden p-5">
                            <span class="skeleton-bar h-4 w-1/2 shrink-0"></span>
                            <span class="skeleton-bar h-3 {{ $widths[$i % 6] }} shrink-0"></span>
                            <span class="skeleton-bar h-3 w-1/3 shrink-0"></span>
                        </div>
                    @endfor
                </div>
                @break

            @case('table')
                @php
                    [$rows, $cols, $lines] = [$part[1] ?? 8, max(2, $part[2] ?? 4), $part[3] ?? 1];
                    // like the real tables: middle columns only from md on
                    $colClass = fn ($c) => $c >= 2 && $c < $cols - 1 ? 'hidden md:flex' : 'flex';
                @endphp
                <div class="{{ $panel }} m-3 overflow-hidden" aria-hidden="true">
                    <div class="flex gap-6 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                        @for ($c = 0; $c < $cols; $c++)
                            <span class="{{ $colClass($c) }} h-4 flex-1 items-center {{ $c === $cols - 1 ? 'justify-end' : '' }}"><span class="skeleton-bar h-2.5 w-16"></span></span>
                        @endfor
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        @for ($r = 0; $r < $rows; $r++)
                            <div class="flex gap-6 px-4 py-3">
                                @for ($c = 0; $c < $cols; $c++)
                                    <span class="{{ $colClass($c) }} min-w-0 flex-1 flex-col justify-center gap-1.5 {{ $c === $cols - 1 ? 'items-end' : '' }} {{ $lines > 1 ? 'min-h-[2.375rem]' : 'min-h-5' }}">
                                        <span class="skeleton-bar h-3.5 {{ $c === $cols - 1 ? 'w-16' : $widths[($r + $c) % 6] }}"></span>
                                        @if ($lines > 1 && $c === 0)
                                            <span class="skeleton-bar h-2.5 w-1/3"></span>
                                        @endif
                                    </span>
                                @endfor
                            </div>
                        @endfor
                    </div>
                </div>
                @break

            @case('form')
                <div class="{{ $panel }} m-3 p-5" aria-hidden="true">
                    <span class="skeleton-bar block h-4 w-40"></span>
                    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        @for ($i = 0; $i < ($part[1] ?? 4); $i++)
                            <div class="flex flex-col gap-2">
                                <span class="skeleton-bar h-3 w-28"></span>
                                <span class="h-10 rounded-lg border border-gray-200 dark:border-gray-700"></span>
                            </div>
                        @endfor
                    </div>
                </div>
                @break

            @case('text')
                <div class="mx-3 mt-2 flex h-5 items-center" aria-hidden="true"><span class="skeleton-bar h-3 w-72 max-w-full"></span></div>
                @break

            @case('card')
                <div class="{{ $panel }} {{ $part[2] ?? 'h-48' }} flex flex-col overflow-hidden p-5">
                    <div class="font-DINPro-bold text-gray-900 dark:text-gray-100">{{ $part[1] }}</div>
                    <div class="mt-3 flex min-h-0 flex-1 flex-col gap-2" aria-hidden="true">
                        <span class="skeleton-bar h-4 w-2/3 shrink-0"></span>
                        <span class="skeleton-bar h-4 w-1/2 shrink-0"></span>
                        <span class="skeleton-bar mt-2 min-h-0 flex-1 rounded-lg opacity-60"></span>
                    </div>
                </div>
                @break
        @endswitch
    @endforeach
</div>
