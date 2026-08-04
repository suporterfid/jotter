<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('boards')) {
            Schema::create('boards', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->string('name');
                $table->string('group_property')->nullable();
                $table->string('filter_property')->nullable();
                $table->string('filter_value')->nullable();
                $table->integer('sort_position')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('boards');
    }
};
