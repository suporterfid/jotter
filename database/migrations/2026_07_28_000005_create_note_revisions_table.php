<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('note_revisions')) {
            Schema::create('note_revisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->foreignId('note_id')->constrained('notes')->cascadeOnDelete();
                $table->string('content_hash', 64);
                $table->longText('content');
                $table->string('actor_id')->nullable();
                $table->timestamps();

                $table->index(['note_id', 'created_at']);
                $table->index(['workspace_id', 'note_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('note_revisions');
    }
};
