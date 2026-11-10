<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LGPD art. 18 VI: a person asks for their account to be deleted from
 * "Your profile → Your data". An administrator carries it out (the privacy
 * policy promises 30 days); null = no pending request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'deletion_requested_at')) {
                $table->timestamp('deletion_requested_at')->nullable()->after('terms_version')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'deletion_requested_at')) {
                $table->dropIndex(['deletion_requested_at']);
                $table->dropColumn('deletion_requested_at');
            }
        });
    }
};
