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

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
