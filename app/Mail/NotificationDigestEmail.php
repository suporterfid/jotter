<?php

namespace App\Mail;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class NotificationDigestEmail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param list<Notification> $notifications */
    public function __construct(
        public readonly User $recipient,
        public readonly array $notifications,
    ) {
        $this->locale = in_array($recipient->locale, ['en', 'pt-BR'], true) ? $recipient->locale : 'en';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('messages.notification_digest_subject', [], $this->locale),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notifications.digest',
            with: [
                'recipient' => $this->recipient,
                'notifications' => $this->notifications,
            ],
        );
    }
}
