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

    $entry = $manifest['src/main.ts'] ?? null;
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Jotter</title>
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
