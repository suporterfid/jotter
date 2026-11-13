<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pluggable vault stores (#436, docs/decisions.md "Pluggable vault stores").
 * A tenant registers stores; a workspace points at one, or at none (its local
 * `vault_path`, as before). `credentials` is encrypted with APP_KEY (cast
 * `encrypted:array`) and never returned by the API. `remote_version` keeps the
 * store's change token (ETag / blob SHA) to detect external edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vault_stores')) {
            Schema::create('vault_stores', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 191);
                $table->string('driver', 32);
                $table->json('settings')->nullable();
                $table->text('credentials')->nullable();
                $table->string('status', 16)->default('untested');
                $table->timestamp('last_checked_at')->nullable();
                $table->string('last_error', 1000)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'name']);
            });
        }

        Schema::table('workspaces', function (Blueprint $table): void {
            if (! Schema::hasColumn('workspaces', 'vault_store_id')) {
                // The API refuses to delete a store in use; nullOnDelete only lets a
                // tenant deletion cascade without ordering problems.
                $table->foreignId('vault_store_id')->nullable()->after('vault_path')
                    ->constrained('vault_stores')->nullOnDelete();
            }
            if (! Schema::hasColumn('workspaces', 'vault_prefix')) {
                $table->string('vault_prefix', 512)->default('')->after('vault_store_id');
            }
            if (! Schema::hasColumn('workspaces', 'storage_state')) {
                $table->string('storage_state', 16)->default('active')->after('vault_prefix');
            }
        });

        foreach (['notes', 'attachments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'remote_version')) {
                    $table->string('remote_version', 128)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['notes', 'attachments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'remote_version')) {
                    $table->dropColumn('remote_version');
                }
            });
        }

        Schema::table('workspaces', function (Blueprint $table): void {
            if (Schema::hasColumn('workspaces', 'vault_store_id')) {
                $table->dropConstrainedForeignId('vault_store_id');
            }
            foreach (['vault_prefix', 'storage_state'] as $column) {
                if (Schema::hasColumn('workspaces', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('vault_stores');
    }
};
