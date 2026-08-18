<!doctype html>
<html lang="{{ $notification->user?->locale ?? 'en' }}">
<body>
    <p>{{ trans('messages.notification_email_greeting', ['name' => $recipient->name], $recipient->locale) }}</p>
    <p>{{ $notification->title }}</p>
    @if (!empty($notification->data['comment_snippet']))
        <blockquote>{{ $notification->data['comment_snippet'] }}</blockquote>
    @endif
    <p><a href="{{ rtrim((string) config('app.url'), '/') }}">{{ trans('messages.notification_email_open', [], $recipient->locale) }}</a></p>
    <p><a href="{{ $unsubscribeUrl }}">{{ trans('messages.notification_email_unsubscribe', [], $recipient->locale) }}</a></p>
</body>
</html>
