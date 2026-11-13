<?php

namespace Tests\Unit;

use App\Domain\Vault\Exceptions\PathTraversalRejected;
use App\Domain\Vault\Store\VaultPathNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VaultPathNormalizerTest extends TestCase
{
    public function test_remote_paths_are_normalized_to_nfc(): void
    {
        $nfd = "Cafe\u{0301}/Nota.md";

        $this->assertSame("Caf\u{00E9}/Nota.md", VaultPathNormalizer::normalize($nfd));
        $this->assertSame($nfd, VaultPathNormalizer::normalize($nfd, nfc: false));
    }

    public function test_root_is_accepted_only_when_allowed(): void
    {
        $this->assertSame('', VaultPathNormalizer::normalize('', allowRoot: true));
        $this->assertSame('folder', VaultPathNormalizer::normalize('folder/', allowRoot: true));

        $this->expectException(PathTraversalRejected::class);
        VaultPathNormalizer::normalize('');
    }

    public function test_a_1024_byte_key_is_accepted(): void
    {
        $key = str_repeat('a', 1021).'.md';

        $this->assertSame($key, VaultPathNormalizer::normalize($key));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'dot-dot' => ['../x.md'];
        yield 'dot' => ['./x.md'];
        yield 'double slash' => ['a//x.md'];
        yield 'absolute' => ['/x.md'];
        yield 'drive' => ['c:x.md'];
        yield 'colon' => ['a/b:c.md'];
        yield 'backslash' => ['a\\x.md'];
        yield 'nul' => ["x\0.md"];
        yield 'git' => ['.git/config'];
        yield 'git uppercase' => ['a/.GIT/x'];
        yield 'too long' => [str_repeat('a', 1025)];
    }

    #[DataProvider('rejected')]
    public function test_unsafe_paths_are_rejected(string $path): void
    {
        $this->expectException(PathTraversalRejected::class);

        VaultPathNormalizer::normalize($path);
    }
}
