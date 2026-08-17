<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workspace_groups')) {
            return;
        }

        Schema::create('workspace_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name', 150);
            $table->timestamps();

            $table->unique(['workspace_id', 'name'], 'workspace_groups_workspace_name_unique');
            $table->index(['workspace_id', 'created_at'], 'workspace_groups_workspace_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_groups');
    }
};
