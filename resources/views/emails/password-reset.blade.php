<x-mail-layout :locale="$locale" :subject="$subject">
    <p>{{ trans('emails.greeting', ['name' => $recipient->name], $locale) }}</p>
    <p>{{ trans('emails.password_reset.body', ['brand' => $brand->name], $locale) }}</p>
    <p>
        <a href="{{ $loginUrl }}" style="display:inline-block;padding:10px 18px;background:#1a6dc1;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;">{{ trans('emails.password_reset.login_button', [], $locale) }}</a>
    </p>
    <p>{{ trans('emails.password_reset.warning', [], $locale) }}</p>
    <p>{{ trans('emails.signoff', ['brand' => $brand->name], $locale) }}</p>
</x-mail-layout>
