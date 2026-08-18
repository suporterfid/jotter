<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('notifications', 'dedupe_key')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->string('dedupe_key', 191)->nullable()->after('data');
                $table->index('dedupe_key', 'notifications_dedupe_key_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('notifications', 'dedupe_key')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->dropIndex('notifications_dedupe_key_index');
                $table->dropColumn('dedupe_key');
            });
        }
    }
};
