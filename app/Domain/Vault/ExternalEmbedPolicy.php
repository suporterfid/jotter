<?php

namespace App\Domain\Vault;

final class ExternalEmbedPolicy
{
    /**
     * @param list<string> $allowedHosts
     */
    public function __construct(private readonly array $allowedHosts)
    {
    }

    public static function configured(): self
    {
        $configuredHosts = config('jotter.external_embed_domains', []);
        $hosts = is_array($configuredHosts) ? $configuredHosts : explode(',', (string) $configuredHosts);

        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $host): string => strtolower(trim((string) $host)),
            $hosts,
        ), static fn (string $host): bool => $host !== '')));

        return new self($normalized);
    }

    /**
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        return $this->allowedHosts;
    }

    public function isAllowed(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! empty($parts['user'])
            || ! empty($parts['pass'])
            || empty($parts['host'])) {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        foreach ($this->allowedHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }
}
