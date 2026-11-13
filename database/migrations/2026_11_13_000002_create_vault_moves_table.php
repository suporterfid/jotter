<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * vault:move (#437): one row per change of a workspace's vault store. A null
 * store id means the workspace's local `vault_path`. `cursor` is the last
 * copied path, so an interrupted copy resumes where it stopped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vault_moves')) {
            return;
        }

        Schema::create('vault_moves', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            // cascadeOnDelete, never nullOnDelete: a null store id means the local
            // folder, and finalize deletes the source. The API refuses to delete a
            // store that an unfinished move still needs.
            $table->foreignId('from_store_id')->nullable()->constrained('vault_stores')->cascadeOnDelete();
            $table->string('from_prefix', 512)->default('');
            $table->foreignId('to_store_id')->nullable()->constrained('vault_stores')->cascadeOnDelete();
            $table->string('to_prefix', 512)->default('');
            // copying → completed → finalized | rolled_back; failed at any step.
            $table->string('status', 16)->default('copying');
            $table->string('cursor', 1024)->nullable();
            $table->unsignedInteger('files_total')->default(0);
            $table->unsignedBigInteger('bytes_total')->default(0);
            $table->unsignedInteger('files_copied')->default(0);
            $table->unsignedBigInteger('bytes_copied')->default(0);
            $table->string('error', 1000)->nullable();
            $table->string('actor_id', 191)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('retain_until')->nullable();
            $table->timestamps();

            $table->index(['status', 'retain_until']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_moves');
    }
};
