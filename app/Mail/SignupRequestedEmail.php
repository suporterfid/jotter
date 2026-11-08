<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells an administrator that someone asked for an account (#390).
 */
final class SignupRequestedEmail extends BrandedMailable
{
    public function __construct(User $administrator, public readonly User $applicant)
    {
        parent::__construct($administrator);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->translate('emails.signup_requested.subject', ['name' => $this->applicant->name]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.signup-requested', with: [
            'locale' => $this->locale,
            'subject' => $this->translate('emails.signup_requested.subject', ['name' => $this->applicant->name]),
            'brand' => $this->brand,
            'recipient' => $this->recipient,
            'applicant' => $this->applicant,
            'adminUrl' => $this->appUrl().'/',
        ]);
    }
}
