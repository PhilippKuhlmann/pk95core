@props([
    'title' => '',
])
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' · PK95 Core' : 'PK95 Core' }}</title>
    <link rel="icon" href="{{ asset('img/logo.svg') }}" type="image/svg+xml">

    @include('layouts.partials.theme')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="antialiased font-DINPro bg-gray-100 text-gray-900 dark:bg-gray-900 dark:text-gray-100" x-data="seitenlayout"
    x-on:keydown.escape.window="mobilOffen && mobilUmschalten(false)">

    @include('layouts.navigation')
    @include('layouts.aside')

    {{-- lg:ml-64 is the default (expanded) so the right thing shows before Alpine runs;
         collapsed overrides it with lg:ml-0!. --}}
    <div class="mt-16 lg:ml-64" x-bind:class="{ 'lg:ml-0!': menuEin }"></div>

    <main class="lg:ml-64 pb-10" x-bind:class="{ 'lg:ml-0!': menuEin }">
        {{ $slot }}
    </main>

    @include('layouts.toasts')
    <x-loeschdialog />

    @livewireScripts
</body>

</html>
