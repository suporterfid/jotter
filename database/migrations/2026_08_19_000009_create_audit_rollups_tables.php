<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_rollups')) {
            Schema::create('audit_rollups', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->date('period_start');
                $table->string('dimension', 16);
                $table->string('dimension_key', 255);
                $table->unsignedBigInteger('count')->default(0);
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['workspace_id', 'period_start', 'dimension', 'dimension_key'],
                    'audit_rollups_workspace_period_dimension_unique',
                );
                $table->index(
                    ['workspace_id', 'dimension', 'period_start'],
                    'audit_rollups_workspace_dimension_period_index',
                );
            });
        }

        if (! Schema::hasTable('audit_rollup_cursors')) {
            Schema::create('audit_rollup_cursors', function (Blueprint $table): void {
                $table->id();
                $table->string('stream', 64)->unique();
                $table->unsignedBigInteger('last_audit_id')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_rollup_cursors');
        Schema::dropIfExists('audit_rollups');
    }
};
