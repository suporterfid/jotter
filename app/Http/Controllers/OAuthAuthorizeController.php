<?php

namespace App\Http\Controllers;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\CredentialContext;
use App\Domain\Auth\InvalidMachineTokenOptions;
use App\Domain\Auth\MachineTokenOptions;
use App\Domain\Auth\Providers\LocalIdentityProvider;
use App\Domain\OAuth\ClientMetadataDocuments;
use App\Domain\OAuth\OAuthError;
use App\Domain\OAuth\OAuthLoginReturn;
use App\Domain\OAuth\OAuthServer;
use App\Domain\OAuth\RedirectUriPolicy;
use App\Models\OAuthClient;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Branding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Authorization endpoint with the consent screen. The person must be signed in
 * (local, OIDC or GrandpaSSOn session); without a session the request is kept
 * in the session and the SPA login returns here afterwards.
 *
 * Consent defaults follow the brain plan: no workspace pre-selected, read
 * checked, write optional, write paths pre-filled with `inbox/{client}/**` and
 * `diario/**` (append only).
 */
final class OAuthAuthorizeController extends Controller
{
    private const SESSION_PENDING = 'oauth.pending.';
    private const PENDING_TTL_SECONDS = 600;
    private const EXPIRY_OPTIONS = ['30d', '90d', '180d', '1y', 'never'];

    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly OAuthServer $server,
        private readonly RedirectUriPolicy $redirects = new RedirectUriPolicy,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        try {
            $client = $this->findClient((string) $request->query('client_id', ''));
        } catch (OAuthError $error) {
            return $this->errorPage('oauth.error_client_metadata', ['reason' => $error->description]);
        }
        $redirectUri = (string) $request->query('redirect_uri', '');

        // Until client and redirect_uri are verified, errors are shown here,
        // never sent to an unverified redirect target.
        if ($client === null) {
            return $this->errorPage('oauth.error_unknown_client');
        }
        if ($redirectUri === '' || ! $this->redirects->matchesRegistered($redirectUri, (array) $client->redirect_uris)) {
            return $this->errorPage('oauth.error_redirect_mismatch');
        }

        $state = $request->query('state') !== null ? (string) $request->query('state') : null;
        if ((string) $request->query('response_type') !== 'code') {
            return $this->redirectError($redirectUri, 'unsupported_response_type', 'Only response_type=code is supported.', $state);
        }
        $challenge = (string) $request->query('code_challenge', '');
        if ($challenge === '' || (string) $request->query('code_challenge_method') !== 'S256' || preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $challenge) !== 1) {
            return $this->redirectError($redirectUri, 'invalid_request', 'PKCE with code_challenge_method=S256 is required.', $state);
        }
        $resource = $request->query('resource') !== null ? (string) $request->query('resource') : null;
        if ($resource !== null && $resource !== '' && ! OAuthServer::resourceMatches($resource)) {
            return $this->redirectError($redirectUri, 'invalid_target', 'resource must be '.OAuthServer::resource().'.', $state);
        }

        $subject = $this->subject($request);
        if ($subject === null) {
            $returnTo = '/oauth/authorize?'.(string) $request->getQueryString();
            OAuthLoginReturn::remember($request, $returnTo);

            return redirect()->to(rtrim(url('/'), '/').'/?login=1&return_to='.rawurlencode($returnTo));
        }

        $requestedScopes = preg_split('/\s+/', trim((string) $request->query('scope', ''))) ?: [];
        $pendingId = Str::random(32);
        $request->session()->put(self::SESSION_PENDING.$pendingId, [
            'client' => $client->id,
            'subject' => $subject->subjectId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $challenge,
            'resource' => $resource,
            'expires' => time() + self::PENDING_TTL_SECONDS,
        ]);
        OAuthLoginReturn::forget($request);

        return $this->consent($request, $subject, $client, $pendingId, [
            'workspace_ids' => [],
            'write' => in_array(CredentialContext::SCOPE_MCP_WRITE, $requestedScopes, true),
            'write_paths' => "inbox/{client}/**:create,append,update\ndiario/**:append",
            'client_slug' => Str::slug($client->name) ?: 'mcp-client',
            'expires' => '90d',
        ], []);
    }

    public function decide(Request $request): Response|RedirectResponse
    {
        $pendingId = (string) $request->input('request_id', '');
        $pending = $request->session()->get(self::SESSION_PENDING.$pendingId);
        $subject = $this->subject($request);

        if (! is_array($pending) || $subject === null || $pending['subject'] !== $subject->subjectId || $pending['expires'] < time()) {
            return $this->errorPage('oauth.error_request_expired');
        }

        $client = OAuthClient::query()->find($pending['client']);
        if ($client === null) {
            return $this->errorPage('oauth.error_unknown_client');
        }

        if ($request->input('decision') !== 'approve') {
            $request->session()->forget(self::SESSION_PENDING.$pendingId);

            return $this->redirectError($pending['redirect_uri'], 'access_denied', 'The user denied the request.', $pending['state']);
        }

        $workspaceIds = array_values(array_unique(array_map('intval', (array) $request->input('workspace_ids', []))));
        $write = $request->boolean('write');
        $old = [
            'workspace_ids' => $workspaceIds,
            'write' => $write,
            'write_paths' => (string) $request->input('write_paths', ''),
            'client_slug' => (string) $request->input('client_slug', ''),
            'expires' => (string) $request->input('expires', '90d'),
        ];

        $offered = $this->offeredWorkspaces($subject)->keyBy('id');
        $chosen = collect($workspaceIds)->map(fn (int $id) => $offered->get($id))->filter();
        $errors = [];
        if ($chosen->isEmpty() || $chosen->count() !== count($workspaceIds)) {
            $errors['workspaces'] = __('oauth.error_pick_workspace');
        } elseif ($chosen->pluck('tenant_id')->unique()->count() !== 1) {
            $errors['workspaces'] = __('oauth.error_one_tenant');
        }
        if (! in_array($old['expires'], self::EXPIRY_OPTIONS, true)) {
            $old['expires'] = '90d';
        }

        $options = null;
        if ($errors === []) {
            $tenant = Tenant::query()->findOrFail($chosen->first()->tenant_id);
            $writePaths = $write ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\n/', $old['write_paths']) ?: []))) : [];
            try {
                /** @var User $user */
                $user = $subject->user;
                $options = MachineTokenOptions::fromInput(
                    owner: $user,
                    tenant: $tenant,
                    scopes: $write ? [CredentialContext::SCOPE_MCP_READ, CredentialContext::SCOPE_MCP_WRITE] : [CredentialContext::SCOPE_MCP_READ],
                    workspaces: $workspaceIds,
                    writePaths: $writePaths,
                    clientSlug: $old['client_slug'],
                    expires: $old['expires'],
                );
            } catch (InvalidMachineTokenOptions $exception) {
                $errors = $exception->errors();
            }
        }

        if ($options === null) {
            return $this->consent($request, $subject, $client, $pendingId, $old, $errors);
        }

        $request->session()->forget(self::SESSION_PENDING.$pendingId);
        $code = $this->server->approve(
            client: $client,
            tenantId: (int) $chosen->first()->tenant_id,
            subjectId: $subject->subjectId,
            workspaceIds: (array) $options->workspaceIds,
            scopes: $options->scopes,
            writePaths: $options->writePaths,
            clientSlug: $options->clientSlug,
            expiresAt: $options->expiresAt,
            redirectUri: $pending['redirect_uri'],
            codeChallenge: $pending['code_challenge'],
            resource: $pending['resource'],
            userId: (int) $user->id,
        );

        return redirect()->away($this->withQuery($pending['redirect_uri'], array_filter([
            'code' => $code,
            'state' => $pending['state'],
            'iss' => OAuthServer::issuer(),
        ], static fn ($value): bool => $value !== null)));
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function consent(Request $request, AuthenticatedSubject $subject, OAuthClient $client, string $pendingId, array $old, array $errors): Response
    {
        $this->applyLocale($subject);
        $pendingRedirect = (string) ($request->session()->get(self::SESSION_PENDING.$pendingId)['redirect_uri'] ?? '');
        $redirectHost = (string) (parse_url($pendingRedirect, PHP_URL_HOST) ?? '');

        return response()->view('oauth.consent', [
            'brand' => Branding::configured(),
            'client' => $client,
            'redirectHost' => $redirectHost,
            // A metadata document client is vouched for by the host serving it;
            // client_name is only what that document claims.
            'clientHost' => $client->kind === OAuthClient::KIND_CIMD ? (string) parse_url($client->client_id, PHP_URL_HOST) : null,
            'isLoopback' => $this->redirects->isLoopbackUri($pendingRedirect),
            'workspaces' => $this->offeredWorkspaces($subject),
            'tenants' => Tenant::query()->get(['id', 'name', 'slug'])->keyBy('id'),
            'requestId' => $pendingId,
            'old' => $old,
            'formErrors' => $errors,
            'expiryOptions' => self::EXPIRY_OPTIONS,
            'subject' => $subject,
        ], $errors === [] ? 200 : 422, ['Cache-Control' => 'no-store', 'X-Frame-Options' => 'DENY']);
    }

    /**
     * @param array<string, string> $replace
     */
    private function errorPage(string $messageKey, array $replace = []): Response
    {
        return response()->view('oauth.error', ['brand' => Branding::configured(), 'message' => __($messageKey, $replace)], 400, ['Cache-Control' => 'no-store']);
    }

    /**
     * A registered (DCR) client, or, when client_id is an https URL, the client
     * described by its metadata document (#411), fetched or taken from cache.
     */
    private function findClient(string $clientId): ?OAuthClient
    {
        if ($clientId === '') {
            return null;
        }
        if (ClientMetadataDocuments::enabled() && ClientMetadataDocuments::isMetadataUrl($clientId)) {
            return app(ClientMetadataDocuments::class)->resolve($clientId);
        }

        return OAuthClient::query()->where('client_id', $clientId)->where('kind', OAuthClient::KIND_DCR)->first();
    }

    private function redirectError(string $redirectUri, string $error, string $description, ?string $state): RedirectResponse
    {
        return redirect()->away($this->withQuery($redirectUri, array_filter([
            'error' => $error,
            'error_description' => $description,
            'state' => $state,
            'iss' => OAuthServer::issuer(),
        ], static fn ($value): bool => $value !== null)));
    }

    /**
     * @param array<string, string> $params
     */
    private function withQuery(string $uri, array $params): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * A browser session only: bearer tokens never authorize a consent.
     */
    private function subject(Request $request): ?AuthenticatedSubject
    {
        if ($request->bearerToken() !== null) {
            return null;
        }

        $subject = $this->identityProvider->resolveIdentity($request);

        return $subject !== null && $subject->credential === null && $subject->user !== null ? $subject : null;
    }

    /**
     * Workspaces the person may grant: explicit memberships, or every
     * workspace for an administrator.
     *
     * @return Collection<int, Workspace>
     */
    private function offeredWorkspaces(AuthenticatedSubject $subject): Collection
    {
        $ids = (new LocalIdentityProvider)->accessibleWorkspaceIds($subject);
        $query = Workspace::query()->orderBy('tenant_id')->orderBy('name');
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query->get(['id', 'tenant_id', 'slug', 'name']);
    }

    private function applyLocale(AuthenticatedSubject $subject): void
    {
        $locale = $subject->locale;
        if (in_array($locale, ['en', 'pt-BR'], true)) {
            app()->setLocale($locale);
        }
    }
}
