<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('note_comments', 'parent_comment_id')) {
            Schema::table('note_comments', function (Blueprint $table): void {
                $table->foreignId('parent_comment_id')
                    ->nullable()
                    ->after('note_id')
                    ->constrained('note_comments')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('note_comments', 'parent_comment_id')) {
            Schema::table('note_comments', function (Blueprint $table): void {
                $table->dropForeign(['parent_comment_id']);
                $table->dropColumn('parent_comment_id');
            });
        }
    }
};
