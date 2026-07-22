<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Identity;
use App\Models\Membership;
use App\Models\Note;
use App\Models\NoteLink;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_projection_and_state_tables_have_the_required_columns(): void
    {
        $expectedColumns = [
            'tenants' => ['id', 'slug', 'name', 'created_at', 'updated_at'],
            'workspaces' => ['id', 'tenant_id', 'slug', 'name', 'vault_path', 'created_at', 'updated_at'],
            'notes' => [
                'id',
                'workspace_id',
                'path',
                'title',
                'frontmatter',
                'content_hash',
                'search_content',
                'created_at',
                'updated_at',
            ],
            'note_links' => [
                'id',
                'source_note_id',
                'target_ref',
                'target_note_id',
                'type',
                'created_at',
                'updated_at',
            ],
            'tags' => ['id', 'workspace_id', 'name', 'created_at', 'updated_at'],
            'note_tags' => ['note_id', 'tag_id'],
            'attachments' => ['id', 'workspace_id', 'path', 'mime', 'size', 'created_at'],
            'identities' => ['id', 'user_id', 'provider', 'subject_id', 'created_at', 'updated_at'],
            'memberships' => [
                'id',
                'subject_id',
                'tenant_id',
                'workspace_id',
                'workspace_scope_id',
                'role',
                'created_at',
                'updated_at',
            ],
            'audit_log' => [
                'id',
                'tenant_id',
                'workspace_id',
                'actor_subject_id',
                'event',
                'metadata',
                'ip_address',
                'created_at',
            ],
        ];

        foreach ($expectedColumns as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");
            $this->assertTrue(
                Schema::hasColumns($table, $columns),
                "Table [{$table}] does not contain all required columns.",
            );
        }

        $this->assertTrue(Schema::hasColumn('notes', 'search_content'));
        $this->assertFalse(Schema::hasColumn('notes', 'body'));
        $this->assertFalse(Schema::hasColumn('notes', 'content'));
        $this->assertFalse(Schema::hasColumn('attachments', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('audit_log', 'updated_at'));
    }

    public function test_migration_up_is_safe_to_invoke_after_tables_exist(): void
    {
        $migration = require database_path('migrations/2026_07_22_000000_create_jotter_data_model.php');

        $migration->up();

        $this->assertTrue(Schema::hasTable('audit_log'));
        $this->assertTrue(Schema::hasColumn('notes', 'search_content'));
    }

    public function test_models_expose_the_domain_relationships(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $workspace = $tenant->workspaces()->create([
            'slug' => 'notes',
            'name' => 'Notes',
            'vault_path' => '/srv/jotter/acme',
        ]);
        $note = $workspace->notes()->create([
            'path' => 'welcome.md',
            'title' => 'Welcome',
            'frontmatter' => ['draft' => false],
            'content_hash' => str_repeat('a', 64),
            'search_content' => 'Rebuildable projection only.',
        ]);
        $target = $workspace->notes()->create([
            'path' => 'target.md',
            'title' => 'Target',
            'content_hash' => str_repeat('b', 64),
        ]);
        $link = $note->outgoingLinks()->create([
            'target_ref' => 'target',
            'target_note_id' => $target->id,
            'type' => 'wikilink',
        ]);
        $tag = $workspace->tags()->create(['name' => 'project']);
        $note->tags()->attach($tag);
        $attachment = $workspace->attachments()->create([
            'path' => 'assets/image.png',
            'mime' => 'image/png',
            'size' => 123,
        ]);
        $user = User::factory()->create();
        $identity = Identity::create([
            'user_id' => $user->id,
            'provider' => 'local',
            'subject_id' => (string) $user->id,
        ]);
        $membership = Membership::create([
            'subject_id' => $identity->subject_id,
            'tenant_id' => $tenant->id,
            'workspace_id' => $workspace->id,
            'role' => 'owner',
        ]);
        $audit = AuditLog::create([
            'tenant_id' => $tenant->id,
            'workspace_id' => $workspace->id,
            'actor_subject_id' => $identity->subject_id,
            'event' => 'data_model.tested',
            'metadata' => ['source' => 'test'],
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertTrue($workspace->tenant->is($tenant));
        $this->assertTrue($tenant->workspaces->contains($workspace));
        $this->assertTrue($note->workspace->is($workspace));
        $this->assertTrue($link->sourceNote->is($note));
        $this->assertTrue($link->targetNote->is($target));
        $this->assertTrue($target->incomingLinks->contains($link));
        $this->assertTrue($note->tags->contains($tag));
        $this->assertTrue($tag->notes->contains($note));
        $this->assertTrue($attachment->workspace->is($workspace));
        $this->assertTrue($identity->user->is($user));
        $this->assertTrue($user->identities->contains($identity));
        $this->assertTrue($membership->tenant->is($tenant));
        $this->assertTrue($membership->workspace->is($workspace));
        $this->assertTrue($audit->tenant->is($tenant));
        $this->assertTrue($audit->workspace->is($workspace));
        $this->assertSame(['draft' => false], $note->frontmatter);
        $this->assertSame(['source' => 'test'], $audit->metadata);
    }

    public function test_workspace_scoped_slugs_paths_and_tag_names_are_unique(): void
    {
        $tenant = Tenant::create(['slug' => 'one', 'name' => 'One']);
        $otherTenant = Tenant::create(['slug' => 'two', 'name' => 'Two']);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'vault',
            'name' => 'Vault',
            'vault_path' => '/vaults/one',
        ]);
        $otherWorkspace = Workspace::create([
            'tenant_id' => $otherTenant->id,
            'slug' => 'vault',
            'name' => 'Vault',
            'vault_path' => '/vaults/two',
        ]);

        Note::create([
            'workspace_id' => $workspace->id,
            'path' => 'same.md',
            'title' => 'Same',
            'content_hash' => str_repeat('c', 64),
        ]);
        Tag::create(['workspace_id' => $workspace->id, 'name' => 'same']);
        Attachment::create([
            'workspace_id' => $workspace->id,
            'path' => 'same.png',
            'mime' => 'image/png',
            'size' => 1,
        ]);
        Note::create([
            'workspace_id' => $otherWorkspace->id,
            'path' => 'same.md',
            'title' => 'Same in another workspace',
            'content_hash' => str_repeat('d', 64),
        ]);
        Tag::create(['workspace_id' => $otherWorkspace->id, 'name' => 'same']);
        Attachment::create([
            'workspace_id' => $otherWorkspace->id,
            'path' => 'same.png',
            'mime' => 'image/png',
            'size' => 1,
        ]);

        $duplicateChecks = [
            fn () => Tenant::create(['slug' => 'one', 'name' => 'Duplicate']),
            fn () => Workspace::create([
                'tenant_id' => $tenant->id,
                'slug' => 'vault',
                'name' => 'Duplicate',
                'vault_path' => '/vaults/duplicate',
            ]),
            fn () => Note::create([
                'workspace_id' => $workspace->id,
                'path' => 'same.md',
                'title' => 'Duplicate',
                'content_hash' => str_repeat('e', 64),
            ]),
            fn () => Tag::create(['workspace_id' => $workspace->id, 'name' => 'same']),
            fn () => Attachment::create([
                'workspace_id' => $workspace->id,
                'path' => 'same.png',
                'mime' => 'image/png',
                'size' => 2,
            ]),
        ];

        foreach ($duplicateChecks as $duplicateCheck) {
            try {
                $duplicateCheck();
                $this->fail('Expected a workspace-scoped unique constraint violation.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_tenant_level_memberships_are_unique_when_workspace_is_null(): void
    {
        $tenant = Tenant::create(['slug' => 'membership', 'name' => 'Membership']);

        Membership::create([
            'subject_id' => 'local:1',
            'tenant_id' => $tenant->id,
            'workspace_id' => null,
            'role' => 'owner',
        ]);

        $this->expectException(QueryException::class);

        Membership::create([
            'subject_id' => 'local:1',
            'tenant_id' => $tenant->id,
            'workspace_id' => null,
            'role' => 'viewer',
        ]);
    }

    public function test_workspace_deletion_is_restricted_while_scoped_memberships_exist(): void
    {
        $tenant = Tenant::create(['slug' => 'scoped', 'name' => 'Scoped']);
        $workspace = $tenant->workspaces()->create([
            'slug' => 'membership',
            'name' => 'Membership',
            'vault_path' => '/vaults/membership',
        ]);
        Membership::create([
            'subject_id' => 'local:1',
            'tenant_id' => $tenant->id,
            'workspace_id' => $workspace->id,
            'role' => 'owner',
        ]);

        $this->expectException(QueryException::class);

        $workspace->delete();
    }

    public function test_audit_log_entries_cannot_be_updated(): void
    {
        $audit = AuditLog::create(['event' => 'created']);
        $audit->event = 'changed';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Audit log entries are append-only.');

        $audit->save();
    }

    public function test_audit_log_entries_cannot_be_deleted(): void
    {
        $audit = AuditLog::create(['event' => 'created']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Audit log entries are append-only.');

        $audit->delete();
    }

    public function test_audit_scopes_cannot_be_deleted_while_history_references_them(): void
    {
        $tenant = Tenant::create(['slug' => 'audited', 'name' => 'Audited']);
        $workspace = $tenant->workspaces()->create([
            'slug' => 'history',
            'name' => 'History',
            'vault_path' => '/vaults/history',
        ]);
        AuditLog::create([
            'tenant_id' => $tenant->id,
            'workspace_id' => $workspace->id,
            'event' => 'workspace.audited',
        ]);

        $this->expectException(QueryException::class);

        $workspace->delete();
    }
}
