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

    public function test_unquoted_yaml_date_is_projected_as_datetime_not_numeric(): void
    {
        // Regression: the previous test only ever wrote due_date as a *quoted*
        // YAML string ("2026-12-31"), which coincidentally masked this bug —
        // quoting suppresses YAML's core-schema timestamp detection, so it
        // reached NotePropertyProjector as a plain string. The natural,
        // unquoted way to write a YAML date (due_date: 2026-12-31, with no
        // quotes) is resolved by Symfony's YAML parser to a Unix timestamp
        // int unless Yaml::PARSE_DATETIME is passed, which the projector then
        // classified as NUMERIC instead of DATETIME.
        $tenant = Tenant::create(['slug' => 'unquoted-date', 'name' => 'Unquoted Date']);
        $vaultPath = storage_path('app/vaults/prop_unquoted_'.uniqid());
        @mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'unquoted-date',
            'name' => 'Unquoted Date Workspace',
            'vault_path' => $vaultPath,
        ]);

        $storage = new VaultStorage();
        $note = $storage->write($workspace, 'unquoted.md', <<<'MARKDOWN'
---
due_date: 2026-12-31
---
# Unquoted Date Note
MARKDOWN);

        $this->assertDatabaseHas('note_properties', [
            'note_id' => $note->id,
            'name' => 'due_date',
            'type' => 'datetime',
            'value_datetime' => '2026-12-31 00:00:00',
        ]);
    }

    public function test_icon_frontmatter_key_is_excluded_from_property_projection(): void
    {
        $tenant = Tenant::create(['slug' => 'icon-test', 'name' => 'Icon Test']);
        $vaultPath = storage_path('app/vaults/prop_icon_'.uniqid());
        @mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'icon-test',
            'name' => 'Icon Test Workspace',
            'vault_path' => $vaultPath,
        ]);

        $storage = new VaultStorage();
        $note = $storage->write($workspace, 'iconed.md', <<<'MARKDOWN'
---
title: Iconed Note
icon: "📄"
status: "active"
---
# Iconed Note Body
MARKDOWN);

        // icon must not be projected as a queryable NoteProperty row...
        $this->assertDatabaseMissing('note_properties', [
            'note_id' => $note->id,
            'name' => 'icon',
        ]);

        // ...but a sibling ordinary key on the same note still is, proving
        // the exclusion is scoped to `icon` specifically, not a projection
        // regression.
        $this->assertDatabaseHas('note_properties', [
            'note_id' => $note->id,
            'name' => 'status',
            'type' => 'string',
            'value_string' => 'active',
        ]);

        // ...and the frontmatter column itself still carries the raw value,
        // since that's what the frontend reads to render the icon.
        $this->assertSame('📄', $note->fresh()->frontmatter['icon']);
    }

    public function test_cover_frontmatter_key_is_excluded_from_property_projection(): void
    {
        $tenant = Tenant::create(['slug' => 'cover-test', 'name' => 'Cover Test']);
        $vaultPath = storage_path('app/vaults/prop_cover_'.uniqid());
        @mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'cover-test',
            'name' => 'Cover Test Workspace',
            'vault_path' => $vaultPath,
        ]);

        $storage = new VaultStorage();
        $note = $storage->write($workspace, 'covered.md', <<<'MARKDOWN'
---
title: Covered Note
cover: "https://example.com/banner.jpg"
status: "active"
---
# Covered Note Body
MARKDOWN);

        // cover must not be projected as a queryable NoteProperty row...
        $this->assertDatabaseMissing('note_properties', [
            'note_id' => $note->id,
            'name' => 'cover',
        ]);

        // ...but a sibling ordinary key on the same note still is, proving
        // the exclusion is scoped to `cover` specifically.
        $this->assertDatabaseHas('note_properties', [
            'note_id' => $note->id,
            'name' => 'status',
            'type' => 'string',
            'value_string' => 'active',
        ]);

        // ...and the frontmatter column itself still carries the raw value,
        // since that's what the frontend reads to render the cover.
        $this->assertSame('https://example.com/banner.jpg', $note->fresh()->frontmatter['cover']);
    }

    public function test_title_frontmatter_key_is_excluded_from_property_projection(): void
    {
        $tenant = Tenant::create(['slug' => 'title-test', 'name' => 'Title Test']);
        $vaultPath = storage_path('app/vaults/prop_title_'.uniqid());
        @mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'title-test',
            'name' => 'Title Test Workspace',
            'vault_path' => $vaultPath,
        ]);

        $storage = new VaultStorage();
        $note = $storage->write($workspace, 'titled.md', <<<'MARKDOWN'
---
title: "Renamed via Title Field"
status: "active"
---
# Original Heading
MARKDOWN);

        // title must not be projected as a queryable NoteProperty row (it
        // would otherwise leak into the generic Properties panel, which #257
        // relies on staying free of the dedicated title-editing affordance)...
        $this->assertDatabaseMissing('note_properties', [
            'note_id' => $note->id,
            'name' => 'title',
        ]);

        // ...but a sibling ordinary key on the same note still is, proving
        // the exclusion is scoped to `title` specifically.
        $this->assertDatabaseHas('note_properties', [
            'note_id' => $note->id,
            'name' => 'status',
            'type' => 'string',
            'value_string' => 'active',
        ]);

        // frontmatter.title takes precedence over the body heading for the
        // resolved title (MarkdownDocument::resolveTitle), matching what the
        // frontend reads and displays.
        $this->assertSame('Renamed via Title Field', $note->fresh()->title);
    }
}
