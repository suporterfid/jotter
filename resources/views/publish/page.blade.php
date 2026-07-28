<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#000000">
    <meta name="color-scheme" content="dark">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ $assetPrefix }}publish.css">
</head>
<body>
    <main class="publish-container">
        <article class="publish-article">
            <h1>{{ $title }}</h1>
            {!! $html !!}
        </article>
    </main>
</body>
</html>
