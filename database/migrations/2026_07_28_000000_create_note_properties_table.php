<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('note_properties')) {
            Schema::create('note_properties', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
                $table->foreignId('note_id')->constrained('notes')->cascadeOnDelete();
                $table->string('name', 191);
                $table->string('type', 32);
                $table->string('value_string', 255)->nullable();
                $table->double('value_numeric')->nullable();
                $table->boolean('value_boolean')->nullable();
                $table->timestamp('value_datetime')->nullable();
                $table->json('value_json')->nullable();
                $table->timestamps();

                $table->unique(['note_id', 'name']);
                $table->index(['workspace_id', 'name']);
                $table->index(['name', 'value_string']);
                $table->index(['name', 'value_numeric']);
                $table->index(['name', 'value_boolean']);
                $table->index(['name', 'value_datetime']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('note_properties');
    }
};
