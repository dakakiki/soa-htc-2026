@php
    /**
     * `$meta` comes from SpaController: the site defaults for the application's
     * own screens, the page's or post's own title and description for public
     * content. Rendered server-side because a crawler expanding a shared link
     * does not run the SPA.
     */
    $meta = $meta ?? [];
    $title = $meta['title'] ?? config('app.name', 'SOA HTC');
    $description = $meta['description'] ?? null;
    $image = $meta['image'] ?? null;

    /**
     * The holding screen, from SpaController: the ground of whatever loads next,
     * so the first paint is already the right colour. Defaults are here as well
     * because this view is also rendered by tests and by error pages that do not
     * come through that controller.
     */
    $splash = ($splash ?? []) + [
        'bg' => '#fbfaf8',
        'ink' => '#003758',
        'rule' => '#f39200',
        'name' => config('app.name', 'SOA HTC'),
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title }}</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
    <link rel="canonical" href="{{ url()->current() }}">
    {{-- Served by ManifestController, from the same theme settings as the favicon. --}}
    <link rel="manifest" href="/manifest.webmanifest">

    <meta property="og:type" content="{{ $meta['type'] ?? 'website' }}">
    <meta property="og:site_name" content="{{ $meta['site_name'] ?? config('app.name', 'SOA HTC') }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:url" content="{{ url()->current() }}">
    @if ($description)
        <meta property="og:description" content="{{ $description }}">
    @endif
    @if ($image)
        <meta property="og:image" content="{{ $image }}">
    @endif
    <meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">

    {{-- The window's own chrome, so a phone tints its bars before anything is drawn. --}}
    <meta name="theme-color" content="{{ $splash['bg'] }}">

    {{--
        Inlined rather than put in the stylesheet: the stylesheet is a request,
        and everything below exists precisely for the moment before requests
        have come back. It is a few hundred bytes and it never blocks.
    --}}
    <style>
        html { background: {{ $splash['bg'] }}; }
        body { margin: 0; }

        .boot {
            position: fixed;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 18px;
            background: {{ $splash['bg'] }};
            color: {{ $splash['ink'] }};
            font-family: 'Instrument Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
        }

        /* The orange rule every screen in this application opens with, so the
           splash is the first line of the page rather than a different thing. */
        .boot__rule { width: 44px; height: 3px; background: {{ $splash['rule'] }}; }

        .boot__name {
            font-size: 15px;
            font-weight: 600;
            letter-spacing: .16em;
            text-transform: uppercase;
        }

        .boot__bar { width: 132px; height: 2px; overflow: hidden; background: currentColor; opacity: .16; }
        .boot__bar i { display: block; width: 40%; height: 100%; background: {{ $splash['rule'] }}; }

        @media (prefers-reduced-motion: no-preference) {
            .boot__bar i { animation: boot-sweep 1.15s ease-in-out infinite; }
        }

        @keyframes boot-sweep {
            from { transform: translateX(-100%); }
            to { transform: translateX(330%); }
        }
    </style>

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body>
    {{--
        Vue sets `container.textContent = ''` before it mounts (runtime-dom's
        own `createApp`), so this is taken away the moment the application has
        something of its own to show. Nothing removes it by hand and nothing has
        to — which also means it MUST stay inside `#app`: a sibling would sit
        over the application for ever.
    --}}
    <div id="app">
        <div class="boot" role="status" aria-live="polite">
            <span class="boot__rule" aria-hidden="true"></span>
            <span class="boot__name">{{ $splash['name'] }}</span>
            <span class="boot__bar" aria-hidden="true"><i></i></span>
            <noscript>This application needs JavaScript switched on.</noscript>
        </div>
    </div>
</body>
</html>
