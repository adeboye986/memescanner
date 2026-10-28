@props(['name', 'size' => 20, 'strokeWidth' => 1.8])

<svg
    {{ $attributes->merge([
        'class' => 'shrink-0',
        'width' => $size,
        'height' => $size,
        'viewBox' => '0 0 24 24',
        'fill' => 'none',
        'xmlns' => 'http://www.w3.org/2000/svg',
        'aria-hidden' => 'true',
    ]) }}
>
    @switch($name)
        @case('brand')
            <path d="M3.5 18.5V6.8L8.2 3l3.8 5.2L15.8 3l4.7 3.8v11.7l-4.2 2.5v-9.8L12 16.7 7.7 11.2V21l-4.2-2.5Z" fill="currentColor"/>
            @break
        @case('home')
            <path d="m3.5 11 8.5-7 8.5 7M5.5 9.5V20h13V9.5M9.5 20v-6h5v6" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linecap="round" stroke-linejoin="round"/>
            @break
        @case('search')
            <circle cx="10.75" cy="10.75" r="6.25" stroke="currentColor" stroke-width="{{ $strokeWidth }}"/>
            <path d="m15.5 15.5 4 4" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linecap="round"/>
            @break
        @case('opportunity')
            <path d="m12 3 7.5 5v8L12 21l-7.5-5V8L12 3Z" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linejoin="round"/>
            <path d="m12 7-3 5h3l-1 5 4-6h-3l2-4h-2Z" fill="currentColor"/>
            @break
        @case('trading')
            <path d="M4 19V9m0 6 5-5 4 3 7-8M16 5h4v4" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linecap="round" stroke-linejoin="round"/>
            @break
        @case('wallet')
            <path d="M4 6.5h14a2 2 0 0 1 2 2v9H4a2 2 0 0 1-2-2v-11a2 2 0 0 1 2-2h12" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M15 11h5v4h-5a2 2 0 1 1 0-4Z" stroke="currentColor" stroke-width="{{ $strokeWidth }}"/>
            @break
        @case('settings')
            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="{{ $strokeWidth }}"/>
            <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.86 2.86-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1v.1H9.5V21a1.7 1.7 0 0 0-1.1-1.6 1.7 1.7 0 0 0-1.88.34l-.06.06-2.86-2.86.06-.06A1.7 1.7 0 0 0 4 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1-.4h-.1V9.5h.1A1.7 1.7 0 0 0 4 8.4a1.7 1.7 0 0 0-.34-1.88l-.06-.06L6.46 3.6l.06.06A1.7 1.7 0 0 0 8.4 4a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1v-.1h4.1v.1A1.7 1.7 0 0 0 15 4a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.86 2.86-.06.06A1.7 1.7 0 0 0 19.4 8.4a1.7 1.7 0 0 0 .6 1 1.7 1.7 0 0 0 1 .4h.1v4.1H21a1.7 1.7 0 0 0-1.6 1.1Z" stroke="currentColor" stroke-width="1.45" stroke-linecap="round" stroke-linejoin="round"/>
            @break
        @case('bell')
            <path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 8.5h18C21 16 18 16 18 9ZM10 21h4" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linecap="round" stroke-linejoin="round"/>
            @break
        @case('sun')
            <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="{{ $strokeWidth }}"/>
            <path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linecap="round"/>
            @break
        @case('moon')
            <path d="M20.5 15.2A8.5 8.5 0 0 1 8.8 3.5 8.5 8.5 0 1 0 20.5 15.2Z" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linecap="round" stroke-linejoin="round"/>
            @break
        @case('chevron-down')
            <path d="m7 9.5 5 5 5-5" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linecap="round" stroke-linejoin="round"/>
            @break
        @case('menu')
            <path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linecap="round"/>
            @break
        @case('close')
            <path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linecap="round"/>
            @break
        @case('user')
            <circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="{{ $strokeWidth }}"/>
            <path d="M4.5 21a7.5 7.5 0 0 1 15 0" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linecap="round"/>
            @break
        @case('telegram')
            <path d="m3 11 17-7-4.5 16-5-4-3 2.5.5-5L17 7 9 14l-6-3Z" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linecap="round" stroke-linejoin="round"/>
            @break
        @case('setup')
            <path d="M12 3v3m0 12v3M3 12h3m12 0h3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1M18.4 5.6l-2.1 2.1m-8.6 8.6-2.1 2.1" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linecap="round"/>
            <circle cx="12" cy="12" r="3.5" stroke="currentColor" stroke-width="{{ $strokeWidth }}"/>
            @break
        @case('shield')
            <path d="M12 3 5 6v5c0 4.7 2.9 8 7 10 4.1-2 7-5.3 7-10V6l-7-3Z" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linejoin="round"/>
            <path d="m9 12 2 2 4-4" stroke="currentColor" stroke-width="{{ $strokeWidth }}" stroke-linecap="round" stroke-linejoin="round"/>
            @break
        @default
            <circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="{{ $strokeWidth }}"/>
    @endswitch
</svg>
