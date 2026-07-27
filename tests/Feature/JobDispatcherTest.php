<?php

namespace Tests\Feature;

use App\Domain\Jobs\Contracts\JobDispatcher;
use App\Domain\Jobs\LocalJobDispatcher;
use Tests\TestCase;

final class JobDispatcherTest extends TestCase
{
    public function test_local_job_dispatcher_returns_uuid_job_id(): void
    {
        $dispatcher = app(JobDispatcher::class);

        $this->assertInstanceOf(LocalJobDispatcher::class, $dispatcher);

        $jobId = $dispatcher->dispatch('App\Jobs\ReindexVaultJob', ['workspace_id' => 1], 1);

        $this->assertNotEmpty($jobId);
        $this->assertIsString($jobId);
    }
}
