<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self sign-up with administrator approval (#390). `signup_status` is null for
 * accounts created by an administrator or a provider, `pending` until an
 * administrator approves (then `approved`). Rejected requests are deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'signup_status')) {
                $table->string('signup_status', 16)->nullable()->after('is_active')->index();
            }
            if (! Schema::hasColumn('users', 'signup_requested_at')) {
                $table->timestamp('signup_requested_at')->nullable()->after('signup_status');
            }
            if (! Schema::hasColumn('users', 'terms_accepted_at')) {
                $table->timestamp('terms_accepted_at')->nullable()->after('signup_requested_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (['signup_status', 'signup_requested_at', 'terms_accepted_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
