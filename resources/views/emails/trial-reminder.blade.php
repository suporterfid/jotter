<x-mail-layout :locale="$locale" :subject="$subject">
    <p>{{ trans('emails.greeting', ['name' => $recipient->name], $locale) }}</p>
    <p>{{ trans_choice('emails.trial_reminder.body', $daysLeft, ['brand' => $brand->name, 'tenant' => $tenant->name, 'days' => $daysLeft, 'date' => $endsAt], $locale) }}</p>
    <p>{{ trans('emails.trial_reminder.what_happens', [], $locale) }}</p>
    @if ($brand->supportUrl)
        <p><a href="{{ $brand->supportUrl }}">{{ trans('emails.trial_reminder.contact', [], $locale) }}</a></p>
    @endif
    <p>{{ trans('emails.signoff', ['brand' => $brand->name], $locale) }}</p>
</x-mail-layout>
