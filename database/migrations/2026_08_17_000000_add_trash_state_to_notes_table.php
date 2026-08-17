<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->string('original_path', 700)->nullable()->after('path');
            $table->softDeletes();
            $table->index(['workspace_id', 'deleted_at'], 'notes_workspace_deleted_index');
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->dropIndex('notes_workspace_deleted_index');
            $table->dropSoftDeletes();
            $table->dropColumn('original_path');
        });
    }
};
