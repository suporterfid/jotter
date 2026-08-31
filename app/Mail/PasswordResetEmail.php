<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent when an administrator resets a user's password. Deliberately contains no
 * password: the administrator hands it over out of band.
 */
final class PasswordResetEmail extends BrandedMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->translate('emails.password_reset.subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset', with: [
            'locale' => $this->locale,
            'subject' => $this->translate('emails.password_reset.subject'),
            'brand' => $this->brand,
            'recipient' => $this->recipient,
            'loginUrl' => $this->appUrl().'/',
        ]);
    }
}
