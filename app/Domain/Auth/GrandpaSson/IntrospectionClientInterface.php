<?php

namespace App\Domain\Auth\GrandpaSson;

interface IntrospectionClientInterface
{
    public function introspect(string $token): IntrospectionResult;
}
