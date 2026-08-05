<?php

namespace Tests\Unit\GrandpaSson;

use App\Domain\Auth\GrandpaSson\IntrospectionResult;
use PHPUnit\Framework\TestCase;

final class IntrospectionResultTest extends TestCase
{
    public function test_has_scope_checks_the_scopes_list(): void
    {
        $result = new IntrospectionResult(active: true, scopes: ['kb:read', 'kb:write']);

        $this->assertTrue($result->hasScope('kb:read'));
        $this->assertFalse($result->hasScope('kb:delete'));
    }

    public function test_audience_includes_workspace_matches_the_literal_convention(): void
    {
        $result = new IntrospectionResult(active: true, audiences: ['workspace/7']);

        $this->assertTrue($result->audienceIncludesWorkspace(7));
        $this->assertFalse($result->audienceIncludesWorkspace(8));
    }

    public function test_audience_includes_workspace_is_false_for_empty_audiences(): void
    {
        $result = new IntrospectionResult(active: false);

        $this->assertFalse($result->audienceIncludesWorkspace(7));
    }
}
