<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'notes_title_search_content_fulltext';

    public function up(): void
    {
        if (! Schema::hasTable('notes') || Schema::hasIndex('notes', self::INDEX)) {
            return;
        }

        Schema::table('notes', function (Blueprint $table): void {
            $table->fullText(['title', 'search_content'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notes') || ! Schema::hasIndex('notes', self::INDEX)) {
            return;
        }

        Schema::table('notes', function (Blueprint $table): void {
            $table->dropFullText(self::INDEX);
        });
    }
};
