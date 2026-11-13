@props(['deploymentId', 'compact' => false])

{{-- Proxy domain Core created for a ProxApps deployment, with its state. --}}
@php
    $link = \App\Models\AppLink::forProxApps((int) $deploymentId);
    $domain = $link?->shownDomain();
@endphp
@if ($domain)
    @if ($link->state === \App\Models\AppLink::ACTIVE)
        <a href="https://{{ $domain }}" target="_blank" rel="noopener" onclick="event.stopPropagation()"
            {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 font-mono text-cerulean-600 hover:underline dark:text-cerulean-400']) }}
            data-tip="Im Proxy eingetragen">
            @unless ($compact)<span class="h-1.5 w-1.5 shrink-0 rounded-full bg-green-500"></span>@endunless{{ $domain }}
        </a>
    @else
        <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 font-mono text-gray-500 dark:text-gray-400']) }}
            data-tip="{{ $link->message ?: (\App\Models\AppLink::STATES[$link->state] ?? '') }}">
            @unless ($compact)<span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $link->state === \App\Models\AppLink::PENDING_DNS ? 'bg-amber-500' : 'bg-rose-500' }}"></span>@endunless{{ $domain }}
            @unless ($compact)<span class="font-sans text-xs">({{ \App\Models\AppLink::STATES[$link->state] ?? $link->state }})</span>@endunless
            @if ($link->message || $compact)<span class="sr-only">{{ $link->message ?: (\App\Models\AppLink::STATES[$link->state] ?? '') }}</span>@endif
        </span>
    @endif
@endif
