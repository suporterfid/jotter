<?php

namespace Tests\Unit;

use App\Domain\Backup\BackupCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * LGPD block 4a: whole-file encryption of backup archives (libsodium secretstream).
 */
final class BackupCipherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/jotter-cipher-'.uniqid('', true);
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function roundTrip(string $plain, BackupCipher $cipher): string
    {
        file_put_contents($this->dir.'/plain', $plain);
        $cipher->encryptFile($this->dir.'/plain', $this->dir.'/enc');
        $cipher->decryptFile($this->dir.'/enc', $this->dir.'/out');

        return (string) file_get_contents($this->dir.'/out');
    }

    public function test_files_of_every_size_round_trip_and_are_marked_encrypted(): void
    {
        $cipher = new BackupCipher(BackupCipher::generateKey());

        foreach (['', 'manifest', random_bytes(1048576), random_bytes(2621440)] as $plain) {
            $this->assertSame($plain, $this->roundTrip($plain, $cipher));
        }

        $this->assertTrue(BackupCipher::isEncrypted($this->dir.'/enc'));
        $this->assertFalse(BackupCipher::isEncrypted($this->dir.'/plain'));
    }

    public function test_the_ciphertext_hides_the_content(): void
    {
        $cipher = new BackupCipher(BackupCipher::generateKey());
        file_put_contents($this->dir.'/plain', str_repeat('projetos/segredo.md ', 100));
        $cipher->encryptFile($this->dir.'/plain', $this->dir.'/enc');

        $this->assertStringNotContainsString('segredo', (string) file_get_contents($this->dir.'/enc'));
    }

    public function test_a_previous_key_still_opens_older_archives(): void
    {
        $old = BackupCipher::generateKey();
        file_put_contents($this->dir.'/plain', 'antigo');
        (new BackupCipher($old))->encryptFile($this->dir.'/plain', $this->dir.'/enc');

        $rotated = new BackupCipher(BackupCipher::generateKey(), [$old]);
        $rotated->decryptFile($this->dir.'/enc', $this->dir.'/out');

        $this->assertSame('antigo', file_get_contents($this->dir.'/out'));
    }

    public function test_the_wrong_key_is_refused_and_leaves_no_output(): void
    {
        file_put_contents($this->dir.'/plain', 'x');
        (new BackupCipher(BackupCipher::generateKey()))->encryptFile($this->dir.'/plain', $this->dir.'/enc');

        try {
            (new BackupCipher(BackupCipher::generateKey()))->decryptFile($this->dir.'/enc', $this->dir.'/out');
            $this->fail('expected a refusal');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No configured key opens this backup', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir.'/out');
    }

    public function test_tampering_and_truncation_are_detected(): void
    {
        $cipher = new BackupCipher(BackupCipher::generateKey());
        file_put_contents($this->dir.'/plain', random_bytes(4096));
        $cipher->encryptFile($this->dir.'/plain', $this->dir.'/enc');
        $bytes = (string) file_get_contents($this->dir.'/enc');

        $tampered = $bytes;
        $tampered[strlen($tampered) - 10] = chr(ord($tampered[strlen($tampered) - 10]) ^ 1);
        file_put_contents($this->dir.'/bad', $tampered);
        try {
            $cipher->decryptFile($this->dir.'/bad', $this->dir.'/out');
            $this->fail('expected an authentication failure');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('authentication failed', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir.'/out');

        file_put_contents($this->dir.'/short', substr($bytes, 0, strlen($bytes) - 20));
        $this->expectExceptionMessage('truncated');
        $cipher->decryptFile($this->dir.'/short', $this->dir.'/out');
    }

    public function test_a_malformed_key_is_refused(): void
    {
        $this->expectExceptionMessage('jotter:backup-key');
        (new BackupCipher('base64:'.base64_encode('short')))->assertUsable();
    }
}
