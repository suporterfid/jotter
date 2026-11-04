<?php

namespace Tests\Unit;

use App\Domain\Auth\CredentialContext;
use App\Domain\Mcp\WritePathPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WritePathPolicyTest extends TestCase
{
    /**
     * @param list<array{glob: string, ops: list<string>}> $writePaths
     */
    private function credential(array $writePaths, ?string $client = 'hermes'): CredentialContext
    {
        return new CredentialContext(1, 1, 'manual', ['mcp:read', 'mcp:write'], null, $writePaths, null, $client, null, false);
    }

    /**
     * @return array<string, array{string, string, string, bool}>
     */
    public static function cases(): array
    {
        return [
            'inbox root file' => ['inbox/{client}/**', 'inbox/hermes/note.md', 'create', true],
            'inbox nested file (** crosses folders)' => ['inbox/{client}/**', 'inbox/hermes/a/b/c.md', 'create', true],
            'other client inbox' => ['inbox/{client}/**', 'inbox/chatgpt/note.md', 'create', false],
            'client prefix is not a match' => ['inbox/{client}/**', 'inbox/hermes-x/note.md', 'create', false],
            'op not granted' => ['inbox/{client}/**', 'inbox/hermes/note.md', 'update', false],
            'single star stays in one folder' => ['diario/*.md', 'diario/2026/11-03.md', 'append', false],
            'single star in folder' => ['diario/*.md', 'diario/2026-11-03.md', 'append', true],
            'question mark is one char' => ['diario/2026-11-0?.md', 'diario/2026-11-03.md', 'append', true],
            '**/ matches zero folders' => ['projetos/**/estado.md', 'projetos/estado.md', 'update', true],
            '**/ matches nested folders' => ['projetos/**/estado.md', 'projetos/x/y/estado.md', 'update', true],
            'non markdown refused' => ['inbox/{client}/**', 'inbox/hermes/script.php', 'create', false],
            'protected folder refused even if a glob matches' => ['_brain/**', '_brain/instructions.md', 'update', false],
            'regex chars are literal' => ['notes/a+b.md', 'notes/aab.md', 'create', false],
            'whole path must match' => ['inbox/{client}/**', 'x/inbox/hermes/n.md', 'create', false],
        ];
    }

    #[DataProvider('cases')]
    public function test_glob_matching(string $glob, string $path, string $op, bool $expected): void
    {
        $ops = $op === 'update' && str_starts_with($glob, 'inbox') ? ['create'] : ['create', 'append', 'update'];
        $policy = new WritePathPolicy;

        $this->assertSame($expected, $policy->allows($this->credential([['glob' => $glob, 'ops' => $ops]]), $path, $op));
    }

    public function test_client_placeholder_without_a_client_slug_matches_nothing(): void
    {
        $credential = $this->credential([['glob' => 'inbox/{client}/**', 'ops' => ['create']]], null);

        $this->assertFalse((new WritePathPolicy)->allows($credential, 'inbox/hermes/note.md', 'create'));
        $this->assertFalse((new WritePathPolicy)->allows($credential, 'inbox//note.md', 'create'));
    }
}
