<?php

namespace App\Domain\Vault;

/**
 * Where an import archive came from. Controls path normalization before the
 * generic zip-slip / extension / size guards run.
 */
enum ImportSource: string
{
    case GENERIC = 'generic';
    case OBSIDIAN = 'obsidian';
    case NOTION = 'notion';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }

    public static function fromInput(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::GENERIC;
    }
}
