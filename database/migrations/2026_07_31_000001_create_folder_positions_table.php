<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('folder_positions')) {
            Schema::create('folder_positions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->string('folder_path');
                $table->integer('sort_position');
                $table->timestamps();

                $table->unique(['workspace_id', 'folder_path']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_positions');
    }
};
