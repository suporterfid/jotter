<x-mail-layout :locale="$locale" :subject="$subject">
    <p>{{ trans('emails.greeting', ['name' => $recipient->name], $locale) }}</p>
    <p>{{ trans('emails.trial_ended.body', ['brand' => $brand->name, 'tenant' => $tenant->name], $locale) }}</p>
    <p>{{ trans('emails.trial_ended.read_only', [], $locale) }}</p>
    @if ($brand->supportUrl)
        <p><a href="{{ $brand->supportUrl }}">{{ trans('emails.trial_ended.contact', [], $locale) }}</a></p>
    @endif
    <p>{{ trans('emails.signoff', ['brand' => $brand->name], $locale) }}</p>
</x-mail-layout>
