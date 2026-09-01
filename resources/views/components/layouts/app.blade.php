<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'Meme Scanner' }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-950 font-sans text-slate-100 antialiased">
        <div class="min-h-screen bg-[radial-gradient(circle_at_top_left,rgba(16,185,129,0.10),transparent_30%),radial-gradient(circle_at_top_right,rgba(59,130,246,0.08),transparent_28%)]">
            <nav aria-label="Primary navigation" class="mx-auto flex w-full max-w-[1600px] items-center gap-2 px-4 pt-5 sm:px-6 lg:px-8">
                <a href="{{ route('dashboard') }}" @class([
                    'rounded-lg px-3 py-2 text-sm font-semibold transition',
                    'bg-slate-800 text-white' => request()->routeIs('dashboard'),
                    'text-slate-400 hover:bg-slate-900 hover:text-white' => ! request()->routeIs('dashboard'),
                ])>Dashboard</a>
                <a href="{{ route('trades.index') }}" @class([
                    'rounded-lg px-3 py-2 text-sm font-semibold transition',
                    'bg-slate-800 text-white' => request()->routeIs('trades.*'),
                    'text-slate-400 hover:bg-slate-900 hover:text-white' => ! request()->routeIs('trades.*'),
                ])>Trade History</a>
            </nav>
            <main class="mx-auto flex max-w-[1600px] flex-col gap-8 px-4 py-6 sm:px-6 lg:px-8 lg:py-10">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
