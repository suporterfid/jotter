<x-mail-layout :locale="$recipient->locale ?? 'en'" :subject="trans('messages.notification_digest_subject', [], $recipient->locale)">
    <p>{{ trans('messages.notification_email_greeting', ['name' => $recipient->name], $recipient->locale) }}</p>
    <h1 style="font-size:18px;margin:0 0 12px;">{{ trans('messages.notification_digest_heading', [], $recipient->locale) }}</h1>
    <ul>
        @foreach ($notifications as $notification)
            <li>{{ $notification->title }}</li>
        @endforeach
    </ul>
    <p><a href="{{ rtrim((string) config('app.url'), '/') }}/?notification-preferences=1">{{ trans('messages.notification_email_preferences', [], $recipient->locale) }}</a></p>
</x-mail-layout>
