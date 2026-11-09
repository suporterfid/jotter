<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LGPD: records which version of the Terms/Privacy texts (JOTTER_TERMS_VERSION)
 * the user accepted, next to `terms_accepted_at`. Null = never accepted a
 * versioned text (accounts created before versioning, or by an administrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'terms_version')) {
                $table->string('terms_version', 64)->nullable()->after('terms_accepted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'terms_version')) {
                $table->dropColumn('terms_version');
            }
        });
    }
};
