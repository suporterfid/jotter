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
    public const ADDITIVE_WRITE_ANNOTATIONS = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
    public const REPLACING_WRITE_ANNOTATIONS = ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false];
    public const WRITE_TOOLS = ['create_note', 'append_to_note', 'update_note'];

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
                'description' => 'Read the canonical Markdown of one note by its vault path (for example "guides/setup.md"). structuredContent carries the sha256 that update_note needs.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['workspace_id' => $workspaceId, 'path' => ['type' => 'string', 'description' => 'Note path relative to the vault root.']],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
                'outputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'sha256' => ['type' => 'string', 'description' => 'SHA-256 of the file on disk; pass it to update_note as expected_sha256.'],
                        'updated_at' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['path', 'sha256'],
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
                'name' => 'semantic_search',
                'title' => 'Semantic search',
                'description' => 'Search notes by meaning rather than exact words (for questions such as "what did we decide about billing?"). Returns the best matching notes with the matching passage and a score. When semantic search is not available for the workspace it falls back to full-text search and says so in "mode" and "reason".',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'workspace_id' => $workspaceId,
                        'query' => ['type' => 'string', 'description' => 'A question or description of what you are looking for.'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'description' => 'Results to return (default 10).'],
                    ],
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
            [
                'name' => 'get_brain_context',
                'title' => 'Get brain context',
                'description' => 'Start here. One call returns the vault router (_brain/README.md), the agent rules (_brain/instructions.md), the state of every project (front matter of projetos/*/estado.md: status, proximo_passo, atualizado, dono) and the 10 latest changes, within 24 KB.',
                'inputSchema' => ['type' => 'object', 'properties' => ['workspace_id' => $workspaceId], 'additionalProperties' => false],
                'annotations' => self::READ_ANNOTATIONS,
            ],
            [
                'name' => 'list_recent_changes',
                'title' => 'List recent changes',
                'description' => 'Latest note revisions, newest first: path, updated_at, actor and, for agent writes, the client.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'workspace_id' => $workspaceId,
                        'since' => ['type' => 'string', 'description' => 'ISO 8601 date-time; only changes at or after it.'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => self::READ_ANNOTATIONS,
            ],
            [
                'name' => 'get_outgoing_links',
                'title' => 'Get outgoing links',
                'description' => 'Wikilinks from one note (by path), resolved or not; links to notes you cannot see are omitted.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['workspace_id' => $workspaceId, 'path' => ['type' => 'string', 'description' => 'Note path relative to the vault root.']],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
                'annotations' => self::READ_ANNOTATIONS,
            ],
            [
                'name' => 'search',
                'title' => 'Search',
                'description' => 'Full-text search across every workspace you can read. Returns up to 20 results as {id, title, url}; pass an id to fetch.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string', 'description' => 'Search terms.']],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
                'annotations' => self::READ_ANNOTATIONS,
            ],
            [
                'name' => 'fetch',
                'title' => 'Fetch',
                'description' => 'Full text of one note by the id returned by search ("<workspace_id>:<path>"): {id, title, text, url, metadata}.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'string', 'description' => 'Result id from search.']],
                    'required' => ['id'],
                    'additionalProperties' => false,
                ],
                'annotations' => self::READ_ANNOTATIONS,
            ],
            ...self::writeDefinitions($workspaceId),
        ];
    }

    /**
     * @param array<string, string> $workspaceId
     * @return list<array<string, mixed>>
     */
    private static function writeDefinitions(array $workspaceId): array
    {
        $path = ['type' => 'string', 'description' => 'Note path relative to the vault root, ending in .md. Must match one of this token\'s write paths.'];
        $content = ['type' => 'string', 'description' => 'Markdown, at most 256 KB.'];
        $writeOutput = [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['written', 'duplicate']],
                'created' => ['type' => 'boolean'],
                'path' => ['type' => 'string'],
                'sha256' => ['type' => ['string', 'null']],
                'bytes' => ['type' => 'integer'],
            ],
            'required' => ['status', 'path'],
        ];

        return [
            [
                'name' => 'create_note',
                'title' => 'Create note',
                'description' => 'Create a new note. With if_exists="suffix" an existing path becomes name-2.md, name-3.md… instead of failing. Only inside the token\'s write paths.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'workspace_id' => $workspaceId,
                        'path' => $path,
                        'content' => $content,
                        'if_exists' => ['type' => 'string', 'enum' => ['fail', 'suffix'], 'default' => 'fail'],
                    ],
                    'required' => ['path', 'content'],
                    'additionalProperties' => false,
                ],
                'outputSchema' => $writeOutput,
                'annotations' => self::ADDITIVE_WRITE_ANNOTATIONS,
                'requiredScope' => CredentialContext::SCOPE_MCP_WRITE,
            ],
            [
                'name' => 'append_to_note',
                'title' => 'Append to note',
                'description' => 'Append Markdown at the end of a note, or at the end of the section under `heading` (created when missing). Creates the note when it does not exist. Repeating the same idempotency_key within 24 hours does nothing.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'workspace_id' => $workspaceId,
                        'path' => $path,
                        'content' => $content,
                        'heading' => ['type' => 'string', 'description' => 'Heading text (without #) whose section receives the content.'],
                        'idempotency_key' => ['type' => 'string', 'maxLength' => 200],
                    ],
                    'required' => ['path', 'content'],
                    'additionalProperties' => false,
                ],
                'outputSchema' => $writeOutput,
                'annotations' => self::ADDITIVE_WRITE_ANNOTATIONS,
                'requiredScope' => CredentialContext::SCOPE_MCP_WRITE,
            ],
            [
                'name' => 'update_note',
                'title' => 'Replace note',
                'description' => 'Replace a note\'s whole content, only if its current SHA-256 still equals expected_sha256 (from read_note). On a mismatch nothing is written and the current hash is returned.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'workspace_id' => $workspaceId,
                        'path' => $path,
                        'content' => $content,
                        'expected_sha256' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
                    ],
                    'required' => ['path', 'content', 'expected_sha256'],
                    'additionalProperties' => false,
                ],
                'outputSchema' => $writeOutput,
                'annotations' => self::REPLACING_WRITE_ANNOTATIONS,
                'requiredScope' => CredentialContext::SCOPE_MCP_WRITE,
            ],
        ];
    }
}
