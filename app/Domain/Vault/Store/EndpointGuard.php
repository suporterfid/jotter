<?php

namespace App\Domain\Vault\Store;

use Closure;

/**
 * SSRF guard for endpoints a tenant types in (S3-compatible URLs). The URL
 * must be HTTPS, and every address its host resolves to must be public:
 * private, loopback, link-local, CGNAT, unspecified, multicast and reserved
 * ranges (IPv4 and IPv6, including IPv4-mapped IPv6) are refused. The
 * operator can let named hosts through (`JOTTER_VAULT_ALLOWED_PRIVATE_HOSTS`,
 * e.g. an internal MinIO).
 *
 * check() returns the addresses it validated; drivers pin the connection to
 * them (cURL CURLOPT_RESOLVE) so DNS cannot change between check and use.
 */
final class EndpointGuard
{
    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  host => IP list (tests inject one)
     * @param  list<string>|null  $allowedPrivateHosts
     */
    public function __construct(?Closure $resolver = null, private readonly ?array $allowedPrivateHosts = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = [];
            foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
                if (isset($record['ip'])) {
                    $ips[] = (string) $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }

            if ($ips === []) {
                $ips = (array) (@gethostbynamel($host) ?: []);
            }

            return array_values(array_unique($ips));
        };
    }

    /**
     * @return array{host: string, port: int, ips: list<string>}
     *
     * @throws UnsafeEndpoint
     */
    public function check(string $url): array
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeEndpoint('The endpoint must be a plain https:// URL with a host name.');
        }

        $allowlisted = in_array($host, $this->allowedHosts(), true);
        if ($scheme !== 'https' && ! ($allowlisted && $scheme === 'http')) {
            throw new UnsafeEndpoint('The endpoint must use https://.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            throw new UnsafeEndpoint("The endpoint host [{$host}] does not resolve.");
        }

        if (! $allowlisted) {
            foreach ($ips as $ip) {
                if (! self::isPublicIp($ip)) {
                    throw new UnsafeEndpoint("The endpoint host [{$host}] resolves to a private or reserved address.");
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'ips' => array_values($ips)];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // IPv4-mapped / -compatible IPv6 (::ffff:10.0.0.1): judge the embedded IPv4.
        if (str_contains($ip, ':')) {
            $packed = inet_pton($ip);
            if ($packed !== false && strlen($packed) === 16
                && (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff") || str_starts_with($packed, str_repeat("\0", 12)))
                && substr($packed, 12) !== "\0\0\0\0" && substr($packed, 12) !== "\0\0\0\1") {
                return self::isPublicIp((string) inet_ntop(substr($packed, 12)));
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $blocked = [
            '0.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24',
            '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
            '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '64:ff9b::/96', '2001:db8::/32',
        ];
        foreach ($blocked as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        return $this->allowedPrivateHosts ?? (array) config('jotter.vault_stores.allowed_private_hosts', []);
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
