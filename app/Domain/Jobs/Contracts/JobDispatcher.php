<?php

namespace App\Domain\Jobs\Contracts;

interface JobDispatcher
{
    /**
     * Dispatch a background job payload with workspace context.
     *
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $jobClass, array $payload, ?int $workspaceId = null): string;
}
