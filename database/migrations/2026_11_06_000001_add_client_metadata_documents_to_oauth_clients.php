<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client ID Metadata Documents (#411): a client may identify itself with an
 * https URL that publishes its metadata. The row is a cache of that document:
 * `client_id` holds the URL (longer than a DCR id), `kind` tells the two
 * apart, and `metadata_expires_at` says when the document must be fetched again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table): void {
            $table->string('client_id', 512)->change();
            if (! Schema::hasColumn('oauth_clients', 'kind')) {
                $table->string('kind', 8)->default('dcr')->after('client_id');
            }
            if (! Schema::hasColumn('oauth_clients', 'metadata_expires_at')) {
                $table->timestamp('metadata_expires_at')->nullable()->after('registered_ip');
            }
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table): void {
            foreach (['kind', 'metadata_expires_at'] as $column) {
                if (Schema::hasColumn('oauth_clients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
