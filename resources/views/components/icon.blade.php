@props(['name', 'size' => 18])

{{--
    Chat's icons, drawn rather than typed.

    A phone written as ☎ is a font's opinion — on Windows it renders as a box,
    which is exactly what happened. These are paths, so a call button looks like
    a call button on every machine.
--}}
@php
    $paths = [
        'phone' => '<path d="M2.5 4.5c0-1.1.9-2 2-2h1.6c.5 0 .9.3 1 .8l.7 2.6c.1.4 0 .8-.3 1L6.3 8.1a11.5 11.5 0 0 0 5.6 5.6l1.2-1.2c.3-.3.7-.4 1-.3l2.6.7c.5.1.8.5.8 1v1.6c0 1.1-.9 2-2 2h-.5A13.5 13.5 0 0 1 2.5 5v-.5Z"/>',
        'plus' => '<path d="M10 4v12M4 10h12" stroke-linecap="round"/>',
        'search' => '<circle cx="9" cy="9" r="5.2"/><path d="m13 13 3.5 3.5" stroke-linecap="round"/>',
        'paperclip' => '<path d="M14.5 9.5 9.8 14.2a3 3 0 0 1-4.3-4.3l5.4-5.4a2 2 0 0 1 2.9 2.9l-5.4 5.4a1 1 0 0 1-1.4-1.4l4.7-4.7" stroke-linecap="round" stroke-linejoin="round"/>',
        'send' => '<path d="M3 10 17 3.5 13 17l-3.5-5L3 10Z" stroke-linejoin="round"/>',
        'close' => '<path d="m5 5 10 10M15 5 5 15" stroke-linecap="round"/>',
        'minimise' => '<path d="M5 10h10" stroke-linecap="round"/>',
        'expand' => '<path d="M11 4h5v5M16 4l-6.5 6.5M9 16H4v-5M4 16l6.5-6.5" stroke-linecap="round" stroke-linejoin="round"/>',
        'trash' => '<path d="M4 6h12M8 6V4.5c0-.3.2-.5.5-.5h3c.3 0 .5.2.5.5V6M6.5 6l.6 9c0 .6.5 1 1 1h3.8c.5 0 1-.4 1-1l.6-9" stroke-linecap="round" stroke-linejoin="round"/>',
        'hash' => '<path d="M7.5 3.5 6 16.5M14 3.5l-1.5 13M3.5 7.5h13M3 12.5h13" stroke-linecap="round"/>',
        'people' => '<circle cx="7.5" cy="7" r="2.8"/><path d="M2.6 16c0-2.5 2.2-4.2 4.9-4.2s4.9 1.7 4.9 4.2" stroke-linecap="round"/><path d="M13.5 5.4a2.6 2.6 0 0 1 0 5M14.5 11.9c1.8.4 3 1.8 3 3.6" stroke-linecap="round"/>',
        'message' => '<path d="M3.5 5.5c0-1.1.9-2 2-2h9c1.1 0 2 .9 2 2v6c0 1.1-.9 2-2 2H8l-3.5 3v-3H5.5a2 2 0 0 1-2-2v-6Z" stroke-linejoin="round"/>',
        'chevron-down' => '<path d="m5.5 8 4.5 4.5L14.5 8" stroke-linecap="round" stroke-linejoin="round"/>',
        'chevron-up' => '<path d="m5.5 12 4.5-4.5L14.5 12" stroke-linecap="round" stroke-linejoin="round"/>',
        'dots' => '<circle cx="5" cy="10" r="1.3"/><circle cx="10" cy="10" r="1.3"/><circle cx="15" cy="10" r="1.3"/>',
        'file' => '<path d="M11 2.5H6.5a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h7a2 2 0 0 0 2-2V7l-4.5-4.5Z" stroke-linejoin="round"/><path d="M11 2.5V7h4.5" stroke-linejoin="round"/>',
        'mic-off' => '<path d="M4 4l12 12" stroke-linecap="round"/><path d="M12 5.5a2 2 0 0 0-4 0v3M8 11.5a2 2 0 0 0 4 0" stroke-linecap="round"/>',
    ];
@endphp

<svg
    {{ $attributes->merge(['class' => 'rc-icon']) }}
    width="{{ $size }}"
    height="{{ $size }}"
    viewBox="0 0 20 20"
    fill="{{ in_array($name, ['phone', 'send'], true) ? 'currentColor' : 'none' }}"
    stroke="currentColor"
    stroke-width="{{ in_array($name, ['phone', 'send'], true) ? 0 : 1.6 }}"
    aria-hidden="true"
>{!! $paths[$name] ?? '' !!}</svg>
