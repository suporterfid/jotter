<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private ?string $erasureLogPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Block 4c: every test gets its own erasure log, so deletions in one test
        // never become tombstones that a restore in another test would replay.
        $this->erasureLogPath = storage_path('framework/testing/erasure-log-'.bin2hex(random_bytes(6)).'.ndjson');
        config(['jotter.erasure_log.path' => $this->erasureLogPath]);
    }

    protected function tearDown(): void
    {
        if ($this->erasureLogPath !== null && is_file($this->erasureLogPath)) {
            unlink($this->erasureLogPath);
        }

        parent::tearDown();
    }
}
