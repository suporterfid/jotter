<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the person their request was not approved; the request is then deleted (#390).
 */
final class SignupRejectedEmail extends BrandedMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->translate('emails.signup_rejected.subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.signup-rejected', with: [
            'locale' => $this->locale,
            'subject' => $this->translate('emails.signup_rejected.subject'),
            'brand' => $this->brand,
            'recipient' => $this->recipient,
            'loginUrl' => $this->appUrl().'/',
        ]);
    }
}
