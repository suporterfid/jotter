<?php

namespace App\Mail;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class NotificationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $recipient,
        public readonly Notification $notification,
    ) {
        $this->locale = in_array($recipient->locale, ['en', 'pt-BR'], true) ? $recipient->locale : 'en';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('messages.notification_email_subject', ['title' => $this->notification->title], $this->locale),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notifications.event',
            with: [
                'recipient' => $this->recipient,
                'notification' => $this->notification,
                'unsubscribeUrl' => $this->unsubscribeUrl(),
            ],
        );
    }

    private function unsubscribeUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/?notification-preferences=1&unsubscribe='.$this->notification->type;
    }
}
