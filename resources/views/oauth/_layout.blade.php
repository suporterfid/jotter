<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} — {{ $brand->name }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f7f7f8; --card: #fff; --text: #1d1d22; --muted: #5d5d69; --border: #d9d9e0; --accent: #6d4aff; --warn-bg: #fff4e5; --warn: #8a4b00; --err: #b42318; }
        @media (prefers-color-scheme: dark) { :root { --bg: #16161a; --card: #202027; --text: #ececf1; --muted: #a3a3b2; --border: #3a3a45; --accent: #9b85ff; --warn-bg: #3a2a12; --warn: #ffcf8a; --err: #ff8a80; } }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Open Sans", system-ui, sans-serif; background: var(--bg); color: var(--text); }
        main { max-width: 560px; margin: 0 auto; padding: 32px 16px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 24px; }
        h1 { font-size: 1.35rem; margin: 0 0 8px; }
        p, li, label { line-height: 1.5; }
        .muted { color: var(--muted); font-size: 0.9rem; }
        .warn { background: var(--warn-bg); color: var(--warn); padding: 10px 12px; border-radius: 8px; margin: 12px 0; }
        .error { color: var(--err); font-size: 0.9rem; margin: 4px 0 0; }
        fieldset { border: 1px solid var(--border); border-radius: 8px; margin: 16px 0; padding: 12px; }
        legend { padding: 0 6px; font-weight: 600; }
        label.check { display: flex; gap: 8px; align-items: flex-start; margin: 6px 0; }
        textarea, input[type=text], select { width: 100%; font: inherit; color: inherit; background: transparent; border: 1px solid var(--border); border-radius: 6px; padding: 8px; }
        textarea { min-height: 72px; font-family: ui-monospace, monospace; font-size: 0.85rem; }
        .actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px; flex-wrap: wrap; }
        button { font: inherit; padding: 10px 18px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: inherit; cursor: pointer; }
        button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        code { font-family: ui-monospace, monospace; font-size: 0.85rem; }
    </style>
</head>
<body>
<main>
    <div class="card">
        @yield('content')
    </div>
    <p class="muted" style="text-align:center">{{ $brand->name }}</p>
</main>
</body>
</html>
