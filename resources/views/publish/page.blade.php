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
</body>
</html>
