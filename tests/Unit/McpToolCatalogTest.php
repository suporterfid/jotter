<?php

namespace Tests\Unit;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\CredentialContext;
use App\Domain\Mcp\McpToolCatalog;
use PHPUnit\Framework\TestCase;

final class McpToolCatalogTest extends TestCase
{
    /**
     * @param list<string> $scopes
     */
    private function credentialSubject(array $scopes): AuthenticatedSubject
    {
        return new AuthenticatedSubject(
            subjectId: '1',
            email: 'agent@example.test',
            name: 'Agent',
            credential: new CredentialContext(1, 1, 'manual', $scopes, null, [], null, null, null, false),
        );
    }

    private function catalog(): McpToolCatalog
    {
        return new McpToolCatalog([
            ['name' => 'read_note', 'title' => 'Read', 'annotations' => McpToolCatalog::READ_ANNOTATIONS],
            ['name' => 'update_note', 'title' => 'Update', 'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true], 'requiredScope' => 'mcp:write'],
        ]);
    }

    public function test_write_tools_are_listed_only_with_mcp_write(): void
    {
        $this->assertSame(['read_note'], array_column($this->catalog()->visibleTo($this->credentialSubject(['mcp:read'])), 'name'));
        $this->assertSame(['read_note', 'update_note'], array_column($this->catalog()->visibleTo($this->credentialSubject(['mcp:read', 'mcp:write'])), 'name'));
    }

    public function test_sessions_never_see_write_tools(): void
    {
        $session = new AuthenticatedSubject(subjectId: '1', email: 'person@example.test', name: 'Person', isAdmin: true);

        $this->assertSame(['read_note'], array_column($this->catalog()->visibleTo($session), 'name'));
        $this->assertFalse($this->catalog()->subjectHasScope($session, 'mcp:write'));
    }

    public function test_required_scope_lookup_and_internal_key_is_stripped(): void
    {
        $catalog = $this->catalog();

        $this->assertSame('mcp:write', $catalog->requiredScope('update_note'));
        $this->assertNull($catalog->requiredScope('read_note'));
        $this->assertTrue($catalog->has('update_note'));
        $this->assertFalse($catalog->has('delete_note'));
        foreach ($catalog->visibleTo($this->credentialSubject(['mcp:read', 'mcp:write'])) as $tool) {
            $this->assertArrayNotHasKey('requiredScope', $tool);
        }
    }

    public function test_every_shipped_tool_is_read_only_and_annotated(): void
    {
        foreach (McpToolCatalog::definitions() as $tool) {
            $this->assertArrayNotHasKey('requiredScope', $tool, $tool['name']);
            $this->assertSame(McpToolCatalog::READ_ANNOTATIONS, $tool['annotations'], $tool['name']);
        }
    }
}
