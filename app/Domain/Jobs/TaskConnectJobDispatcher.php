<?php

namespace App\Domain\Jobs;

use App\Domain\Jobs\Contracts\JobDispatcher;
use Illuminate\Support\Str;

final class TaskConnectJobDispatcher implements JobDispatcher
{
    public function dispatch(string $jobClass, array $payload, ?int $workspaceId = null): string
    {
        $jobId = (string) Str::uuid();

        logger()->info("TaskConnectJobDispatcher (Stub): Dispatched {$jobClass} [Job ID: {$jobId}] to TaskConnect", [
            'workspace_id' => $workspaceId,
            'payload' => $payload,
        ]);

        return $jobId;
    }
}
