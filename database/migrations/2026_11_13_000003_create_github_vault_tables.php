<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub vault store (#431, docs/20261113-jotter-github-vault-implementation-plan.md §5.1).
 *
 * Everything is keyed by `vault_store_id`: D1 gives one GitHub store (one
 * repository and branch) to exactly one workspace.
 *
 * - `vault_journal`: writes saved in Jotter and not yet committed (write-behind, D3).
 * - `vault_remote_files`: the files of the remote head as Jotter last saw them
 *   (path → blob SHA), so stat/list/exists never call GitHub.
 * - `vault_blob_cache`: immutable file contents by blob SHA, LRU-capped, never backed up.
 * - `vault_sync_states`: remote head, ref ETag, pull/flush timestamps, backoff, webhook age.
 * - `github_write_budgets`: write token bucket per actor (installation or PAT).
 * - `github_installations`: App installations a tenant completed through the
 *   install flow; a store may only use an installation bound to its tenant.
 * - `github_install_states`: single-use `state` nonces of the install flow.
 * - `github_webhook_deliveries`: `X-GitHub-Delivery` ids seen in the last 24 h.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_journal', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vault_store_id')->constrained('vault_stores')->cascadeOnDelete();
            $table->string('path', 1024)->collation('utf8mb4_bin');
            $table->string('op', 8); // write | delete
            $table->binary('content')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->char('sha256', 64)->nullable();
            // Git blob SHA of `content`, computed locally: the store's version token.
            $table->char('git_sha', 40)->nullable();
            // Remote blob SHA this edit was based on ('' = the file did not exist remotely).
            $table->string('base_version', 40)->nullable();
            $table->string('actor_id', 191)->nullable();
            $table->string('actor_name', 191)->nullable();
            $table->string('flush_id', 36)->nullable();
            $table->char('commit_sha', 40)->nullable();
            $table->timestamp('flushed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 1000)->nullable();
            $table->timestamps();

            $table->index(['vault_store_id', 'flushed_at', 'id']);
            $table->index(['vault_store_id', 'flush_id']);
        });
        // MariaDB/MySQL cannot index a 1024-char utf8mb4 column whole; a prefix index serves lookups.
        DB::statement('CREATE INDEX vault_journal_store_path ON vault_journal (vault_store_id, path(191))');
        DB::statement('ALTER TABLE vault_journal MODIFY content LONGBLOB NULL');

        Schema::create('vault_remote_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vault_store_id')->constrained('vault_stores')->cascadeOnDelete();
            $table->string('path', 1024)->collation('utf8mb4_bin');
            $table->char('blob_sha', 40);
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });
        DB::statement('CREATE INDEX vault_remote_files_store_path ON vault_remote_files (vault_store_id, path(191))');

        Schema::create('vault_blob_cache', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vault_store_id')->constrained('vault_stores')->cascadeOnDelete();
            $table->char('sha', 40);
            $table->unsignedBigInteger('size');
            $table->binary('content');
            $table->timestamp('last_used_at')->nullable();

            $table->unique(['vault_store_id', 'sha']);
            $table->index(['vault_store_id', 'last_used_at']);
        });
        DB::statement('ALTER TABLE vault_blob_cache MODIFY content LONGBLOB NOT NULL');

        Schema::create('vault_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vault_store_id')->unique()->constrained('vault_stores')->cascadeOnDelete();
            $table->boolean('initialized')->default(false);
            $table->char('remote_head', 40)->nullable();
            $table->string('ref_etag', 191)->nullable();
            $table->boolean('pull_pending')->default(false);
            $table->timestamp('last_pull_at')->nullable();
            $table->timestamp('last_flush_at')->nullable();
            $table->timestamp('next_flush_at')->nullable();
            $table->unsignedInteger('flush_failures')->default(0);
            $table->timestamp('failing_since')->nullable();
            $table->timestamp('failure_notified_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            // Crash recovery between POST /git/commits and PATCH /git/refs (§5.4).
            $table->string('pending_flush_id', 36)->nullable();
            $table->char('pending_commit_sha', 40)->nullable();
            $table->char('pending_commit_parent', 40)->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->unsignedBigInteger('repo_size_kb')->nullable();
            // Paths ignored on pull (.git*, submodules, symlinks, too large) and case/NFD fixes, for the doctor.
            $table->json('warnings')->nullable();
            $table->timestamps();
        });

        Schema::create('github_write_budgets', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_key', 191)->unique();
            $table->double('hour_tokens');
            $table->double('minute_tokens');
            $table->timestamp('refilled_at')->nullable();
            $table->timestamp('blocked_until')->nullable();
            $table->unsignedInteger('remaining_reported')->nullable();
            $table->timestamps();
        });

        Schema::create('github_installations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('installation_id');
            $table->string('account_login', 100)->nullable();
            $table->string('actor_id', 191)->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'installation_id']);
            $table->index('installation_id');
        });

        Schema::create('github_install_states', function (Blueprint $table): void {
            $table->id();
            $table->char('nonce_hash', 64)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('actor_id', 191)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('github_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id', 64)->unique();
            $table->string('event', 64);
            $table->timestamp('received_at');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        foreach (['github_webhook_deliveries', 'github_install_states', 'github_installations', 'github_write_budgets', 'vault_sync_states', 'vault_blob_cache', 'vault_remote_files', 'vault_journal'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
