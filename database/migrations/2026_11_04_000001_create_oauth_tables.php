<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Embedded OAuth 2.1 authorization server for MCP clients (brain plan PR 5).
 * Every secret (client secret, authorization code, refresh token) is stored
 * only as a SHA-256 hash; access tokens live in `machine_tokens` (kind=oauth)
 * like manual tokens, so the same resolver and scopes apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('oauth_clients')) {
            Schema::create('oauth_clients', function (Blueprint $table): void {
                $table->id();
                $table->string('client_id', 64)->unique();
                $table->string('name', 191);
                $table->json('redirect_uris');
                $table->string('auth_method', 32)->default('none');
                $table->string('secret_hash', 64)->nullable();
                $table->string('registered_ip', 45)->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('oauth_grants')) {
            Schema::create('oauth_grants', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('subject_id', 191);
                $table->foreignId('oauth_client_id')->constrained('oauth_clients')->cascadeOnDelete();
                $table->json('workspace_ids');
                $table->json('scopes');
                $table->json('write_paths')->nullable();
                $table->string('client_slug', 40)->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->index(['subject_id', 'revoked_at']);
            });
        }

        if (! Schema::hasTable('oauth_auth_codes')) {
            Schema::create('oauth_auth_codes', function (Blueprint $table): void {
                $table->id();
                $table->string('code_hash', 64)->unique();
                $table->foreignId('oauth_grant_id')->constrained('oauth_grants')->cascadeOnDelete();
                $table->text('redirect_uri');
                $table->string('code_challenge', 128);
                $table->string('resource', 512)->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('oauth_refresh_tokens')) {
            Schema::create('oauth_refresh_tokens', function (Blueprint $table): void {
                $table->id();
                $table->string('token_hash', 64)->unique();
                $table->foreignId('oauth_grant_id')->constrained('oauth_grants')->cascadeOnDelete();
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('machine_tokens', function (Blueprint $table): void {
            if (! Schema::hasColumn('machine_tokens', 'oauth_grant_id')) {
                $table->foreignId('oauth_grant_id')->nullable()->after('client_slug')->constrained('oauth_grants')->cascadeOnDelete();
            }
        });

        if (! $this->hasForeignKey('machine_tokens', 'machine_tokens_client_id_foreign')) {
            Schema::table('machine_tokens', function (Blueprint $table): void {
                $table->foreign('client_id')->references('id')->on('oauth_clients')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('machine_tokens', function (Blueprint $table): void {
            if ($this->hasForeignKey('machine_tokens', 'machine_tokens_client_id_foreign')) {
                $table->dropForeign('machine_tokens_client_id_foreign');
            }
            if (Schema::hasColumn('machine_tokens', 'oauth_grant_id')) {
                $table->dropConstrainedForeignId('oauth_grant_id');
            }
        });

        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_auth_codes');
        Schema::dropIfExists('oauth_grants');
        Schema::dropIfExists('oauth_clients');
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
