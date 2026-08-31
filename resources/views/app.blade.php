@php
    $manifestPaths = [
        public_path('build/.vite/manifest.json'),
        public_path('build/manifest.json'),
    ];

    $manifest = null;
    foreach ($manifestPaths as $path) {
        if (is_readable($path)) {
            $manifest = json_decode(file_get_contents($path), true);
            break;
        }
    }

    $entry = $manifest['src/main.ts']
        ?? $manifest['index.html']
        ?? null;

    // Operator branding (JOTTER_BRAND_*); defaults reproduce the stock Jotter shell.
    $brand = \App\Support\Branding::configured();
    $brandDescription = $brand->name === 'Jotter'
        ? 'A fast, local-first Markdown knowledge base and note-taking application.'
        : $brand->name.' — Markdown knowledge base and notes.';
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<!-- Production HTML Shell. Keep synchronized with frontend/index.html (dev shell). -->
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#FFFFFF">
    <script>
      (function () {
        var stored = localStorage.getItem('jotter-theme');
        var preference = stored === 'light' || stored === 'dark' || stored === 'system' ? stored : 'system';
        var theme = preference === 'light' || preference === 'dark'
          ? preference
          : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
        var themeColor = document.querySelector('meta[name="theme-color"]');
        if (themeColor) themeColor.setAttribute('content', theme === 'dark' ? '#191919' : '#FFFFFF');
      })();
    </script>
    <meta name="description" content="{{ $brandDescription }}">

    <title>{{ $brand->name }}</title>

    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <meta property="og:title" content="{{ $brand->name }}">
    <meta property="og:description" content="{{ $brandDescription }}">
    <meta property="og:image" content="{{ $brand->logoUrl ?? url('social-card.png') }}">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">

    @foreach ($entry['css'] ?? [] as $css)
        <link rel="stylesheet" href="{{ asset('build/'.$css) }}">
    @endforeach
</head>
<body>
    <div id="app-right-drawer-primary"></div>
    <div id="app-right-drawer-secondary"></div>
    <div id="app"></div>
    @if (isset($entry['file']))
        <script type="module" src="{{ asset('build/'.$entry['file']) }}"></script>
    @endif
</body>
</html>
