<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace storage quotas (#390). `storage_quota_mb`: null uses
 * JOTTER_WORKSPACE_QUOTA_MB, 0 means unlimited. `storage_bytes` is the measured
 * vault size, kept current by writes and re-measured hourly (workspace:storage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            if (! Schema::hasColumn('workspaces', 'storage_quota_mb')) {
                $table->unsignedInteger('storage_quota_mb')->nullable()->after('vault_path');
            }
            if (! Schema::hasColumn('workspaces', 'storage_bytes')) {
                $table->unsignedBigInteger('storage_bytes')->nullable()->after('storage_quota_mb');
            }
            if (! Schema::hasColumn('workspaces', 'storage_measured_at')) {
                $table->timestamp('storage_measured_at')->nullable()->after('storage_bytes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            foreach (['storage_quota_mb', 'storage_bytes', 'storage_measured_at'] as $column) {
                if (Schema::hasColumn('workspaces', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
