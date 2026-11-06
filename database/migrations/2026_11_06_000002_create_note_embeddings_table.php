<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Semantic search (#412). `note_embeddings` is a projection, like the rest of
 * MySQL (spec §1): one row per chunk of a note, rebuilt from the note's text
 * at any time (`semantic:index --rebuild`). `content_hash` is the note's hash
 * when the chunk was embedded, so a changed note is re-embedded. Off for every
 * workspace until an operator opts it in (`workspaces.semantic_search`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('note_embeddings')) {
            Schema::create('note_embeddings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->foreignId('note_id')->constrained('notes')->cascadeOnDelete();
                $table->unsignedSmallInteger('chunk_index');
                $table->char('content_hash', 64);
                $table->string('model', 120);
                $table->unsignedSmallInteger('dimensions');
                $table->text('chunk_text');
                $table->binary('vector');
                $table->timestamps();

                $table->unique(['note_id', 'chunk_index']);
                $table->index(['workspace_id', 'note_id']);
            });
        }

        Schema::table('workspaces', function (Blueprint $table): void {
            if (! Schema::hasColumn('workspaces', 'semantic_search')) {
                $table->boolean('semantic_search')->default(false)->after('vault_path');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('note_embeddings');
        Schema::table('workspaces', function (Blueprint $table): void {
            if (Schema::hasColumn('workspaces', 'semantic_search')) {
                $table->dropColumn('semantic_search');
            }
        });
    }
};
