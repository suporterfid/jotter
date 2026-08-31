<x-mail-layout :locale="$locale" :subject="$subject">
    <p>{{ trans('emails.greeting', ['name' => $recipient->name], $locale) }}</p>
    <p>{{ trans('emails.welcome.intro', ['brand' => $brand->name, 'workspace' => $workspace->name], $locale) }}</p>
    <p>
        <a href="{{ $loginUrl }}" style="display:inline-block;padding:10px 18px;background:#1a6dc1;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;">{{ trans('emails.welcome.login_button', [], $locale) }}</a>
    </p>
    <p>{{ trans('emails.welcome.credentials', ['email' => $recipient->email], $locale) }}</p>
    <ul>
        <li>{{ trans('emails.welcome.webdav', [], $locale) }} <a href="{{ $webdavUrl }}">{{ $webdavUrl }}</a></li>
        <li>{{ trans('emails.welcome.mcp', [], $locale) }} <a href="{{ $mcpGuideUrl }}">{{ trans('emails.welcome.mcp_guide', [], $locale) }}</a></li>
    </ul>
    @if ($trialDays !== null)
        <p>{{ trans_choice('emails.welcome.trial', $trialDays, ['days' => $trialDays], $locale) }}</p>
    @endif
    <p>{{ trans('emails.signoff', ['brand' => $brand->name], $locale) }}</p>
</x-mail-layout>
