@auth
    <x-layouts.shell :title="$title ?? null">
        {{ $slot }}
    </x-layouts.shell>
@else
    @php $applicationName = app(\App\Services\ApplicationSettingsService::class)->get('general.application_name'); @endphp
    <!DOCTYPE html>
    <html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="csrf-token" content="{{ csrf_token() }}">
            <title>{{ isset($title) ? $title.' · '.$applicationName : $applicationName }}</title>
            <x-theme-head />
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        </head>
        <body class="ms-app-body">
            <div class="ms-guest-shell">
                <nav aria-label="Guest navigation" class="ms-guest-navigation">
                    <a href="{{ route('dashboard') }}" class="ms-brand" aria-label="{{ $applicationName }} home">
                        <span class="ms-brand-mark"><x-app-icon name="brand" :size="29" /></span>
                        <span class="ms-brand-name">{{ $applicationName }}</span>
                    </a>
                    <div class="ms-guest-actions">
                        <button type="button" class="ms-icon-button" data-theme-toggle aria-label="Switch color theme" aria-pressed="false">
                            <span data-theme-icon="light"><x-app-icon name="sun" :size="19" /></span>
                            <span data-theme-icon="dark"><x-app-icon name="moon" :size="19" /></span>
                        </button>
                        <a href="{{ route('login') }}">Sign in</a>
                        <a href="{{ route('register') }}" class="ms-guest-primary">Create account</a>
                    </div>
                </nav>
                <main class="ms-guest-content">
                    {{ $slot }}
                </main>
            </div>
        </body>
    </html>
@endauth
