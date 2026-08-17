<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('note_acl_entries')) {
            return;
        }

        Schema::create('note_acl_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('note_id')->constrained('notes')->cascadeOnDelete();
            $table->string('principal_type', 20);
            $table->unsignedBigInteger('principal_id');
            $table->string('permission', 20);
            $table->timestamps();

            $table->unique(
                ['note_id', 'principal_type', 'principal_id'],
                'note_acl_entries_note_principal_unique',
            );
            $table->index(
                ['principal_type', 'principal_id', 'permission'],
                'note_acl_entries_principal_permission_index',
            );
            $table->index(['note_id', 'permission'], 'note_acl_entries_note_permission_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('note_acl_entries');
    }
};
