<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use ZipArchive;

#[Group('release')]
class ReleaseZipSecurityTest extends TestCase
{
    public function test_release_zip_contains_no_secrets_or_private_keys(): void
    {
        $path = getenv('JOTTER_RELEASE_ZIP') ?: base_path('dist/jotter-release.zip');

        if (! is_file($path)) {
            $this->markTestSkipped('Set JOTTER_RELEASE_ZIP to inspect a built release artifact.');
        }

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path), "Unable to open release zip: {$path}");

        $violations = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));

            if ($this->isForbiddenPath($name)) {
                $violations[] = "forbidden path: {$name}";
                continue;
            }

            $contents = $zip->getFromIndex($index);
            if (! is_string($contents) || str_contains($contents, "\0")) {
                continue;
            }

            if (preg_match('/-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----/', $contents) === 1) {
                $violations[] = "private key material: {$name}";
            }

            if (str_starts_with($name, 'app/vendor/')) {
                continue;
            }

            foreach (preg_split('/\R/', $contents) ?: [] as $lineNumber => $line) {
                if ($this->containsCredentialLiteral($line)) {
                    $violations[] = sprintf('credential-like literal: %s:%d', $name, $lineNumber + 1);
                }
            }
        }

        $zip->close();

        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    private function isForbiddenPath(string $name): bool
    {
        if (preg_match('#(^|/)\.env(?:\..+)?$#i', $name) === 1 && ! str_ends_with($name, '/.env.example')) {
            return true;
        }

        return preg_match(
            '#(^|/)(?:id_(?:rsa|dsa|ecdsa|ed25519)|[^/]+\.(?:pem|p12|pfx|key))$#i',
            $name,
        ) === 1;
    }

    private function containsCredentialLiteral(string $line): bool
    {
        $credentialName = '(?:PASSWORD|PASSWD|SECRET|TOKEN|API_KEY|PRIVATE_KEY|CLIENT_SECRET|CREDENTIALS?)';

        if (preg_match('/^\s*'.$credentialName.'\s*=\s*(.*?)\s*$/i', $line, $matches) === 1) {
            return trim($matches[1], " \t\"'") !== '';
        }

        if (preg_match('/["\']'.$credentialName.'["\']\s*(?:=>|[:=])\s*["\']([^"\']+)["\']/i', $line, $matches) === 1) {
            $literal = trim($matches[1]);

            return $literal !== '' && strtolower($literal) !== 'hashed';
        }

        if (preg_match('/(?:\$|const\s+|let\s+|var\s+)?'.$credentialName.'\s*=\s*["\']([^"\']+)["\']/i', $line, $matches) === 1) {
            return trim($matches[1]) !== '';
        }

        return false;
    }
}
