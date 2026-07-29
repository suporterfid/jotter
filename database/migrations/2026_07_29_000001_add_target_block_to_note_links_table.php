<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('note_links', 'target_block')) {
            Schema::table('note_links', function (Blueprint $table) {
                $table->string('target_block', 255)->nullable()->after('target_ref');
                $table->index(['target_note_id', 'target_block'], 'note_links_target_block_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('note_links', 'target_block')) {
            Schema::table('note_links', function (Blueprint $table) {
                $table->dropIndex('note_links_target_block_index');
                $table->dropColumn('target_block');
            });
        }
    }
};
