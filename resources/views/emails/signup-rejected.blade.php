<x-mail-layout :locale="$locale" :subject="$subject">
    <p>{{ trans('emails.greeting', ['name' => $recipient->name], $locale) }}</p>
    <p>{{ trans('emails.signup_rejected.body', ['brand' => $brand->name], $locale) }}</p>
    <p>{{ trans('emails.signoff', ['brand' => $brand->name], $locale) }}</p>
</x-mail-layout>
