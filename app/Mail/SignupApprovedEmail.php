<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the person their account was approved and they can sign in (#390).
 */
final class SignupApprovedEmail extends BrandedMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->translate('emails.signup_approved.subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.signup-approved', with: [
            'locale' => $this->locale,
            'subject' => $this->translate('emails.signup_approved.subject'),
            'brand' => $this->brand,
            'recipient' => $this->recipient,
            'loginUrl' => $this->appUrl().'/',
        ]);
    }
}
