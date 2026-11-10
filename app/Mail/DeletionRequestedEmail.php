<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells an administrator that someone asked for their account to be deleted
 * (LGPD art. 18 VI). The privacy policy promises an answer within 30 days.
 */
final class DeletionRequestedEmail extends BrandedMailable
{
    public function __construct(User $administrator, public readonly User $requester)
    {
        parent::__construct($administrator);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->translate('emails.deletion_requested.subject', ['name' => $this->requester->name]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.deletion-requested', with: [
            'locale' => $this->locale,
            'subject' => $this->translate('emails.deletion_requested.subject', ['name' => $this->requester->name]),
            'brand' => $this->brand,
            'recipient' => $this->recipient,
            'requester' => $this->requester,
            'adminUrl' => $this->appUrl().'/',
        ]);
    }
}
