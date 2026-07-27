<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultPathGuard;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AttachmentUploadTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultRoot;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultRoot = sys_get_temp_dir().'/jotter-attachments-'.uniqid('', true);
        mkdir($this->vaultRoot, 0755, true);

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->vaultRoot);

        parent::tearDown();
    }

    public function test_attachment_upload_stores_file_in_vault_and_creates_database_record_and_audit_log(): void
    {
        $workspace = $this->makeWorkspace('upload-test');
        $file = UploadedFile::fake()->create('diagram.png', 100, 'image/png');

        $response = $this->postJson("/api/workspaces/{$workspace->id}/attachments", [
            'file' => $file,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.path', '_resources/diagram.png')
            ->assertJsonPath('data.mime', 'image/png');

        $this->assertDatabaseHas('attachments', [
            'workspace_id' => $workspace->id,
            'path' => '_resources/diagram.png',
            'mime' => 'image/png',
        ]);

        $this->assertFileExists($this->vaultRoot.'/_resources/diagram.png');

        $this->assertDatabaseHas('audit_log', [
            'workspace_id' => $workspace->id,
            'event' => 'attachment.created',
        ]);
    }

    public function test_attachment_listing_returns_workspace_attachments_with_download_urls(): void
    {
        $workspace = $this->makeWorkspace('list-test');
        Attachment::query()->create([
            'workspace_id' => $workspace->id,
            'path' => '_resources/doc.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
        ]);

        $response = $this->getJson("/api/workspaces/{$workspace->id}/attachments");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.path', '_resources/doc.pdf')
            ->assertJsonPath('data.0.mime', 'application/pdf');
    }

    public function test_attachment_streaming_serves_vault_file_with_correct_mime_and_headers(): void
    {
        $workspace = $this->makeWorkspace('stream-test');
        $fileDir = $this->vaultRoot.'/_resources';
        mkdir($fileDir, 0755, true);
        file_put_contents($fileDir.'/sample.txt', 'Hello attachment content');

        Attachment::query()->create([
            'workspace_id' => $workspace->id,
            'path' => '_resources/sample.txt',
            'mime' => 'text/plain',
            'size' => 24,
        ]);

        $response = $this->withoutExceptionHandling()->get("/api/workspaces/{$workspace->id}/attachments/_resources/sample.txt");

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $this->assertSame('Hello attachment content', $response->streamedContent());
    }

    public function test_attachment_deletion_removes_file_from_disk_and_database_and_audits(): void
    {
        $workspace = $this->makeWorkspace('delete-test');
        $fileDir = $this->vaultRoot.'/_resources';
        mkdir($fileDir, 0755, true);
        $filePath = $fileDir.'/to-delete.png';
        file_put_contents($filePath, 'png binary data');

        $attachment = Attachment::query()->create([
            'workspace_id' => $workspace->id,
            'path' => '_resources/to-delete.png',
            'mime' => 'image/png',
            'size' => 15,
        ]);

        $response = $this->deleteJson("/api/workspaces/{$workspace->id}/attachments/{$attachment->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Attachment deleted successfully.']);

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        $this->assertFileDoesNotExist($filePath);

        $this->assertDatabaseHas('audit_log', [
            'workspace_id' => $workspace->id,
            'event' => 'attachment.deleted',
        ]);
    }

    public function test_unallowed_file_extension_or_mime_type_is_rejected_with_422(): void
    {
        $workspace = $this->makeWorkspace('invalid-type');
        $file = UploadedFile::fake()->create('script.exe', 100, 'application/x-msdownload');

        $response = $this->postJson("/api/workspaces/{$workspace->id}/attachments", [
            'file' => $file,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_attachment_path_traversal_is_rejected_and_audited(): void
    {
        $workspace = $this->makeWorkspace('traversal-test');

        $response = $this->get("/api/workspaces/{$workspace->id}/attachments/..%2F..%2Fetc%2Fpasswd");

        $response->assertNotFound();

        $this->assertDatabaseHas('audit_log', [
            'workspace_id' => $workspace->id,
            'event' => VaultPathGuard::AUDIT_EVENT,
        ]);
    }

    public function test_attachment_routes_require_workspace_authorization(): void
    {
        $workspace = $this->makeWorkspace('auth-test');
        $nonMember = User::factory()->create(['is_admin' => false]);

        $this->actingAs($nonMember);

        $this->getJson("/api/workspaces/{$workspace->id}/attachments")
            ->assertForbidden();
    }

    private function makeWorkspace(string $slug): Workspace
    {
        $tenant = Tenant::query()->create([
            'slug' => "tenant-{$slug}",
            'name' => "Tenant {$slug}",
        ]);

        return Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => $slug,
            'name' => "Workspace {$slug}",
            'vault_path' => $this->vaultRoot,
        ]);
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->deleteTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
