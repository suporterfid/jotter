<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workspace_group_members')) {
            return;
        }

        Schema::create('workspace_group_members', function (Blueprint $table): void {
            $table->foreignId('workspace_group_id')->constrained('workspace_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['workspace_group_id', 'user_id'], 'workspace_group_members_primary');
            $table->index(['user_id', 'workspace_group_id'], 'workspace_group_members_user_group_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_group_members');
    }
};
