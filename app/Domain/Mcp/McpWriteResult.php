<?php

namespace App\Domain\Mcp;

/**
 * Outcome of an MCP write tool: either data for the client, or an error the
 * model can read and recover from (`isError`), optionally rate-limited.
 */
final class McpWriteResult
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $data,
        public readonly ?string $error = null,
        public readonly ?int $retryAfter = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function ok(array $data): self
    {
        return new self(true, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function error(string $message, array $data = []): self
    {
        return new self(false, $data, $message);
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(false, ['retry_after' => $retryAfter], 'Write rate limit exceeded; retry after '.$retryAfter.' seconds.', $retryAfter);
    }
}
