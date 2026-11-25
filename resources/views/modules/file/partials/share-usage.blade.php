{{-- Used space of one share with the quota bar, in the table row and on the mobile card. Needs $r. --}}
@php use App\Modules\File\Shares; @endphp
<div class="text-sm tabular-nums">{{ Shares::bytes($r['used']) }}@if ($r['quota'] > 0)<span class="text-gray-500 dark:text-gray-400"> von {{ Shares::bytes($r['quota']) }}</span>@endif</div>
@if ($r['pct'] !== null)
    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="img" aria-label="{{ $r['pct'] }} % des Kontingents">
        <div class="h-full rounded-full {{ $r['barTone'] }}" style="width: {{ $r['pct'] }}%"></div>
    </div>
@endif
