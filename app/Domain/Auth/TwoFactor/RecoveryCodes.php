<?php

namespace App\Domain\Auth\TwoFactor;

/**
 * One-time recovery codes for when the authenticator is lost. Shown once;
 * only their SHA-256 hashes are kept (in an encrypted column). The codes are
 * random (50 bits each), so a fast hash is enough.
 */
final class RecoveryCodes
{
    public const COUNT = 10;

    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * @return array{0: list<string>, 1: list<string>} [plain codes to show, hashes to store]
     */
    public static function generate(): array
    {
        $plain = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $code = '';
            for ($j = 0; $j < 10; $j++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $plain[] = substr($code, 0, 5).'-'.substr($code, 5);
        }

        return [$plain, array_map(self::hash(...), $plain)];
    }

    /**
     * @param  list<string>  $hashes
     * @return list<string>|null the remaining hashes when $code matched one (now consumed); null otherwise
     */
    public static function consume(array $hashes, string $code): ?array
    {
        $candidate = self::hash($code);
        foreach ($hashes as $index => $hash) {
            if (hash_equals($hash, $candidate)) {
                unset($hashes[$index]);

                return array_values($hashes);
            }
        }

        return null;
    }

    private static function hash(string $code): string
    {
        return hash('sha256', strtolower(preg_replace('/[\s-]+/', '', $code) ?? ''));
    }
}
