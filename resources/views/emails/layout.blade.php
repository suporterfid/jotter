@php
    $brand = \App\Support\Branding::configured();
    $appUrl = rtrim((string) config('app.url'), '/');
    $footerLinks = array_filter([
        trans('emails.footer.terms', [], $locale) => $brand->termsUrl,
        trans('emails.footer.privacy', [], $locale) => $brand->privacyUrl,
        trans('emails.footer.support', [], $locale) => $brand->supportUrl,
    ]);
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject ?? $brand->name }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f2;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#252525;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f2;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border:1px solid #d9d7d3;border-radius:8px;">
                    <tr>
                        <td style="padding:20px 28px;border-bottom:1px solid #eeecea;font-weight:700;font-size:18px;">
                            @if ($brand->logoUrl)
                                <img src="{{ $brand->logoUrl }}" alt="{{ $brand->name }}" height="28" style="height:28px;vertical-align:middle;">
                            @else
                                {{ $brand->name }}
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 28px;font-size:15px;line-height:1.55;">
                            {{ $slot }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px;border-top:1px solid #eeecea;font-size:12px;line-height:1.6;color:#5f5f5f;">
                            <a href="{{ $appUrl }}" style="color:#5f5f5f;">{{ $appUrl }}</a>
                            @foreach ($footerLinks as $label => $href)
                                &nbsp;·&nbsp;<a href="{{ $href }}" style="color:#5f5f5f;">{{ $label }}</a>
                            @endforeach
                            @if ($brand->poweredBy)
                                <br><a href="{{ \App\Support\Branding::REPOSITORY_URL }}" style="color:#5f5f5f;">{{ trans('emails.footer.powered_by', [], $locale) }}</a>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
