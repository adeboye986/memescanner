@props(['chain', 'size' => 42])

@if($chain === 'solana')
    <svg
        {{ $attributes->merge(['class' => 'shrink-0']) }}
        width="{{ $size }}"
        height="{{ $size }}"
        viewBox="0 0 42 42"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        aria-hidden="true"
    >
        <circle cx="21" cy="21" r="21" fill="#111827" />
        <path d="M12.4 12.7h18.4l-3.4 3.5H9l3.4-3.5Z" fill="#7C5CFF" />
        <path d="M9 19.2h18.4l3.4 3.5H12.4L9 19.2Z" fill="#34D399" />
        <path d="M12.4 25.8h18.4l-3.4 3.5H9l3.4-3.5Z" fill="#26C8D9" />
    </svg>
@else
    <svg
        {{ $attributes->merge(['class' => 'shrink-0']) }}
        width="{{ $size }}"
        height="{{ $size }}"
        viewBox="0 0 42 42"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        aria-hidden="true"
    >
        <circle cx="21" cy="21" r="21" fill="#627EEA" />
        <path d="m21 7.5-8 13.3 8 4.6 8-4.6L21 7.5Z" fill="white" fill-opacity=".92" />
        <path d="m21 27-8-4.6L21 34.5 29 22.4 21 27Z" fill="white" fill-opacity=".7" />
    </svg>
@endif
