<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#FFFFFF">
    <meta name="color-scheme" content="light dark">
    <script>
      (function () {
        var stored = localStorage.getItem('jotter-theme');
        var preference = stored === 'light' || stored === 'dark' || stored === 'system' ? stored : 'system';
        var theme = preference === 'light' || preference === 'dark'
          ? preference
          : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
      })();
    </script>
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
