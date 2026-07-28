<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('note_comments')) {
            Schema::create('note_comments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->foreignId('note_id')->constrained('notes')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name', 191);
                $table->text('content');
                $table->unsignedInteger('anchor_line')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'note_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('note_comments');
    }
};
