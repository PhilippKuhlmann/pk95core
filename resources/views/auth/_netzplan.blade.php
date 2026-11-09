{{--
    The plan on the sign-in page: how PK95 runs. Included twice, in two roles:

    1. $hintergrund = true - faint, absolutely positioned, lines and boxes only. Below lg it
       lies behind the card and fills the whole area; from lg on it shrinks to a strip on
       the left so that something shows behind the card without clashing with the readable
       version in the second column.

    2. without $hintergrund - the readable version, only from lg on, as its own grid column
       next to the form.

    Only the readable version carries labels: in the background they would be cut off at the
    card edge in mid-word.

    Pure drawing, no content: aria-hidden. Names and ports are the defaults of PK95 (catalog),
    nothing from a real installation - everybody sees this page, also without access.

    Colours via currentColor: one text class per group, and the drawing follows dark mode.

    Top to bottom: Internet, router (80/443), Proxmox VE with the LXC pk95core and its Docker
    containers on the network pk95, the bridge vmbr0, the app containers ProxApps deployed.
--}}
@php
    $hintergrund ??= false;

    $lage = $hintergrund
        ? 'absolute inset-0 h-full w-full opacity-30
           lg:inset-y-0 lg:left-0 lg:right-auto lg:w-[30rem] lg:opacity-25'
        : 'hidden h-auto max-h-[78vh] w-full lg:block';

    /* Docker containers in the LXC pk95core: x, name, detail. */
    $container = [
        [360, 'pk95core', 'Oberfläche · 8080'],
        [590, 'pk95-proxy', 'Caddy · TLS'],
        [820, 'pk95-uptime', 'Monitore'],
        [1050, 'pk95-proxapps', 'Katalog · API'],
    ];

    /* App containers ProxApps deployed on Proxmox: x, name, subdomain. */
    $apps = [
        [330, 'CT nextcloud', 'cloud'],
        [525, 'CT immich', 'fotos'],
        [720, 'CT paperless', 'paperless'],
        [915, 'CT nodered', 'nodered'],
        [1110, 'CT adguard', 'dns'],
    ];

    /*
     * The ways a packet takes. Several of them look like operation, one alone like a loop.
     * The durations have no common divisor on purpose: otherwise the packets meet again at
     * the start regularly, and that is what the eye notices.
     */
    $wege = [
        // a request from the Internet through the router to the proxy …
        ['d' => 'M765 172 V214', 'dauer' => '2.9s', 'start' => '0s'],
        ['d' => 'M765 258 V300 H690 V400', 'dauer' => '4.3s', 'start' => '1.7s'],
        // … and from the proxy over the bridge to the apps
        ['d' => 'M690 470 V560 H415 V600', 'dauer' => '6.5s', 'start' => '0.6s'],
        ['d' => 'M690 470 V560 H610 V600', 'dauer' => '5.3s', 'start' => '3.9s'],
        // Uptime checks an app
        ['d' => 'M920 470 V560 H805 V600', 'dauer' => '7.1s', 'start' => '2.2s'],
        // ProxApps rolls out a container
        ['d' => 'M1150 470 V560 H1000 V600', 'dauer' => '8.3s', 'start' => '4.6s'],
        // Core talks to the apps over the network pk95
        ['d' => 'M460 470 V500 H1150 V470', 'dauer' => '9.7s', 'start' => '1.1s'],
    ];
@endphp

{{-- slice for the background: fills the area and crops instead of scaling via CSS (Chrome
     drew that visibly blurred). meet for the readable version: nothing may be cut off. --}}
<svg class="pointer-events-none {{ $lage }}" viewBox="270 100 1075 730"
    preserveAspectRatio="xMidYMid {{ $hintergrund ? 'slice' : 'meet' }}"
    aria-hidden="true" focusable="false">

    <g class="text-chathams-blue-200 dark:text-cerulean-900">
        <g stroke="currentColor" stroke-width="1.7" fill="none">
            {{-- Internet and router --}}
            <rect x="680" y="128" width="170" height="44" rx="22" />
            <path d="M765 172 V214" />
            <rect x="680" y="214" width="170" height="44" />
            <path d="M765 258 V300 H690 V400" />

            {{-- Proxmox VE and the LXC pk95core (dashed: a container, not a machine) --}}
            <rect x="300" y="300" width="1015" height="490" />
            <rect x="330" y="356" width="955" height="170" stroke-dasharray="7 5" />

            {{-- Docker containers and the network pk95 --}}
            @foreach ($container as [$x])
                <rect x="{{ $x }}" y="400" width="200" height="70" />
            @endforeach
            <path d="M460 470 V500 M920 470 V500 M1150 470 V500" />
            <path d="M440 500 H1180" />

            {{-- bridge vmbr0: proxy, Uptime and ProxApps reach the app containers --}}
            <path d="M690 470 V560 M920 500 V560 M1150 500 V560" />
            <path d="M400 560 H1210" />
            @foreach ($apps as [$x])
                <path d="M{{ $x + 85 }} 560 V600" />
                <rect x="{{ $x }}" y="600" width="170" height="80" />
            @endforeach

            {{-- storage of the node: snapshots before updates, backups --}}
            <rect x="330" y="718" width="420" height="44" stroke-dasharray="3 4" />
            <rect x="865" y="718" width="420" height="44" stroke-dasharray="3 4" />

            {{-- scale bar, like at the bottom of a drawing --}}
            <path d="M400 806 H580 M400 800 V812 M490 802 V810 M580 800 V812" />
        </g>

        {{-- ports and status lights: filled squares --}}
        <g fill="currentColor" opacity="0.6">
            @for ($i = 0; $i < 6; $i++)
                <rect x="{{ 772 + $i * 12 }}" y="242" width="8" height="7" />
            @endfor
            @foreach ($container as [$x])
                @for ($i = 0; $i < 3; $i++)
                    <rect x="{{ $x + 160 + $i * 11 - 22 }}" y="456" width="8" height="6" />
                @endfor
            @endforeach
            @foreach ($apps as [$x])
                <circle cx="{{ $x + 152 }}" cy="616" r="4" />
            @endforeach
        </g>
    </g>

    @unless ($hintergrund)
        {{-- titles --}}
        <g class="font-mono text-chathams-blue-500 dark:text-cerulean-700" fill="currentColor" font-size="16.5">
            <text x="719" y="156">INTERNET</text>
            <text x="694" y="242">ROUTER</text>
            <text x="316" y="326">PROXMOX VE</text>
            <text x="346" y="382">LXC · pk95core · Docker</text>
            @foreach ($container as [$x, $name])
                <text x="{{ $x + 12 }}" y="426">{{ $name }}</text>
            @endforeach
            @foreach ($apps as [$x, $name])
                <text x="{{ $x + 12 }}" y="626">{{ $name }}</text>
            @endforeach
        </g>

        {{-- details --}}
        <g class="font-mono text-chathams-blue-400 dark:text-cerulean-800" fill="currentColor" font-size="14">
            <text x="868" y="242">80 · 443</text>
            @foreach ($container as [$x, , $detail])
                <text x="{{ $x + 12 }}" y="447">{{ $detail }}</text>
            @endforeach
            <text x="472" y="494">Netz · pk95</text>
            <text x="332" y="565">vmbr0</text>
            <text x="928" y="548">Prüfung</text>
            <text x="1158" y="548">ausrollen</text>
            @foreach ($apps as [$x, , $sub])
                <text x="{{ $x + 12 }}" y="648">{{ $sub }}.…</text>
                <text x="{{ $x + 12 }}" y="668">Monitor · Proxy</text>
            @endforeach
            <text x="346" y="745">Snapshots vor jedem Update</text>
            <text x="881" y="745">Sicherungen · /opt/pk95/backups</text>
        </g>
    @endunless

    {{-- packets last, so they lie on top of the lines. pathLength="100" normalises every way,
         so one CSS rule fits all lengths. In the background they only run below lg: above,
         the readable version is next to it and every packet would run twice. --}}
    @foreach ($wege as $weg)
        <path class="netzplan-paket text-cerulean-600 dark:text-cerulean-500 @if ($hintergrund) lg:hidden @endif"
            d="{{ $weg['d'] }}" pathLength="100"
            style="animation-duration: {{ $weg['dauer'] }}; animation-delay: {{ $weg['start'] }}"
            stroke="currentColor" stroke-width="2.6" stroke-linecap="round" fill="none" opacity="0.85" />
    @endforeach
</svg>
