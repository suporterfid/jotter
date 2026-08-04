<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('audit_log', 'note_id')) {
            Schema::table('audit_log', function (Blueprint $table) {
                $table->foreignId('note_id')->nullable()->after('workspace_id')->constrained('notes')->nullOnDelete();
                $table->index(['note_id', 'created_at'], 'audit_log_note_created_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropForeign(['note_id']);
            $table->dropIndex('audit_log_note_created_index');
            $table->dropColumn('note_id');
        });
    }
};
