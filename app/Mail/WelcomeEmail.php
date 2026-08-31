<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent once by `tenant:provision`. Never carries the password: that is printed
 * a single time on the operator's terminal.
 */
final class WelcomeEmail extends BrandedMailable
{
    public const MCP_GUIDE_URL = 'https://github.com/suporterfid/jotter/blob/main/docs/mcp.md';

    public function __construct(
        User $recipient,
        public readonly Workspace $workspace,
        public readonly ?int $trialDays = null,
    ) {
        parent::__construct($recipient);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->translate('emails.welcome.subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.welcome', with: [
            'locale' => $this->locale,
            'subject' => $this->translate('emails.welcome.subject'),
            'brand' => $this->brand,
            'recipient' => $this->recipient,
            'workspace' => $this->workspace,
            'loginUrl' => $this->appUrl().'/',
            'webdavUrl' => $this->appUrl().'/api/webdav/'.$this->workspace->id,
            'mcpGuideUrl' => self::MCP_GUIDE_URL,
            'trialDays' => $this->trialDays,
        ]);
    }
}
