@props(['name' => 'grid'])
@php
    $paths = [
        'grid' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
        'desk' => 'M4 4h16v12H4z M8 20h8 M12 16v4 M8 8h8 M8 12h5',
        'users' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M16 3a4 4 0 0 1 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
        'sale' => 'M3 5h2l2 12h12l2-9H6 M10 21h.01 M18 21h.01',
        'box' => 'm12 3 9 5-9 5-9-5 9-5 M3 8v9l9 5 9-5V8 M12 13v9 M7.5 5.5l9 5',
        'wallet' => 'M20 8V4H6a3 3 0 0 0-3 3v12a2 2 0 0 0 2 2h16V8H6a1 1 0 0 1 0-2 M21 12h-6v5h6 M17 14.5h.01',
        'document' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z M14 2v6h6 M8 13h8 M8 17h6',
        'chart' => 'M4 3v18h17 M8 16v-5 M13 16V7 M18 16v-8',
        'settings' => 'M4 7h16 M4 17h16 M8 4v6 M16 14v6',
        'building' => 'M4 21V3h12v18 M16 9h4v12 M2 21h20 M8 7h4 M8 11h4 M8 15h4 M8 21v-3h4v3',
        'chevron' => 'm9 5 7 7-7 7',
        'menu' => 'M4 6h16 M4 12h16 M4 18h16',
        'close' => 'm6 6 12 12 M6 18 18 6',
    ];
@endphp
<svg {{ $attributes->class(['navigation-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="{{ $paths[$name] ?? $paths['grid'] }}"/></svg>
