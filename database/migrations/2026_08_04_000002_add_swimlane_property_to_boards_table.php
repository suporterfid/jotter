<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('boards', 'swimlane_property')) {
            Schema::table('boards', function (Blueprint $table) {
                $table->string('swimlane_property')->nullable()->after('group_property');
            });
        }
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('swimlane_property');
        });
    }
};
