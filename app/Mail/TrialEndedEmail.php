<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class TrialEndedEmail extends BrandedMailable
{
    public function __construct(
        User $recipient,
        public readonly Tenant $tenant,
    ) {
        parent::__construct($recipient);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->translate('emails.trial_ended.subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trial-ended', with: [
            'locale' => $this->locale,
            'subject' => $this->translate('emails.trial_ended.subject'),
            'brand' => $this->brand,
            'recipient' => $this->recipient,
            'tenant' => $this->tenant,
        ]);
    }
}
