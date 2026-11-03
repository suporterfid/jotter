<?php

namespace App\Domain\Mcp;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\CredentialContext;

/**
 * MCP tool definitions with their required scope. Every tool publishes
 * `annotations` so clients can tell reads from writes: ChatGPT, for example,
 * only skips the write confirmation for tools marked `readOnlyHint`.
 *
 * Read tools need `mcp:read` (already enforced for every `/api/mcp` call);
 * write tools (brain plan PR 3) declare `mcp:write` and are listed only for
 * credentials holding it.
 */
final class McpToolCatalog
{
    public const READ_ANNOTATIONS = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];

    /**
     * @param list<array<string, mixed>>|null $tools override for tests; each tool may carry `requiredScope`
     */
    public function __construct(private readonly ?array $tools = null) {}

    /**
     * Tools the subject may see, without the internal `requiredScope` key.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleTo(AuthenticatedSubject $subject): array
    {
        $visible = [];
        foreach ($this->all() as $tool) {
            if ($this->allows($subject, $tool)) {
                unset($tool['requiredScope']);
                $visible[] = $tool;
            }
        }

        return $visible;
    }

    /**
     * Scope a tool needs beyond `mcp:read`, or null when the tool is unknown or read-only.
     */
    public function requiredScope(string $name): ?string
    {
        foreach ($this->all() as $tool) {
            if ($tool['name'] === $name) {
                return $tool['requiredScope'] ?? null;
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        foreach ($this->all() as $tool) {
            if ($tool['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    public function subjectHasScope(AuthenticatedSubject $subject, ?string $scope): bool
    {
        if ($scope === null || $scope === CredentialContext::SCOPE_MCP_READ) {
            return true;
        }

        // Only bearer credentials can hold write scopes; browser sessions and
        // GrandpaSSOn service tokens stay read-only on MCP.
        return $subject->credential !== null && $subject->credential->hasScope($scope);
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function allows(AuthenticatedSubject $subject, array $tool): bool
    {
        return $this->subjectHasScope($subject, $tool['requiredScope'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function all(): array
    {
        return $this->tools ?? self::definitions();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $workspaceId = [
            'type' => 'integer',
            'description' => 'Workspace id. Optional when the token can access exactly one workspace; see list_workspaces.',
        ];

        return [
            [
                'name' => 'list_workspaces',
                'title' => 'List workspaces',
                'description' => 'List the workspaces this token can read (id, slug, name).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false],
                'annotations' => self::READ_ANNOTATIONS,
            ],
            [
                'name' => 'list_notes',
                'title' => 'List notes',
                'description' => 'List notes (id, path, title, updated_at) in a workspace, ordered by id.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'workspace_id' => $workspaceId,
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => self::READ_ANNOTATIONS,
            ],
            [
                'name' => 'read_note',
                'title' => 'Read note',
                'description' => 'Read the canonical Markdown of one note by its vault path (for example "guides/setup.md").',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['workspace_id' => $workspaceId, 'path' => ['type' => 'string', 'description' => 'Note path relative to the vault root.']],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
                'annotations' => self::READ_ANNOTATIONS,
            ],
            [
                'name' => 'search_notes',
                'title' => 'Search notes',
                'description' => 'Full-text search over titles and content; returns ranked results with snippets.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['workspace_id' => $workspaceId, 'query' => ['type' => 'string', 'description' => 'Search terms.']],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
                'annotations' => self::READ_ANNOTATIONS,
            ],
            [
                'name' => 'get_backlinks',
                'title' => 'Get backlinks',
                'description' => 'Notes that link to a target note, identified by path or title.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['workspace_id' => $workspaceId, 'target' => ['type' => 'string', 'description' => 'Target note path or title.']],
                    'required' => ['target'],
                    'additionalProperties' => false,
                ],
                'annotations' => self::READ_ANNOTATIONS,
            ],
        ];
    }
}
