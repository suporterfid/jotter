<?php

namespace App\Domain\Backup;

use RuntimeException;

/**
 * Encrypts whole backup archives at rest (LGPD art. 46) with libsodium's
 * secretstream (XChaCha20-Poly1305), in 1 MiB chunks so a large archive fits
 * shared-hosting memory limits. Pure PHP (ext-sodium), no shelling out.
 *
 * File layout (`*.zip.enc`):
 *   "JTBKENC1" (8 bytes) | key id (8 bytes) | secretstream header (24 bytes)
 *   then chunks: big-endian uint32 length | ciphertext; the last chunk is
 *   tagged FINAL, so a truncated file is detected.
 *
 * The key id is the first 8 bytes of SHA-256(key): it names which configured
 * key (JOTTER_BACKUP_KEY, or one of JOTTER_BACKUP_PREVIOUS_KEYS after a
 * rotation) opens the file, without revealing the key.
 */
final class BackupCipher
{
    public const MAGIC = 'JTBKENC1';

    public const EXTENSION = '.enc';

    private const CHUNK = 1048576;

    private const KEY_ID_BYTES = 8;

    /**
     * @param  string|null  $key  JOTTER_BACKUP_KEY ("base64:…")
     * @param  list<string>  $previousKeys  JOTTER_BACKUP_PREVIOUS_KEYS, for archives made before a rotation
     */
    public function __construct(
        private readonly ?string $key = null,
        private readonly array $previousKeys = [],
    ) {}

    public static function fromConfig(): self
    {
        $previous = config('jotter.backup.previous_keys', []);

        return new self(
            ((string) config('jotter.backup.key', '')) ?: null,
            array_values(array_filter(array_map('trim', is_array($previous) ? $previous : explode(',', (string) $previous)))),
        );
    }

    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES));
    }

    public function enabled(): bool
    {
        return $this->key !== null && $this->key !== '';
    }

    /**
     * @throws RuntimeException when the configured key is malformed or ext-sodium is missing
     */
    public function assertUsable(): void
    {
        self::assertSodium();
        if ($this->enabled()) {
            self::decodeKey((string) $this->key);
        }
        foreach ($this->previousKeys as $previous) {
            self::decodeKey($previous);
        }
    }

    public static function isEncrypted(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $magic = (string) fread($handle, strlen(self::MAGIC));
        fclose($handle);

        return $magic === self::MAGIC;
    }

    public function encryptFile(string $source, string $target): void
    {
        self::assertSodium();
        if (! $this->enabled()) {
            throw new RuntimeException('JOTTER_BACKUP_KEY is not set.');
        }
        $key = self::decodeKey((string) $this->key);

        $in = self::open($source, 'rb');
        $out = self::open($target, 'wb');
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            fwrite($out, self::MAGIC.self::keyId($key).$header);

            $chunk = (string) fread($in, self::CHUNK);
            do {
                $next = feof($in) ? '' : (string) fread($in, self::CHUNK);
                $final = $next === '' && feof($in);
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $chunk,
                    '',
                    $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                );
                fwrite($out, pack('N', strlen($cipher)).$cipher);
                $chunk = $next;
            } while (! $final);
        } finally {
            fclose($in);
            fclose($out);
            sodium_memzero($key);
        }
        @chmod($target, 0600);
    }

    /**
     * @throws RuntimeException when no configured key opens the file, or it was altered or truncated
     */
    public function decryptFile(string $source, string $target): void
    {
        self::assertSodium();
        $in = self::open($source, 'rb');
        $out = null;
        try {
            if ((string) fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('This file is not an encrypted Jotter backup.');
            }
            $keyId = (string) fread($in, self::KEY_ID_BYTES);
            $key = $this->keyFor($keyId);
            $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            sodium_memzero($key);

            $out = self::open($target, 'wb');
            $finished = false;
            while (! $finished) {
                $length = fread($in, 4);
                if ($length === false || strlen($length) !== 4) {
                    throw new RuntimeException('The encrypted backup is truncated.');
                }
                $size = unpack('N', $length)[1];
                $cipher = $size > 0 ? (string) fread($in, $size) : '';
                if (strlen($cipher) !== $size) {
                    throw new RuntimeException('The encrypted backup is truncated.');
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($result === false) {
                    throw new RuntimeException('The encrypted backup was altered or damaged (authentication failed).');
                }
                [$plain, $tag] = $result;
                fwrite($out, $plain);
                $finished = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (fread($in, 1) !== '') {
                throw new RuntimeException('The encrypted backup has data after its final chunk.');
            }
        } catch (RuntimeException $exception) {
            // Remove the partial output, but only if this call created it.
            if (is_resource($out)) {
                fclose($out);
                $out = null;
                if (is_file($target)) {
                    unlink($target);
                }
            }

            throw $exception;
        } finally {
            fclose($in);
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    private function keyFor(string $keyId): string
    {
        foreach (array_filter([$this->key, ...$this->previousKeys]) as $candidate) {
            $key = self::decodeKey((string) $candidate);
            if (hash_equals(self::keyId($key), $keyId)) {
                return $key;
            }
        }

        throw new RuntimeException(sprintf(
            'No configured key opens this backup (key id %s). Set JOTTER_BACKUP_KEY, or add the old key to JOTTER_BACKUP_PREVIOUS_KEYS.',
            bin2hex($keyId),
        ));
    }

    private static function keyId(string $key): string
    {
        return substr(hash('sha256', $key, true), 0, self::KEY_ID_BYTES);
    }

    private static function decodeKey(string $value): string
    {
        $raw = str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : false;
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('A backup key must be "base64:" followed by 32 random bytes; generate one with php artisan jotter:backup-key.');
        }

        return $raw;
    }

    private static function assertSodium(): void
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw new RuntimeException('Encrypted backups need the PHP sodium extension (ext-sodium).');
        }
    }

    /**
     * @return resource
     */
    private static function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new RuntimeException("Unable to open {$path}.");
        }

        return $handle;
    }
}
