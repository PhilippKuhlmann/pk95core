{{--
    The clock of PK95 AD, ticking in the browser without polling: $skew = AD minus Core in ms
    (AdTime::normalize). The difference to the browser clock is taken once when it starts.
    $class: classes of the time.
--}}
@php
    $adNow = now()->getTimestampMs() + (int) $skew;
@endphp
<time class="{{ $class ?? '' }} tabular-nums" data-ad-clock
    x-data="{
        d: {{ $adNow }} - Date.now(),
        t: @js(\App\Modules\Ad\AdTime::clock((int) $skew)),
        timer: null,
        tick() { this.t = new Date(Date.now() + this.d).toLocaleTimeString('de-DE', { timeZone: @js(\App\Http\Support\Format::timezone()), hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }) },
        init() { this.tick(); this.timer = setInterval(() => this.tick(), 1000) },
        destroy() { clearInterval(this.timer) },
    }" x-text="t">{{ \App\Modules\Ad\AdTime::clock((int) $skew) }}</time>
