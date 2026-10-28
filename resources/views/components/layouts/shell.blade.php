@props(['title' => null, 'administrator' => false])

@php
    $configuredApplicationName = trim((string) app(\App\Services\ApplicationSettingsService::class)->get('general.application_name'));
    $applicationName = $configuredApplicationName === '' || strcasecmp($configuredApplicationName, 'Laravel') === 0
        ? 'Meme Scanner'
        : $configuredApplicationName;
    $user = auth()->user();
    $isAdministrator = (bool) $administrator;
    $settingsUrl = $isAdministrator ? route('settings.index') : route('account.edit').'#account-settings';
    $walletsUrl = $isAdministrator ? route('dashboard').'#wallets' : route('account.edit').'#wallets';
    $scannerUrl = route('dashboard').'#scanner-controls';
    $systemStatusUrl = route('dashboard').($isAdministrator ? '#system-status' : '#system-activity');
    $primaryNavigation = [
        ['label' => 'Overview', 'icon' => 'home', 'url' => route('dashboard'), 'active' => request()->routeIs('dashboard'), 'section' => 'overview'],
        ['label' => 'Token Scanner', 'icon' => 'search', 'url' => $scannerUrl, 'active' => false, 'section' => 'scanner'],
        ['label' => 'Opportunities', 'icon' => 'opportunity', 'url' => route('opportunities.index'), 'active' => request()->routeIs('opportunities.*'), 'section' => 'opportunities'],
        ['label' => 'Trading', 'icon' => 'trading', 'url' => route('trades.index'), 'active' => request()->routeIs('trades.*'), 'section' => 'trading'],
        ['label' => 'Wallets', 'icon' => 'wallet', 'url' => $walletsUrl, 'active' => false, 'section' => 'wallets'],
        ['label' => 'Settings', 'icon' => 'settings', 'url' => $settingsUrl, 'active' => request()->routeIs('settings.*', 'account.*', 'telegram.*'), 'section' => 'settings'],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title ? $title.' · '.$applicationName : $applicationName }}</title>
        <x-theme-head />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="ms-app-body">
        <div class="ms-app-shell" data-application-shell>
            <button
                type="button"
                class="ms-sidebar-backdrop"
                data-sidebar-backdrop
                aria-label="Close navigation"
                hidden
            ></button>

            <aside id="app-sidebar" class="ms-sidebar" data-sidebar aria-label="Application navigation">
                <div class="ms-sidebar-header">
                    <a href="{{ route('dashboard') }}" class="ms-brand" aria-label="{{ $applicationName }} overview">
                        <span class="ms-brand-mark"><x-app-icon name="brand" :size="27" /></span>
                        <span class="ms-brand-name">{{ $applicationName }}</span>
                    </a>
                    <button type="button" class="ms-sidebar-close" data-sidebar-close aria-label="Close navigation">
                        <x-app-icon name="close" :size="20" />
                    </button>
                </div>

                <nav class="ms-primary-navigation" aria-label="Primary navigation">
                    @foreach($primaryNavigation as $item)
                        <a
                            href="{{ $item['url'] }}"
                            data-shell-nav-item
                            data-shell-section="{{ $item['section'] }}"
                            @class(['ms-nav-link', 'is-active' => $item['active']])
                            @if($item['active']) aria-current="page" @endif
                        >
                            <x-app-icon :name="$item['icon']" :size="18" />
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </nav>

                <div class="ms-sidebar-spacer"></div>

                <a href="{{ $systemStatusUrl }}" class="ms-system-link">
                    <span class="ms-system-indicator" aria-hidden="true"></span>
                    <span>System status</span>
                </a>
            </aside>

            <div class="ms-shell-content">
                <header class="ms-topbar">
                    <button
                        type="button"
                        class="ms-icon-button ms-mobile-menu"
                        data-sidebar-toggle
                        aria-controls="app-sidebar"
                        aria-expanded="false"
                    >
                        <span class="sr-only">Open navigation</span>
                        <x-app-icon name="menu" :size="21" />
                    </button>

                    <span class="ms-mobile-brand">{{ $applicationName }}</span>

                    <div class="ms-navigation-search">
                        <x-app-icon name="search" :size="16" />
                        <label for="application-navigation-search" class="sr-only">Search navigation</label>
                        <input
                            id="application-navigation-search"
                            type="search"
                            placeholder="Search navigation..."
                            autocomplete="off"
                            data-navigation-search
                        >
                        <kbd>⌘ K</kbd>
                    </div>
                    <p class="sr-only" data-navigation-search-status aria-live="polite"></p>

                    <div class="ms-topbar-actions">
                        <button
                            type="button"
                            class="ms-icon-button ms-theme-toggle"
                            data-theme-toggle
                            aria-label="Switch color theme"
                            aria-pressed="false"
                        >
                            <span data-theme-icon="light"><x-app-icon name="sun" :size="19" /></span>
                            <span data-theme-icon="dark"><x-app-icon name="moon" :size="19" /></span>
                        </button>

                        <a href="{{ route('telegram.settings') }}" class="ms-icon-button ms-notification-link" aria-label="Notification settings" title="Notification settings">
                            <x-app-icon name="bell" :size="18" />
                        </a>

                        <details class="ms-account-menu" data-account-menu>
                            <summary>
                                <span class="ms-avatar">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                                <strong class="ms-account-name">{{ $user->name }}</strong>
                                <x-app-icon name="chevron-down" :size="14" />
                            </summary>
                            <div class="ms-account-popover">
                                <div class="ms-account-popover-header">
                                    <strong>{{ $user->name }}</strong>
                                    <span>{{ $user->email }}</span>
                                    <small>{{ $isAdministrator ? 'Administrator' : 'Trader' }}</small>
                                </div>
                                <a href="{{ route('account.edit') }}"><x-app-icon name="user" :size="17" /> Profile & security</a>
                                <a href="{{ route('telegram.settings') }}"><x-app-icon name="telegram" :size="17" /> Telegram & notifications</a>
                                @unless($isAdministrator)
                                    <a href="{{ route('onboarding') }}"><x-app-icon name="setup" :size="17" /> Setup</a>
                                @endunless
                                @can('review-ethereum-accounting')
                                    <a href="{{ route('ethereum-eligibility.index') }}"><x-app-icon name="shield" :size="17" /> Ethereum accounting</a>
                                @endcan
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit">Sign out</button>
                                </form>
                            </div>
                        </details>
                    </div>
                </header>

                <main class="ms-main-content">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
