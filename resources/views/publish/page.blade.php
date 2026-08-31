@php
    $brand = \App\Support\Branding::configured();
    $brandLinks = array_filter([
        'terms' => $brand->termsUrl,
        'privacy' => $brand->privacyUrl,
        'support' => $brand->supportUrl,
    ]);
    $brandLinkLabels = $brandLinkLabels ?? ['terms' => 'Terms', 'privacy' => 'Privacy', 'support' => 'Support'];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#FFFFFF">
    <meta name="color-scheme" content="light dark">
    <script src="{{ $assetPrefix }}publish-theme.js"></script>
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ $assetPrefix }}publish.css">
</head>
<body>
    <header class="publish-toolbar">
        <label for="publish-theme-preference" class="publish-theme-control">
            <span>{{ $themeLabels['preference'] }}</span>
            <select id="publish-theme-preference" name="theme-preference">
                <option value="system">{{ $themeLabels['system'] }}</option>
                <option value="light">{{ $themeLabels['light'] }}</option>
                <option value="dark">{{ $themeLabels['dark'] }}</option>
            </select>
        </label>
    </header>
    <main class="publish-container">
        <article class="publish-article">
            <h1>{{ $title }}</h1>
            {!! $html !!}
        </article>
    </main>
    @if ($brandLinks !== [] || $brand->poweredBy)
    <footer class="publish-footer" data-brand="{{ $brand->name }}">
        @foreach ($brandLinks as $key => $href)
            <a href="{{ $href }}" rel="noopener">{{ $brandLinkLabels[$key] ?? ucfirst($key) }}</a>
        @endforeach
        @if ($brand->poweredBy)
            <a class="publish-powered-by" href="{{ \App\Support\Branding::REPOSITORY_URL }}" rel="noopener">Powered by Jotter</a>
        @endif
    </footer>
    @endif
</body>
</html>
