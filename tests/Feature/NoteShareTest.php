<?php

namespace Tests\Feature;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Sharing\NoteShareService;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Note;
use App\Models\NoteAclEntry;
use App\Models\Membership;
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

    public function test_admin_can_create_read_and_revoke_a_note_share(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $note = $this->noteFixture();
        $url = "/api/workspaces/{$note->workspace_id}/notes/{$note->id}/share";

        $created = $this->actingAs($admin)
            ->postJson($url, ['expires_at' => now()->addDay()->toISOString()])
            ->assertCreated()
            ->assertJsonPath('data.active', true)
            ->assertJsonStructure(['data' => ['token', 'url', 'expires_at', 'revoked_at']]);

        $token = $created->json('data.token');
        $this->assertIsString($token);
        $this->assertStringContainsString($token, $created->json('data.url'));

        $this->actingAs($admin)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.url', null)
            ->assertJsonMissingPath('data.token');

        $this->actingAs($admin)
            ->deleteJson($url)
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertNotNull($this->noteShare()->revoked_at);
        $this->assertDatabaseHas('audit_log', [
            'event' => 'note.share_created',
            'note_id' => $note->id,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'event' => 'note.share_revoked',
            'note_id' => $note->id,
        ]);
        $auditMetadata = AuditLog::query()->latest('id')->limit(2)->pluck('metadata')->all();
        $this->assertStringNotContainsString($token, json_encode($auditMetadata, JSON_THROW_ON_ERROR));
    }

    public function test_editor_without_view_access_cannot_create_a_share_for_a_restricted_note(): void
    {
        $note = $this->noteFixture();
        $allowedUser = User::factory()->create();
        NoteAclEntry::create([
            'note_id' => $note->id,
            'principal_type' => 'user',
            'principal_id' => $allowedUser->id,
            'permission' => 'view',
        ]);
        $editor = User::factory()->create();
        Membership::create([
            'subject_id' => (string) $editor->id,
            'tenant_id' => $note->workspace->tenant_id,
            'workspace_id' => $note->workspace_id,
            'role' => 'editor',
        ]);

        $this->actingAs($editor)
            ->postJson("/api/workspaces/{$note->workspace_id}/notes/{$note->id}/share", ['expires_at' => null])
            ->assertNotFound();

        $this->assertDatabaseCount('note_shares', 0);
    }

    public function test_public_share_renders_only_the_selected_note_and_plain_text_wikilinks(): void
    {
        $note = $this->noteFixture();
        $workspace = $note->workspace;
        file_put_contents($workspace->vault_path.'/'.$note->path, "Selected note content\n\n[[Secret note]]");
        $secret = Note::create([
            'workspace_id' => $workspace->id,
            'path' => 'secret.md',
            'title' => 'Secret note',
            'frontmatter' => [],
            'content_hash' => hash('sha256', 'Secret note body'),
            'search_content' => 'Secret note body',
        ]);
        file_put_contents($workspace->vault_path.'/'.$secret->path, 'Secret note body');
        $admin = $this->adminSubject();
        ['token' => $token] = app(NoteShareService::class)->create($note, $admin, null);

        $this->get('/share/'.$token)
            ->assertOk()
            ->assertSee('Selected note content')
            ->assertSee('[[Secret note]]')
            ->assertDontSee('Secret note body')
            ->assertDontSee($workspace->slug)
            ->assertDontSee('sidebar')
            ->assertDontSee('href="#/note/', false);
    }

    public function test_invalid_expired_and_revoked_public_links_return_404(): void
    {
        $note = $this->noteFixture();
        $admin = $this->adminSubject();
        $service = app(NoteShareService::class);
        ['token' => $expired] = $service->create($note, $admin, now()->subMinute());
        ['share' => $revokedShare, 'token' => $revoked] = $service->create($note, $admin, null);
        $service->revoke($revokedShare, $admin);

        $this->get('/share/not-a-token')->assertNotFound();
        $this->get('/share/'.$expired)->assertNotFound();
        $this->get('/share/'.$revoked)->assertNotFound();
    }

    public function test_public_share_rewrites_registered_images_and_attachment_links_to_the_same_token(): void
    {
        $note = $this->noteFixture();
        $workspace = $note->workspace;
        $markdown = "![diagram](_resources/diagram.png)\n\n[Download](_resources/diagram.png)";
        file_put_contents($workspace->vault_path.'/'.$note->path, $markdown);
        Attachment::create([
            'workspace_id' => $workspace->id,
            'path' => '_resources/diagram.png',
            'mime' => 'image/png',
            'size' => 3,
        ]);
        mkdir($workspace->vault_path.'/_resources', 0755, true);
        file_put_contents($workspace->vault_path.'/_resources/diagram.png', 'PNG');
        ['token' => $token] = app(NoteShareService::class)->create($note, $this->adminSubject(), null);

        $this->get('/share/'.$token)
            ->assertOk()
            ->assertSee('/share/'.$token.'/attachments/_resources/diagram.png', false);
        $this->get('/share/'.$token.'/attachments/_resources/diagram.png')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->get('/share/'.$token.'/attachments/../'.$note->path)->assertNotFound();
    }

    private function noteShare(): \App\Models\NoteShare
    {
        return \App\Models\NoteShare::query()->latest('id')->firstOrFail();
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
