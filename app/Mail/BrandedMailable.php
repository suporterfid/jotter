<?php

namespace App\Mail;

use App\Models\User;
use App\Support\Branding;
use Illuminate\Mail\Mailable;

/**
 * Base for transactional mail: resolves the recipient locale (en / pt-BR) and
 * the operator branding once so every template shares the same shell.
 */
abstract class BrandedMailable extends Mailable
{
    public readonly Branding $brand;

    public function __construct(public readonly User $recipient)
    {
        $this->brand = Branding::configured();
        $this->locale = in_array($recipient->locale, ['en', 'pt-BR'], true) ? $recipient->locale : 'en';
    }

    protected function appUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    protected function translate(string $key, array $replace = []): string
    {
        return trans($key, $replace + ['brand' => $this->brand->name], $this->locale);
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    protected function translateChoice(string $key, int $number, array $replace = []): string
    {
        return trans_choice($key, $number, $replace + ['brand' => $this->brand->name], $this->locale);
    }
}
