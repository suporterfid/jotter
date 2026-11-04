<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The local user behind an OAuth grant and its tokens. With GrandpaSSOn the
 * subject id is the SSO user's UUID (memberships are keyed by it), not the
 * local `users.id`, so the token resolver needs both: subject_id for access
 * checks, user_id to load the account. Null for manual tokens and older rows,
 * whose subject_id is already the local user id.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['oauth_grants', 'machine_tokens'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'user_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->foreignId('user_id')->nullable()->after('subject_id')->constrained('users')->cascadeOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['machine_tokens', 'oauth_grants'] as $tableName) {
            if (Schema::hasColumn($tableName, 'user_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropConstrainedForeignId('user_id');
                });
            }
        }
    }
};
