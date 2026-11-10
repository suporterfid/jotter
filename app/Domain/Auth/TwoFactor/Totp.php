<?php

namespace App\Domain\Auth\TwoFactor;

/**
 * Time-based one-time passwords (RFC 6238 over RFC 4226 HOTP): HMAC-SHA1,
 * 30-second steps, 6 digits, the parameters every authenticator app uses.
 * Pure PHP, no dependency.
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    /** Steps accepted on each side of now, for clock drift (±30 s). */
    public const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A 160-bit secret, Base32 without padding (32 characters). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function step(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD);
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The matching step when $code is valid within the window and newer than
     * $lastUsedStep (a code is accepted once: no replay); null otherwise.
     */
    public static function verify(string $secret, string $code, ?int $lastUsedStep = null, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return null;
        }

        $now = self::step($timestamp);
        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; $step++) {
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** The otpauth:// URI that authenticator apps read from the QR code. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=-]+/', '', $text) ?? '');
        $bits = '';
        foreach (str_split($text) as $char) {
            $index = strpos(self::BASE32, $char);
            if ($index === false) {
                throw new \InvalidArgumentException('Invalid Base32 secret.');
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
