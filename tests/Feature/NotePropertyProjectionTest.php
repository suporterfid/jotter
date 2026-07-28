<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultReindexer;
use App\Domain\Vault\VaultStorage;
use App\Models\NoteProperty;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotePropertyProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_typed_properties_are_projected_and_rebuildable_identically(): void
    {
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $vaultPath = storage_path('app/vaults/prop_test_'.uniqid());
        @mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'default',
            'name' => 'Default Workspace',
            'vault_path' => $vaultPath,
        ]);

        $storage = new VaultStorage();
        $docContent = <<<MARKDOWN
---
title: Typed Note
status: "in-progress"
priority: 1
published: true
due_date: "2026-12-31"
tags: ["work", "urgent"]
metadata:
  owner: "admin"
---
# Typed Note Body
MARKDOWN;

        $note = $storage->write($workspace, 'typed_note.md', $docContent);

        // 1. Assert projected property rows exist with expected types and values
        $this->assertDatabaseHas('note_properties', [
            'note_id' => $note->id,
            'name' => 'status',
            'type' => 'string',
            'value_string' => 'in-progress',
        ]);
        $this->assertDatabaseHas('note_properties', [
            'note_id' => $note->id,
            'name' => 'priority',
            'type' => 'numeric',
            'value_numeric' => 1,
        ]);
        $this->assertDatabaseHas('note_properties', [
            'note_id' => $note->id,
            'name' => 'published',
            'type' => 'boolean',
            'value_boolean' => true,
        ]);
        $this->assertDatabaseHas('note_properties', [
            'note_id' => $note->id,
            'name' => 'due_date',
            'type' => 'datetime',
        ]);

        $beforeState = NoteProperty::query()->orderBy('id')->get()->toArray();
        $this->assertNotEmpty($beforeState);

        // 2. Drop all property rows and execute VaultReindexer::reindex
        NoteProperty::query()->delete();
        $this->assertDatabaseCount('note_properties', 0);

        $reindexer = new VaultReindexer();
        $reindexer->reindex($workspace);

        // 3. Assert rebuild produces identical property rows
        $afterState = NoteProperty::query()->orderBy('id')->get()->toArray();

        $this->assertCount(count($beforeState), $afterState);
        foreach ($beforeState as $index => $row) {
            $this->assertEquals($row['name'], $afterState[$index]['name']);
            $this->assertEquals($row['type'], $afterState[$index]['type']);
            $this->assertEquals($row['value_string'], $afterState[$index]['value_string']);
            $this->assertEquals($row['value_numeric'], $afterState[$index]['value_numeric']);
            $this->assertEquals($row['value_boolean'], $afterState[$index]['value_boolean']);
        }
    }
}
