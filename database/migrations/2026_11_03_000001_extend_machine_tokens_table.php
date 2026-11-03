<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scoped credentials (brain multi-agent plan, PR 1 / decision D1–D3).
 *
 * Existing tokens become `["mcp:read"]` only (D1). Tokens owned by an
 * administrator keep reaching what they reach today through MCP — every
 * workspace of the token's tenant — via an explicit `workspace_ids` list,
 * because credentials no longer inherit the administrator bypass (D2/D3).
 * `client_id` has no foreign key yet: `oauth_clients` arrives with the OAuth PR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_tokens', function (Blueprint $table): void {
            if (! Schema::hasColumn('machine_tokens', 'kind')) {
                $table->string('kind', 16)->default('manual')->after('name');
            }
            if (! Schema::hasColumn('machine_tokens', 'scopes')) {
                $table->json('scopes')->nullable()->after('kind');
            }
            if (! Schema::hasColumn('machine_tokens', 'workspace_ids')) {
                $table->json('workspace_ids')->nullable()->after('scopes');
            }
            if (! Schema::hasColumn('machine_tokens', 'write_paths')) {
                $table->json('write_paths')->nullable()->after('workspace_ids');
            }
            if (! Schema::hasColumn('machine_tokens', 'client_id')) {
                $table->unsignedBigInteger('client_id')->nullable()->after('write_paths');
            }
            if (! Schema::hasColumn('machine_tokens', 'client_slug')) {
                $table->string('client_slug', 40)->nullable()->after('client_id');
            }
            if (! Schema::hasColumn('machine_tokens', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('revoked_at');
            }
            if (! Schema::hasColumn('machine_tokens', 'last_used_at')) {
                $table->timestamp('last_used_at')->nullable()->after('expires_at');
            }
        });

        DB::table('machine_tokens')->whereNull('scopes')->update(['scopes' => json_encode(['mcp:read'])]);

        $adminIds = DB::table('users')->where('is_admin', true)->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        if ($adminIds === []) {
            return;
        }

        DB::table('machine_tokens')
            ->whereNull('workspace_ids')
            ->whereIn('subject_id', $adminIds)
            ->orderBy('id')
            ->get(['id', 'tenant_id'])
            ->each(static function (object $token): void {
                $workspaceIds = DB::table('workspaces')->where('tenant_id', $token->tenant_id)->orderBy('id')->pluck('id')
                    ->map(static fn ($id): int => (int) $id)->all();
                DB::table('machine_tokens')->where('id', $token->id)->update(['workspace_ids' => json_encode($workspaceIds)]);
            });
    }

    public function down(): void
    {
        Schema::table('machine_tokens', function (Blueprint $table): void {
            foreach (['kind', 'scopes', 'workspace_ids', 'write_paths', 'client_id', 'client_slug', 'expires_at', 'last_used_at'] as $column) {
                if (Schema::hasColumn('machine_tokens', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
