<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('note_watchers')) {
            Schema::create('note_watchers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->foreignId('note_id')->constrained('notes')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->boolean('is_watching')->default(true);
                $table->timestamps();

                $table->unique(['note_id', 'user_id']);
                $table->index(['workspace_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('note_watchers');
    }
};
