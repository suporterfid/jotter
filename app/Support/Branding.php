<?php

namespace App\Support;

/**
 * Operator-facing branding for one installation. Every value has a default that
 * leaves a self-hosted Jotter exactly as before; a hosted operator overrides them
 * through environment variables only — never by forking the engine.
 */
final class Branding
{
    public const REPOSITORY_URL = 'https://github.com/suporterfid/jotter';

    public function __construct(
        public readonly string $name,
        public readonly ?string $logoUrl,
        public readonly ?string $supportUrl,
        public readonly ?string $termsUrl,
        public readonly ?string $privacyUrl,
        public readonly bool $poweredBy,
    ) {}

    public static function configured(): self
    {
        $brand = (array) config('jotter.brand', []);

        return new self(
            name: self::stringOrNull($brand['name'] ?? null) ?? (string) config('app.name', 'Jotter'),
            logoUrl: self::stringOrNull($brand['logo_url'] ?? null),
            supportUrl: self::stringOrNull($brand['support_url'] ?? null),
            termsUrl: self::stringOrNull($brand['terms_url'] ?? null),
            privacyUrl: self::stringOrNull($brand['privacy_url'] ?? null),
            poweredBy: (bool) ($brand['powered_by'] ?? true),
        );
    }

    /**
     * @return array{name: string, logo_url: ?string, support_url: ?string, terms_url: ?string, privacy_url: ?string, powered_by: bool, powered_by_url: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'logo_url' => $this->logoUrl,
            'support_url' => $this->supportUrl,
            'terms_url' => $this->termsUrl,
            'privacy_url' => $this->privacyUrl,
            'powered_by' => $this->poweredBy,
            'powered_by_url' => self::REPOSITORY_URL,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
