<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class TrialReminderEmail extends BrandedMailable
{
    public function __construct(
        User $recipient,
        public readonly Tenant $tenant,
        public readonly int $daysLeft,
    ) {
        parent::__construct($recipient);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->translateChoice('emails.trial_reminder.subject', $this->daysLeft, ['days' => $this->daysLeft]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trial-reminder', with: [
            'locale' => $this->locale,
            'subject' => $this->translateChoice('emails.trial_reminder.subject', $this->daysLeft, ['days' => $this->daysLeft]),
            'brand' => $this->brand,
            'recipient' => $this->recipient,
            'tenant' => $this->tenant,
            'daysLeft' => $this->daysLeft,
            'endsAt' => $this->tenant->trial_ends_at?->locale($this->locale)->isoFormat('LL') ?? '',
        ]);
    }
}
