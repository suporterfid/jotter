<?php

namespace App\Domain\Jobs;

use App\Domain\Jobs\Contracts\JobDispatcher;
use Illuminate\Support\Str;

final class LocalJobDispatcher implements JobDispatcher
{
    public function dispatch(string $jobClass, array $payload, ?int $workspaceId = null): string
    {
        $jobId = (string) Str::uuid();

        // In v0 local dispatcher mode, jobs are logged and executed synchronously or via Artisan commands
        logger()->info("LocalJobDispatcher: Dispatched {$jobClass} [Job ID: {$jobId}]", [
            'workspace_id' => $workspaceId,
            'payload' => $payload,
        ]);

        return $jobId;
    }
}
