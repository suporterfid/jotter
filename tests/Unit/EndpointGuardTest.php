<?php

namespace Tests\Unit;

use App\Domain\Vault\Store\EndpointGuard;
use App\Domain\Vault\Store\UnsafeEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EndpointGuardTest extends TestCase
{
    private function guard(array $allowed = []): EndpointGuard
    {
        return new EndpointGuard(static fn (string $host): array => match ($host) {
            'public.example' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
            'private.example' => ['192.168.1.10'],
            'mixed.example' => ['93.184.216.34', '127.0.0.1'],
            'minio.internal' => ['10.0.0.9'],
            default => [],
        }, $allowed);
    }

    public function test_public_https_endpoint_passes_and_returns_the_pinned_addresses(): void
    {
        $checked = $this->guard()->check('https://public.example:9000/path');

        $this->assertSame('public.example', $checked['host']);
        $this->assertSame(9000, $checked['port']);
        $this->assertSame(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'], $checked['ips']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafe(): iterable
    {
        yield 'plain http' => ['http://public.example'];
        yield 'no scheme' => ['public.example'];
        yield 'loopback v4' => ['https://127.0.0.1'];
        yield 'loopback v6' => ['https://[::1]'];
        yield 'private 10/8' => ['https://10.0.0.1'];
        yield 'private 172.16/12' => ['https://172.20.0.1'];
        yield 'private 192.168/16' => ['https://192.168.0.1'];
        yield 'link-local metadata' => ['https://169.254.169.254'];
        yield 'link-local v6' => ['https://[fe80::1]'];
        yield 'unique local v6' => ['https://[fd00::1]'];
        yield 'cgnat' => ['https://100.64.0.1'];
        yield 'unspecified' => ['https://0.0.0.0'];
        yield 'ipv4-mapped private' => ['https://[::ffff:10.0.0.1]'];
        yield 'dns to private' => ['https://private.example'];
        yield 'dns with one private answer' => ['https://mixed.example'];
        yield 'unresolvable' => ['https://nowhere.example'];
        yield 'userinfo' => ['https://a:b@public.example'];
    }

    #[DataProvider('unsafe')]
    public function test_unsafe_endpoints_are_refused(string $url): void
    {
        $this->expectException(UnsafeEndpoint::class);

        $this->guard()->check($url);
    }

    public function test_operator_allowlist_lets_an_internal_minio_through(): void
    {
        $checked = $this->guard(['minio.internal'])->check('http://minio.internal:9000');

        $this->assertSame(['10.0.0.9'], $checked['ips']);
    }
}
