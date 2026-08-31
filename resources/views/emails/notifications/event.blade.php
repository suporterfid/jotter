<x-mail-layout :locale="$recipient->locale ?? 'en'" :subject="trans('messages.notification_email_subject', ['title' => $notification->title], $recipient->locale)">
    <p>{{ trans('messages.notification_email_greeting', ['name' => $recipient->name], $recipient->locale) }}</p>
    <p>{{ $notification->title }}</p>
    @if (!empty($notification->data['comment_snippet']))
        <blockquote style="margin:0 0 16px;padding:8px 14px;border-left:3px solid #d9d7d3;color:#5f5f5f;">{{ $notification->data['comment_snippet'] }}</blockquote>
    @endif
    <p><a href="{{ rtrim((string) config('app.url'), '/') }}">{{ trans('messages.notification_email_open', [], $recipient->locale) }}</a></p>
    <p><a href="{{ $unsubscribeUrl }}">{{ trans('messages.notification_email_unsubscribe', [], $recipient->locale) }}</a></p>
</x-mail-layout>
