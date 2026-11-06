<?php

namespace App\Domain\OAuth;

/**
 * Resolves a host name to its IP addresses. A seam so tests can stand in for DNS.
 */
interface HostResolver
{
    /**
     * @return list<string> IPv4 and IPv6 addresses; empty when the name does not resolve
     */
    public function resolve(string $host): array;
}
