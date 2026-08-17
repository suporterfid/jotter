<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NoteTrashTest extends TestCase
{
    use RefreshDatabase;

    public function test_soft_deleted_notes_are_hidden_from_active_queries(): void
    {
        $workspace = $this->makeWorkspace();
        $note = Note::query()->create([
            'workspace_id' => $workspace->id,
            'path' => 'readme.md',
            'title' => 'Readme',
            'content_hash' => hash('sha256', "# Readme\n"),
            'search_content' => 'Readme',
        ]);

        $note->delete();

        $this->assertSoftDeleted('notes', ['id' => $note->id]);
        $this->assertFalse(Note::query()->whereKey($note->id)->exists());
        $this->assertTrue(Note::withTrashed()->whereKey($note->id)->exists());
        $this->assertTrue(Note::withTrashed()->findOrFail($note->id)->trashed());
    }

    private function makeWorkspace(): Workspace
    {
        $tenant = Tenant::query()->create([
            'slug' => 'trash-tenant-'.uniqid(),
            'name' => 'Trash Tenant',
        ]);

        return Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => 'trash-workspace-'.uniqid(),
            'name' => 'Trash Workspace',
            'vault_path' => sys_get_temp_dir().'/jotter-trash-'.uniqid(),
        ]);
    }
}
