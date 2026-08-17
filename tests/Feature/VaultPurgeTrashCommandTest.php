<?php

namespace Tests\Feature;

use App\Domain\Vault\NoteTrash;
use App\Domain\Vault\VaultStorage;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VaultPurgeTrashCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultRoot = sys_get_temp_dir().'/jotter-purge-trash-'.uniqid('', true);
        mkdir($this->vaultRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->vaultRoot);

        parent::tearDown();
    }

    public function test_command_purges_expired_trash_and_respects_batch_size(): void
    {
        $workspace = $this->makeWorkspace();
        $old = $this->trash($workspace, 'old.md');
        $new = $this->trash($workspace, 'new.md');
        $old->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();
        $new->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();

        $this->artisan('vault:purge-trash', ['--days' => 30, '--batch' => 1])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('notes', ['id' => $old->id]);
        $this->assertDatabaseHas('notes', ['id' => $new->id]);
        $this->assertFileDoesNotExist($this->vaultRoot.'/'.$old->path);
        $this->assertFileExists($this->vaultRoot.'/'.$new->path);
    }

    public function test_command_uses_configured_retention_when_days_is_omitted(): void
    {
        config(['jotter.trash.retention_days' => 10]);
        $workspace = $this->makeWorkspace();
        $expired = $this->trash($workspace, 'expired.md');
        $recent = $this->trash($workspace, 'recent.md');
        $expired->forceFill(['deleted_at' => now()->subDays(11)])->saveQuietly();
        $recent->forceFill(['deleted_at' => now()->subDays(9)])->saveQuietly();

        $this->artisan('vault:purge-trash')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('notes', ['id' => $expired->id]);
        $this->assertDatabaseHas('notes', ['id' => $recent->id]);
    }

    private function trash(Workspace $workspace, string $path): Note
    {
        $note = app(VaultStorage::class)->write($workspace, $path, "# {$path}\n");

        return app(NoteTrash::class)->trash($workspace, $note);
    }

    private function makeWorkspace(): Workspace
    {
        $tenant = Tenant::query()->create([
            'slug' => 'purge-tenant-'.uniqid(),
            'name' => 'Purge Tenant',
        ]);

        return Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'purge-workspace-'.uniqid(),
            'name' => 'Purge Workspace',
            'vault_path' => $this->vaultRoot,
        ]);
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path) && ! is_file($path)) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->deleteTree($path.DIRECTORY_SEPARATOR.$item);
        }

        @rmdir($path);
    }
}
