<x-mail-layout :locale="$locale" :subject="$subject">
    <p>{{ trans('emails.greeting', ['name' => $recipient->name], $locale) }}</p>
    <p>{{ trans('emails.deletion_requested.body', ['name' => $requester->name, 'email' => $requester->email, 'brand' => $brand->name], $locale) }}</p>
    <p>
        <a href="{{ $adminUrl }}" style="display:inline-block;padding:10px 18px;background:#1a6dc1;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;">{{ trans('emails.deletion_requested.button', ['brand' => $brand->name], $locale) }}</a>
    </p>
    <p>{{ trans('emails.deletion_requested.where', [], $locale) }}</p>
    <p>{{ trans('emails.signoff', ['brand' => $brand->name], $locale) }}</p>
</x-mail-layout>
