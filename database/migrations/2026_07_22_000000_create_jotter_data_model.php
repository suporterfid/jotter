<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each table is guarded independently so this migration is safe after a
     * previous invocation stopped between DDL statements.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique('tenants_slug_unique');
                $table->string('name');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workspaces')) {
            Schema::create('workspaces', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')
                    ->constrained('tenants')
                    ->cascadeOnDelete();
                $table->string('slug');
                $table->string('name');
                $table->string('vault_path', 1024);
                $table->timestamps();

                $table->unique(['tenant_id', 'slug'], 'workspaces_tenant_slug_unique');
                $table->index(['tenant_id', 'name'], 'workspaces_tenant_name_index');
            });
        }

        if (! Schema::hasTable('notes')) {
            Schema::create('notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')
                    ->constrained('workspaces')
                    ->cascadeOnDelete();
                $table->string('path', 700);
                $table->string('title');
                $table->json('frontmatter')->nullable();
                $table->char('content_hash', 64);
                $table->longText('search_content')->nullable();
                $table->timestamps();

                $table->unique(['workspace_id', 'path'], 'notes_workspace_path_unique');
                $table->index(['workspace_id', 'updated_at'], 'notes_workspace_updated_index');
                $table->index(['workspace_id', 'title'], 'notes_workspace_title_index');
            });
        }

        if (! Schema::hasTable('note_links')) {
            Schema::create('note_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('source_note_id')
                    ->constrained('notes')
                    ->cascadeOnDelete();
                $table->string('target_ref', 700);
                $table->foreignId('target_note_id')
                    ->nullable()
                    ->constrained('notes')
                    ->nullOnDelete();
                $table->string('type', 50);
                $table->timestamps();

                $table->unique(
                    ['source_note_id', 'target_ref', 'type'],
                    'note_links_source_ref_type_unique',
                );
                $table->index(
                    ['target_note_id', 'type'],
                    'note_links_target_type_index',
                );
                $table->index(
                    ['source_note_id', 'type'],
                    'note_links_source_type_index',
                );
            });
        }

        if (! Schema::hasTable('tags')) {
            Schema::create('tags', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')
                    ->constrained('workspaces')
                    ->cascadeOnDelete();
                $table->string('name');
                $table->timestamps();

                $table->unique(['workspace_id', 'name'], 'tags_workspace_name_unique');
                $table->index(['workspace_id', 'created_at'], 'tags_workspace_created_index');
            });
        }

        if (! Schema::hasTable('note_tags')) {
            Schema::create('note_tags', function (Blueprint $table) {
                $table->foreignId('note_id')
                    ->constrained('notes')
                    ->cascadeOnDelete();
                $table->foreignId('tag_id')
                    ->constrained('tags')
                    ->cascadeOnDelete();

                $table->primary(['note_id', 'tag_id'], 'note_tags_primary');
                $table->index('tag_id', 'note_tags_tag_index');
            });
        }

        if (! Schema::hasTable('attachments')) {
            Schema::create('attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')
                    ->constrained('workspaces')
                    ->cascadeOnDelete();
                $table->string('path', 700);
                $table->string('mime');
                $table->unsignedBigInteger('size');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(
                    ['workspace_id', 'path'],
                    'attachments_workspace_path_unique',
                );
                $table->index(
                    ['workspace_id', 'created_at'],
                    'attachments_workspace_created_index',
                );
            });
        }

        if (! Schema::hasTable('identities')) {
            Schema::create('identities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->string('provider', 100);
                $table->string('subject_id');
                $table->timestamps();

                $table->unique(
                    ['provider', 'subject_id'],
                    'identities_provider_subject_unique',
                );
                $table->index('user_id', 'identities_user_index');
            });
        }

        if (! Schema::hasTable('memberships')) {
            Schema::create('memberships', function (Blueprint $table) {
                $table->id();
                $table->string('subject_id');
                $table->foreignId('tenant_id')
                    ->constrained('tenants')
                    ->cascadeOnDelete();
                $table->foreignId('workspace_id')
                    ->nullable()
                    ->constrained('workspaces')
                    ->nullOnDelete();
                $table->unsignedBigInteger('workspace_scope_id')
                    ->storedAs('COALESCE(workspace_id, 0)');
                $table->string('role', 50);
                $table->timestamps();

                $table->unique(
                    ['subject_id', 'tenant_id', 'workspace_scope_id'],
                    'memberships_subject_tenant_workspace_unique',
                );
                $table->index(
                    ['tenant_id', 'subject_id'],
                    'memberships_tenant_subject_index',
                );
                $table->index(
                    ['workspace_id', 'subject_id'],
                    'memberships_workspace_subject_index',
                );
                $table->index(['tenant_id', 'role'], 'memberships_tenant_role_index');
            });
        }

        if (! Schema::hasTable('audit_log')) {
            Schema::create('audit_log', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')
                    ->nullable()
                    ->constrained('tenants')
                    ->restrictOnDelete();
                $table->foreignId('workspace_id')
                    ->nullable()
                    ->constrained('workspaces')
                    ->restrictOnDelete();
                $table->string('actor_subject_id')->nullable();
                $table->string('event');
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(
                    ['tenant_id', 'workspace_id', 'created_at'],
                    'audit_log_scope_created_index',
                );
                $table->index(
                    ['actor_subject_id', 'created_at'],
                    'audit_log_actor_created_index',
                );
                $table->index(['event', 'created_at'], 'audit_log_event_created_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('identities');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('note_tags');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('note_links');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('workspaces');
        Schema::dropIfExists('tenants');
    }
};
