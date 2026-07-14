<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'MIMS') }}</title>

    {{-- Lets Flux manage the .dark class on <html> based on system preference
         or the user's saved choice (local storage). Remove this if you want
         to manage appearance manually — see fluxui.dev/docs/dark-mode --}}
    @fluxAppearance

    {{-- Space Grotesk (display) — Instrument Sans is assumed to already be
         loaded app-wide; this adds only the extra display face. --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=space-grotesk:500,600&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-white font-sans">
    {{ $slot }}

    @fluxScripts
</body>
</html>