<?php

namespace App\Domain\OAuth;

/**
 * Redirect URI rules for the embedded OAuth server.
 *
 * - Registration (DCR): every URI must be https, or http on a loopback host,
 *   carry no fragment, and match one pattern of `jotter.oauth.redirect_allowlist`
 *   (`*` in the port position matches any port, `*` in the path matches anything).
 * - Authorization: the requested URI must equal a registered one exactly, except
 *   that loopback URIs match on any port (RFC 8252 §7.3).
 */
final class RedirectUriPolicy
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];

    /**
     * @param list<string>|null $allowlist
     */
    public function __construct(private readonly ?array $allowlist = null) {}

    public function allowedForRegistration(string $uri): bool
    {
        $parts = $this->parse($uri);
        if ($parts === null) {
            return false;
        }

        if ($parts['scheme'] !== 'https' && ! ($parts['scheme'] === 'http' && $this->isLoopback($parts['host']))) {
            return false;
        }

        foreach ($this->allowlist ?? (array) config('jotter.oauth.redirect_allowlist', []) as $pattern) {
            if ($this->matchesPattern($parts, (string) $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $registered
     */
    public function matchesRegistered(string $requested, array $registered): bool
    {
        if (in_array($requested, $registered, true)) {
            return true;
        }

        $request = $this->parse($requested);
        if ($request === null || ! $this->isLoopback($request['host'])) {
            return false;
        }

        foreach ($registered as $uri) {
            $candidate = $this->parse((string) $uri);
            if ($candidate !== null
                && $candidate['scheme'] === $request['scheme']
                && $candidate['host'] === $request['host']
                && $candidate['path'] === $request['path']
                && $candidate['query'] === $request['query']) {
                return true;
            }
        }

        return false;
    }

    public function isLoopback(string $host): bool
    {
        return in_array(strtolower($host), self::LOOPBACK_HOSTS, true);
    }

    public function isLoopbackUri(string $uri): bool
    {
        $parts = $this->parse($uri);

        return $parts !== null && $this->isLoopback($parts['host']);
    }

    /**
     * @param array{scheme: string, host: string, port: int|null, path: string, query: string} $parts
     */
    private function matchesPattern(array $parts, string $pattern): bool
    {
        if (preg_match('#^(https?)://([^/:]+|\[[^\]]+\])(?::(\*|\d+))?(/.*)?$#i', trim($pattern), $m) !== 1) {
            return false;
        }

        [$scheme, $host, $port, $path] = [strtolower($m[1]), strtolower($m[2]), $m[3] ?? '', $m[4] ?? '/'];
        if ($scheme !== $parts['scheme'] || $host !== $parts['host']) {
            return false;
        }

        if ($port !== '*') {
            $expected = $port === '' ? null : (int) $port;
            if ($expected !== $parts['port']) {
                return false;
            }
        }

        $regex = '#\A'.str_replace('\*', '.*', preg_quote($path, '#')).'\z#';
        $target = $parts['path'].($parts['query'] !== '' ? '?'.$parts['query'] : '');

        return preg_match($regex, $target) === 1;
    }

    /**
     * @return array{scheme: string, host: string, port: int|null, path: string, query: string}|null
     */
    private function parse(string $uri): ?array
    {
        if ($uri === '' || strlen($uri) > 2000 || str_contains($uri, '#') || preg_match('/\s/', $uri) === 1) {
            return null;
        }

        $parts = parse_url($uri);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return [
            'scheme' => strtolower($parts['scheme']),
            'host' => strtolower($parts['host']),
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            'path' => $parts['path'] ?? '/',
            'query' => $parts['query'] ?? '',
        ];
    }
}
