<?php

namespace Tests\Support;

use App\Domain\Auth\GrandpaSson\IntrospectionClientInterface;
use App\Domain\Auth\GrandpaSson\IntrospectionResult;

final class FakeIntrospectionClient implements IntrospectionClientInterface
{
    public function __construct(private readonly IntrospectionResult $result)
    {
    }

    public function introspect(string $token): IntrospectionResult
    {
        return $this->result;
    }
}
