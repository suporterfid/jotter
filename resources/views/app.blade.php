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
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<!-- Production HTML Shell. Keep synchronized with frontend/index.html (dev shell). -->
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#000000">
    <meta name="description" content="A fast, local-first Markdown knowledge base and note-taking application.">

    <title>Jotter</title>

    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <meta property="og:title" content="Jotter">
    <meta property="og:description" content="A fast, local-first Markdown knowledge base and note-taking application.">
    <meta property="og:image" content="{{ url('social-card.png') }}">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">

    @foreach ($entry['css'] ?? [] as $css)
        <link rel="stylesheet" href="{{ asset('build/'.$css) }}">
    @endforeach
</head>
<body>
    <div id="app"></div>
    @if (isset($entry['file']))
        <script type="module" src="{{ asset('build/'.$entry['file']) }}"></script>
    @endif
</body>
</html>
