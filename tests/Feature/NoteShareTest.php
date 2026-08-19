<?php

namespace Tests\Feature;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Sharing\NoteShareService;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class NoteShareTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $vaultDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->vaultDirectories as $directory) {
            if (is_dir($directory)) {
                $this->removeDirectory($directory);
            }
        }

        parent::tearDown();
    }

    public function test_creation_persists_only_a_hash_and_returns_a_plaintext_token_once(): void
    {
        $note = $this->noteFixture();
        $subject = $this->adminSubject();

        ['share' => $share, 'token' => $token] = app(NoteShareService::class)
            ->create($note, $subject, now()->addDay()->toImmutable());

        $this->assertNotSame('', $token);
        $this->assertNotSame($token, $share->token_hash);
        $this->assertSame(hash('sha256', $token), $share->token_hash);
        $this->assertSame($note->id, $share->note_id);
        $this->assertSame($share->token_hash, $share->fresh()->token_hash);
        $this->assertNotNull($share->expires_at);
    }

    public function test_active_lookup_rejects_expired_and_revoked_tokens(): void
    {
        $note = $this->noteFixture();
        $subject = $this->adminSubject();
        $service = app(NoteShareService::class);

        ['token' => $expiredToken] = $service->create($note, $subject, now()->subMinute()->toImmutable());
        ['share' => $revoked, 'token' => $revokedToken] = $service->create($note, $subject, null);
        $service->revoke($revoked, $subject);

        $this->assertNull($service->activeForToken($expiredToken));
        $this->assertNull($service->activeForToken($revokedToken));
    }

    public function test_active_lookup_returns_a_share_without_exposing_the_plaintext_token(): void
    {
        $note = $this->noteFixture();
        $subject = $this->adminSubject();
        $service = app(NoteShareService::class);
        ['share' => $created, 'token' => $token] = $service->create($note, $subject, Carbon::now()->addHour()->toImmutable());

        $resolved = $service->activeForToken($token);

        $this->assertNotNull($resolved);
        $this->assertSame($created->id, $resolved->id);
        $this->assertArrayNotHasKey('token', $resolved->getAttributes());
        $this->assertTrue($resolved->isActive());
    }

    private function noteFixture(): Note
    {
        $tenant = Tenant::create(['slug' => 'tenant-'.bin2hex(random_bytes(3)), 'name' => 'Tenant']);
        $workspaceSlug = 'workspace-'.bin2hex(random_bytes(3));
        $vaultDirectory = storage_path('app/vaults/'.$workspaceSlug);
        mkdir($vaultDirectory, 0755, true);
        $this->vaultDirectories[] = $vaultDirectory;
        file_put_contents($vaultDirectory.'/note.md', '# Shared note');

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => $workspaceSlug,
            'name' => 'Workspace',
            'vault_path' => $vaultDirectory,
        ]);

        return Note::create([
            'workspace_id' => $workspace->id,
            'path' => 'note.md',
            'title' => 'Shared note',
            'frontmatter' => [],
            'content_hash' => hash('sha256', '# Shared note'),
            'search_content' => '# Shared note',
        ]);
    }

    private function adminSubject(): AuthenticatedSubject
    {
        $user = User::factory()->create(['is_admin' => true]);

        return new AuthenticatedSubject(
            subjectId: (string) $user->id,
            email: $user->email,
            name: $user->name,
            isAdmin: true,
            user: $user,
        );
    }

    private function removeDirectory(string $directory): void
    {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
