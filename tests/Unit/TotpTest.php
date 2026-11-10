<?php

namespace Tests\Unit;

use App\Domain\Auth\TwoFactor\RecoveryCodes;
use App\Domain\Auth\TwoFactor\Totp;
use PHPUnit\Framework\TestCase;

/**
 * Block 4b: TOTP (RFC 6238) and recovery codes.
 */
final class TotpTest extends TestCase
{
    /** RFC 6238 appendix B, SHA-1 seed "12345678901234567890" (6 low digits of the 8-digit values). */
    public function test_rfc_6238_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);

        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'] as $time => $code) {
            $this->assertSame($code, Totp::code($secret, Totp::step($time)), "T={$time}");
        }
    }

    public function test_verify_accepts_one_step_of_drift_and_refuses_replay(): void
    {
        $secret = Totp::generateSecret();
        $this->assertSame(32, strlen($secret));
        $now = 1_800_000_000;
        $step = Totp::step($now);

        $this->assertSame($step, Totp::verify($secret, Totp::code($secret, $step), null, $now));
        $this->assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $step - 1), null, $now));
        $this->assertSame($step + 1, Totp::verify($secret, ' '.Totp::code($secret, $step + 1).' ', null, $now));
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $step - 2), null, $now), 'too old');
        $this->assertNull(Totp::verify($secret, 'abcdef', null, $now));
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $step), $step, $now), 'a used code is refused');
        $this->assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $step + 1), $step, $now));
    }

    public function test_base32_round_trip_and_uri(): void
    {
        $bytes = random_bytes(20);
        $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        $this->assertSame($bytes, Totp::base32Decode(strtolower(Totp::base32Encode($bytes))));

        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'ana@example.com', 'Cadernia Notes');
        $this->assertStringStartsWith('otpauth://totp/Cadernia%20Notes:ana%40example.com?', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=Cadernia%20Notes', $uri);
    }

    public function test_recovery_codes_are_unique_and_work_once(): void
    {
        [$codes, $hashes] = RecoveryCodes::generate();
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        $this->assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $codes[0]);
        $this->assertNotContains($codes[0], $hashes, 'only hashes are stored');

        $remaining = RecoveryCodes::consume($hashes, ' '.strtoupper($codes[3]).' ');
        $this->assertCount(9, $remaining);
        $this->assertNull(RecoveryCodes::consume($remaining, $codes[3]), 'a used code is gone');
        $this->assertNull(RecoveryCodes::consume($hashes, 'aaaaa-aaaaa'));
    }
}
