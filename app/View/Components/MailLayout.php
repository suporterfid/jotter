<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Shared transactional e-mail shell: brand header, body slot, footer links.
 */
final class MailLayout extends Component
{
    public function __construct(
        public string $locale = 'en',
        public ?string $subject = null,
    ) {}

    public function render(): View
    {
        return view('emails.layout');
    }
}
