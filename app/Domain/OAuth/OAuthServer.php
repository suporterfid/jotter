<?php

namespace App\Domain\OAuth;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\CredentialContext;
use App\Models\MachineToken;
use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use App\Models\OAuthGrant;
use App\Models\OAuthRefreshToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lean OAuth 2.1 authorization server for MCP clients (brain plan PR 5):
 * dynamic client registration (RFC 7591), authorization code + PKCE S256,
 * rotating refresh tokens with reuse detection, and revocation (RFC 7009).
 * Opaque tokens, stored only as SHA-256 hashes; no JWT, no signing keys.
 *
 * Access tokens are `machine_tokens` rows (kind=oauth) carrying the grant's
 * scopes, workspaces, write paths and client slug, so MCP enforces them with
 * the same CredentialScope rules as manual tokens.
 */
final class OAuthServer
{
    public const ACCESS_PREFIX = 'jt_oat_';
    public const REFRESH_PREFIX = 'jt_ort_';
    public const CODE_PREFIX = 'jt_oac_';
    public const CLIENT_PREFIX = 'jtc_';
    public const SECRET_PREFIX = 'jt_ocs_';

    /** Scopes an OAuth grant can carry (decision D8: never api:*). */
    public const GRANTABLE_SCOPES = [CredentialContext::SCOPE_MCP_READ, CredentialContext::SCOPE_MCP_WRITE];
    public const SUPPORTED_SCOPES = [CredentialContext::SCOPE_MCP_READ, CredentialContext::SCOPE_MCP_WRITE, 'offline_access'];
    public const AUTH_METHODS = ['none', 'client_secret_post', 'client_secret_basic'];

    public function __construct(
        private readonly RedirectUriPolicy $redirects = new RedirectUriPolicy,
        private readonly AuditRecorder $audit = new AuditRecorder,
    ) {}

    public static function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public static function resource(): string
    {
        return self::issuer().'/api/mcp';
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * RFC 7591 registration. Returns the client and, for confidential clients,
     * the plaintext secret (shown once).
     *
     * @param array<string, mixed> $metadata
     * @return array{client: OAuthClient, secret: string|null}
     */
    public function register(array $metadata, ?string $ip): array
    {
        $redirectUris = $metadata['redirect_uris'] ?? null;
        if (! is_array($redirectUris) || $redirectUris === [] || count($redirectUris) > 10) {
            throw new OAuthError('invalid_redirect_uri', 'redirect_uris must list 1 to 10 URIs.');
        }
        foreach ($redirectUris as $uri) {
            if (! is_string($uri) || ! $this->redirects->allowedForRegistration($uri)) {
                throw new OAuthError('invalid_redirect_uri', 'Redirect URI not allowed on this server: '.(is_string($uri) ? $uri : '(invalid)').'. Ask the operator to add it to JOTTER_OAUTH_REDIRECT_ALLOWLIST.');
            }
        }

        $method = (string) ($metadata['token_endpoint_auth_method'] ?? 'none');
        if (! in_array($method, self::AUTH_METHODS, true)) {
            throw new OAuthError('invalid_client_metadata', 'token_endpoint_auth_method must be one of: '.implode(', ', self::AUTH_METHODS).'.');
        }

        foreach ((array) ($metadata['grant_types'] ?? ['authorization_code']) as $grantType) {
            if (! in_array($grantType, ['authorization_code', 'refresh_token'], true)) {
                throw new OAuthError('invalid_client_metadata', 'Only authorization_code and refresh_token grants are supported.');
            }
        }

        $name = trim((string) ($metadata['client_name'] ?? ''));
        $name = $name === '' ? 'MCP client' : mb_substr($name, 0, 120);
        $secret = $method === 'none' ? null : self::SECRET_PREFIX.Str::random(48);

        $client = OAuthClient::create([
            'client_id' => self::CLIENT_PREFIX.Str::random(32),
            'name' => $name,
            'redirect_uris' => array_values($redirectUris),
            'auth_method' => $method,
            'secret_hash' => $secret === null ? null : self::hash($secret),
            'registered_ip' => $ip,
        ]);

        $this->audit->record(event: AuditEvent::OAUTH_CLIENT_REGISTERED, metadata: [
            'client_id' => $client->client_id, 'name' => $client->name, 'redirect_uris' => $client->redirect_uris, 'ip' => $ip,
        ]);

        return ['client' => $client, 'secret' => $secret];
    }

    /**
     * Client authentication at the token and revocation endpoints. Public
     * clients (`none`) identify themselves with client_id only.
     */
    public function authenticateClient(?string $clientId, ?string $secret, string $usedMethod): OAuthClient
    {
        $client = $clientId === null || $clientId === '' ? null : OAuthClient::query()->where('client_id', $clientId)->first();
        if ($client === null) {
            throw new OAuthError('invalid_client', 'Unknown client.', 401);
        }

        if ($client->auth_method !== 'none') {
            if ($secret === null || $client->secret_hash === null || ! hash_equals($client->secret_hash, self::hash($secret))) {
                throw new OAuthError('invalid_client', 'Client authentication failed.', 401);
            }
        }

        if ($client->auth_method !== 'none' && $usedMethod !== $client->auth_method) {
            // Accept both secret methods; a confidential client may send either.
            if (! in_array($usedMethod, ['client_secret_post', 'client_secret_basic'], true)) {
                throw new OAuthError('invalid_client', 'Client authentication failed.', 401);
            }
        }

        return $client;
    }

    /**
     * Creates the grant a person consented to and a single-use code for it.
     *
     * @param list<int> $workspaceIds
     * @param list<string> $scopes
     * @param list<array{glob: string, ops: list<string>}> $writePaths
     */
    public function approve(
        OAuthClient $client,
        int $tenantId,
        string $subjectId,
        array $workspaceIds,
        array $scopes,
        array $writePaths,
        ?string $clientSlug,
        ?\DateTimeInterface $expiresAt,
        string $redirectUri,
        string $codeChallenge,
        ?string $resource,
        ?int $userId = null,
    ): string {
        $scopes = array_values(array_intersect(self::GRANTABLE_SCOPES, $scopes));
        if (! in_array(CredentialContext::SCOPE_MCP_READ, $scopes, true)) {
            $scopes = [CredentialContext::SCOPE_MCP_READ, ...$scopes];
        }

        $code = self::CODE_PREFIX.Str::random(48);

        DB::transaction(function () use ($client, $tenantId, $subjectId, $userId, $workspaceIds, $scopes, $writePaths, $clientSlug, $expiresAt, $redirectUri, $codeChallenge, $resource, $code): void {
            $grant = OAuthGrant::create([
                'tenant_id' => $tenantId,
                'subject_id' => $subjectId,
                'user_id' => $userId,
                'oauth_client_id' => $client->id,
                'workspace_ids' => array_values(array_map('intval', $workspaceIds)),
                'scopes' => $scopes,
                'write_paths' => in_array(CredentialContext::SCOPE_MCP_WRITE, $scopes, true) && $writePaths !== [] ? $writePaths : null,
                'client_slug' => $clientSlug,
                'expires_at' => $expiresAt,
            ]);

            OAuthAuthCode::create([
                'code_hash' => self::hash($code),
                'oauth_grant_id' => $grant->id,
                'redirect_uri' => $redirectUri,
                'code_challenge' => $codeChallenge,
                'resource' => $resource,
                'expires_at' => now()->addSeconds((int) config('jotter.oauth.code_ttl_seconds', 60)),
            ]);

            $this->audit->record(event: AuditEvent::OAUTH_GRANT_CREATED, tenantId: $tenantId, actorId: $subjectId, metadata: [
                'grant_id' => $grant->id, 'client_id' => $client->client_id, 'client_name' => $client->name,
                'workspace_ids' => $grant->workspace_ids, 'scopes' => $scopes, 'client_slug' => $clientSlug,
            ]);
        });

        return $code;
    }

    /**
     * authorization_code grant: single use, 60 s, PKCE S256, exact redirect_uri.
     * Replaying a used code revokes the whole grant (RFC 6749 §4.1.2).
     *
     * @return array<string, mixed> token response
     */
    public function exchangeCode(OAuthClient $client, string $code, string $redirectUri, string $codeVerifier, ?string $resource): array
    {
        /** @var OAuthAuthCode|null $record */
        $record = OAuthAuthCode::query()->where('code_hash', self::hash($code))->first();
        if ($record === null || $record->grant === null || $record->grant->oauth_client_id !== $client->id) {
            throw new OAuthError('invalid_grant', 'Unknown or foreign authorization code.');
        }

        if ($record->used_at !== null) {
            $this->revokeGrant($record->grant, 'code_reuse');

            throw new OAuthError('invalid_grant', 'Authorization code already used; the grant was revoked.');
        }

        $record->forceFill(['used_at' => now()])->save();

        if ($record->expires_at->isPast()) {
            throw new OAuthError('invalid_grant', 'Authorization code expired.');
        }
        if (! hash_equals($record->redirect_uri, $redirectUri)) {
            throw new OAuthError('invalid_grant', 'redirect_uri does not match the authorization request.');
        }
        if ($codeVerifier === '' || ! hash_equals($record->code_challenge, self::pkceChallenge($codeVerifier))) {
            throw new OAuthError('invalid_grant', 'PKCE verification failed.');
        }
        if ($resource !== null && $resource !== '' && ! self::resourceMatches($resource)) {
            throw new OAuthError('invalid_target', 'resource must be '.self::resource().'.');
        }
        if (! $record->grant->isActive()) {
            throw new OAuthError('invalid_grant', 'The grant is no longer active.');
        }

        return $this->issueTokens($record->grant, $client);
    }

    /**
     * refresh_token grant with rotation. Presenting an already-rotated refresh
     * token revokes the grant, its access tokens and its refresh tokens.
     *
     * @return array<string, mixed>
     */
    public function refresh(OAuthClient $client, string $refreshToken): array
    {
        /** @var OAuthRefreshToken|null $record */
        $record = OAuthRefreshToken::query()->where('token_hash', self::hash($refreshToken))->first();
        if ($record === null || $record->grant === null || $record->grant->oauth_client_id !== $client->id) {
            throw new OAuthError('invalid_grant', 'Unknown or foreign refresh token.');
        }

        if ($record->used_at !== null) {
            $this->revokeGrant($record->grant, 'refresh_reuse');

            throw new OAuthError('invalid_grant', 'Refresh token already used; the grant was revoked.');
        }

        if ($record->expires_at->isPast() || ! $record->grant->isActive()) {
            throw new OAuthError('invalid_grant', 'Refresh token expired or grant revoked.');
        }

        $record->forceFill(['used_at' => now()])->save();

        return $this->issueTokens($record->grant, $client);
    }

    /**
     * RFC 7009: revoking a refresh token revokes its grant; revoking an access
     * token revokes that token. Unknown tokens are ignored.
     */
    public function revokeToken(OAuthClient $client, string $token): void
    {
        $refresh = OAuthRefreshToken::query()->where('token_hash', self::hash($token))->first();
        if ($refresh !== null && $refresh->grant !== null && $refresh->grant->oauth_client_id === $client->id) {
            $this->revokeGrant($refresh->grant, 'client_revoked');

            return;
        }

        MachineToken::query()
            ->where('token_hash', MachineToken::hashToken($token))
            ->where('kind', MachineToken::KIND_OAUTH)
            ->where('client_id', $client->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revokeGrant(OAuthGrant $grant, string $reason, ?string $actorId = null): void
    {
        DB::transaction(function () use ($grant): void {
            $now = now();
            $grant->forceFill(['revoked_at' => $grant->revoked_at ?? $now])->save();
            MachineToken::query()->where('oauth_grant_id', $grant->id)->whereNull('revoked_at')->update(['revoked_at' => $now]);
            OAuthRefreshToken::query()->where('oauth_grant_id', $grant->id)->whereNull('used_at')->update(['used_at' => $now]);
        });

        $this->audit->record(event: AuditEvent::OAUTH_GRANT_REVOKED, tenantId: $grant->tenant_id, actorId: $actorId ?? $grant->subject_id, metadata: [
            'grant_id' => $grant->id, 'reason' => $reason,
        ]);
    }

    public static function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function resourceMatches(string $resource): bool
    {
        return rtrim($resource, '/') === self::resource();
    }

    /**
     * @return array<string, mixed>
     */
    private function issueTokens(OAuthGrant $grant, OAuthClient $client): array
    {
        $access = self::ACCESS_PREFIX.Str::random(48);
        $refresh = self::REFRESH_PREFIX.Str::random(48);
        $accessTtl = max(60, (int) config('jotter.oauth.access_ttl_seconds', 3600));
        $accessExpiry = now()->addSeconds($accessTtl);
        if ($grant->expires_at !== null && $grant->expires_at->lt($accessExpiry)) {
            $accessExpiry = $grant->expires_at->copy();
        }
        $refreshExpiry = now()->addDays(max(1, (int) config('jotter.oauth.refresh_ttl_days', 30)));
        if ($grant->expires_at !== null && $grant->expires_at->lt($refreshExpiry)) {
            $refreshExpiry = $grant->expires_at->copy();
        }

        DB::transaction(function () use ($grant, $client, $access, $refresh, $accessExpiry, $refreshExpiry): void {
            MachineToken::create([
                'tenant_id' => $grant->tenant_id,
                'subject_id' => $grant->subject_id,
                'user_id' => $grant->user_id,
                'name' => 'OAuth: '.$client->name,
                'kind' => MachineToken::KIND_OAUTH,
                'scopes' => $grant->scopes,
                'workspace_ids' => $grant->workspace_ids,
                'write_paths' => $grant->write_paths,
                'client_id' => $client->id,
                'client_slug' => $grant->client_slug,
                'oauth_grant_id' => $grant->id,
                'expires_at' => $accessExpiry,
                'token_hash' => MachineToken::hashToken($access),
            ]);

            OAuthRefreshToken::create([
                'token_hash' => self::hash($refresh),
                'oauth_grant_id' => $grant->id,
                'expires_at' => $refreshExpiry,
            ]);

            $client->forceFill(['last_used_at' => now()])->save();
        });

        $this->audit->record(event: AuditEvent::OAUTH_TOKEN_ISSUED, tenantId: $grant->tenant_id, actorId: $grant->subject_id, metadata: [
            'grant_id' => $grant->id, 'client_id' => $client->client_id, 'expires_at' => $accessExpiry->toIso8601String(),
        ]);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => max(1, (int) now()->diffInSeconds($accessExpiry, true)),
            'refresh_token' => $refresh,
            'scope' => implode(' ', $grant->scopes),
        ];
    }
}
