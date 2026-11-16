<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Vault\Store\GitHub\GitHubApiError;
use App\Domain\Vault\Store\GitHub\GitHubAppAuth;
use App\Domain\Vault\Store\GitHub\GitHubAppRegistry;
use App\Domain\Vault\Store\TenantStorageAccess;
use App\Domain\Vault\Store\VaultStoreDrivers;
use App\Models\GitHubAppRegistration;
use App\Models\GitHubInstallation;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connecting a tenant to GitHub (#439, plan §4.2–§4.3).
 *
 * App (hosted): Storage → Connect GitHub → `installations/new?state=<nonce>`
 * → GitHub → Setup URL (`/api/github/setup`). The state is single-use, expires
 * in 15 minutes and is bound to the tenant and the admin who started; the
 * installation is then bound to the tenant, and only then can a store use it.
 *
 * PAT (self-hosted): the store form takes the token; this controller only
 * shows the URL and secret for the repository webhook.
 *
 * G1: the instance administrator registers the App from Administration →
 * Storage (manifest flow); it is kept in the database unless .env sets one.
 */
final class GitHubConnectController extends Controller
{
    private const STATE_MINUTES = 15;

    public function __construct(
        private readonly TenantStorageAccess $access = new TenantStorageAccess,
        private readonly VaultStoreDrivers $drivers = new VaultStoreDrivers,
        private readonly GitHubAppAuth $app = new GitHubAppAuth,
        private readonly AuditRecorder $audit = new AuditRecorder,
    ) {}

    /** What the Storage screen needs to offer GitHub. */
    public function config(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeGitHub($request, $tenant);

        return response()->json(['data' => [
            'app_configured' => $this->app->configured() && $this->app->slug() !== null,
            'app_slug' => $this->app->slug(),
            'webhook_url' => url('/webhooks/github'),
            'installations' => GitHubInstallation::query()->where('tenant_id', $tenant->id)->whereNull('suspended_at')
                ->orderBy('id')->get(['installation_id', 'account_login', 'created_at'])->toArray(),
        ]]);
    }

    public function install(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeGitHub($request, $tenant);
        if (! $this->app->configured() || $this->app->slug() === null) {
            return response()->json(['message' => __('messages.github_app_not_configured')], 422);
        }

        $nonce = Str::random(40);
        DB::table('github_install_states')->insert([
            'nonce_hash' => hash('sha256', $nonce),
            'tenant_id' => $tenant->id,
            'actor_id' => $this->actorId($request),
            'expires_at' => now()->addMinutes(self::STATE_MINUTES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $url = rtrim((string) config('jotter.github.web_url', 'https://github.com'), '/')
            .'/apps/'.rawurlencode((string) $this->app->slug()).'/installations/new?state='.rawurlencode($nonce);

        return response()->json(['data' => ['url' => $url, 'expires_in' => self::STATE_MINUTES * 60]]);
    }

    /** The App's Setup URL: GitHub sends the admin back here with installation_id and state. */
    public function setup(Request $request): RedirectResponse|JsonResponse
    {
        $nonce = (string) $request->query('state', '');
        $installationId = (int) $request->query('installation_id', 0);

        $state = $nonce === '' ? null : DB::table('github_install_states')->where('nonce_hash', hash('sha256', $nonce))->first();
        if ($state === null || $state->used_at !== null || now()->greaterThan($state->expires_at) || $installationId <= 0) {
            return response()->json(['message' => __('messages.github_state_invalid')], 403);
        }
        // Single use, even when what follows fails.
        $consumed = DB::table('github_install_states')->where('id', $state->id)->whereNull('used_at')->update(['used_at' => now()]);
        if ($consumed === 0) {
            return response()->json(['message' => __('messages.github_state_invalid')], 403);
        }

        $tenant = Tenant::query()->find($state->tenant_id);
        $this->authorizeGitHub($request, $tenant);
        $actor = $this->actorId($request);
        if (! config('jotter.auth_bypass', false) && $state->actor_id !== null && $actor !== $state->actor_id) {
            return response()->json(['message' => __('messages.github_state_invalid')], 403);
        }

        try {
            $listing = $this->app->installationRepositories($installationId);
        } catch (GitHubApiError $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        GitHubInstallation::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'installation_id' => $installationId],
            ['account_login' => $listing['account'], 'actor_id' => $actor, 'suspended_at' => null],
        );
        $this->audit->record(AuditEvent::VAULT_STORE_GITHUB_INSTALLED, $tenant->id, null, $actor, [
            'installation_id' => $installationId,
            'account' => $listing['account'],
            'repositories' => count($listing['repositories']),
        ]);

        return redirect('/?storage=github&tenant='.$tenant->id.'&installation='.$installationId);
    }

    /** Repositories of an installation bound to this tenant (the admin picks one, then a branch). */
    public function repositories(Request $request, Tenant $tenant, int $installation): JsonResponse
    {
        $this->authorizeGitHub($request, $tenant);
        $bound = GitHubInstallation::query()->where('tenant_id', $tenant->id)->where('installation_id', $installation)->whereNull('suspended_at')->exists();
        if (! $bound) {
            return response()->json(['message' => __('messages.github_installation_unknown')], 404);
        }

        try {
            $listing = $this->app->installationRepositories($installation);
        } catch (GitHubApiError $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json(['data' => $listing]);
    }

    /**
     * URL and secret for the `push` webhook an admin adds to the repository of
     * a PAT store (no App webhook in that mode, plan §4.3).
     */
    public function webhook(Request $request, TenantVaultStore $vaultStore): JsonResponse
    {
        $this->authorizeGitHub($request, $vaultStore->tenant);
        if ($vaultStore->driver !== VaultStoreDrivers::GITHUB) {
            abort(404);
        }
        if (! empty($vaultStore->settings['installation_id'])) {
            return response()->json(['data' => ['mode' => 'app', 'url' => url('/webhooks/github'), 'secret' => null]]);
        }

        $credentials = $vaultStore->decryptedCredentials() ?? [];
        if (empty($credentials['webhook_secret'])) {
            $credentials['webhook_secret'] = Str::random(40);
            $vaultStore->credentials = $credentials;
            $vaultStore->save();
        }

        return response()->json(['data' => [
            'mode' => 'pat',
            'url' => url('/webhooks/github'),
            'secret' => $credentials['webhook_secret'],
            'content_type' => 'application/json',
            'events' => ['push'],
        ]]);
    }

    /**
     * The instance's GitHub App, for Administration → Storage: where it comes
     * from and whether it works. Never the key or the secret.
     */
    public function appStatus(Request $request): JsonResponse
    {
        $this->authorizeInstanceAdmin($request);
        $source = $this->app->source();
        $registration = $source === GitHubAppAuth::SOURCE_DATABASE ? $this->app->registration() : null;
        $slug = $this->app->slug();

        return response()->json(['data' => [
            'source' => $source,
            'app_id' => $this->app->appId(),
            'slug' => $slug,
            'owner' => $registration?->owner,
            'html_url' => $registration?->html_url
                ?? ($slug === null ? null : rtrim((string) config('jotter.github.web_url', 'https://github.com'), '/').'/apps/'.rawurlencode($slug)),
            'registered_at' => $registration?->created_at?->toIso8601String(),
            'webhook_secret_set' => $this->app->webhookSecret() !== null,
            'problems' => $source === null ? [] : $this->app->configurationProblems(),
            'webhook_url' => url('/webhooks/github'),
        ]]);
    }

    /**
     * Forgets the App registered from the admin panel (the App itself stays on
     * GitHub; delete it there). Installations and stores that use it stop
     * working until another App is registered.
     */
    public function forgetApp(Request $request): JsonResponse
    {
        $this->authorizeInstanceAdmin($request);
        if ($this->app->source() !== GitHubAppAuth::SOURCE_DATABASE) {
            return response()->json(['message' => __('messages.github_app_not_registered_here')], 409);
        }

        $registration = $this->app->registration();
        GitHubAppRegistration::query()->delete();
        app(GitHubAppRegistry::class)->forget();
        $this->audit->record(AuditEvent::VAULT_STORE_GITHUB_INSTALLED, null, null, $this->actorId($request), [
            'action' => 'app_forgotten',
            'app_id' => $registration?->app_id,
            'app_slug' => $registration?->slug,
        ]);

        return response()->json(['data' => ['source' => $this->app->source()]]);
    }

    /**
     * G1 manifest flow: the instance administrator registers the App with the
     * right permissions in one step. The browser posts `manifest` to `post_url`.
     */
    public function manifest(Request $request): JsonResponse
    {
        $this->authorizeInstanceAdmin($request);
        if ($this->app->source() !== null) {
            return response()->json(['message' => __('messages.github_app_already_configured')], 409);
        }

        $organization = trim((string) $request->query('organization', ''));
        if ($organization !== '' && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/', $organization) !== 1) {
            return response()->json(['message' => __('messages.github_organization_invalid')], 422);
        }
        $web = rtrim((string) config('jotter.github.web_url', 'https://github.com'), '/');
        $nonce = Str::random(40);
        $request->session()->put('github_manifest_state', $nonce);

        $name = mb_substr((string) config('jotter.brand.name', 'Jotter').' Vault', 0, 34);

        return response()->json(['data' => [
            'post_url' => ($organization === '' ? $web.'/settings/apps/new' : $web.'/organizations/'.rawurlencode($organization).'/settings/apps/new').'?state='.$nonce,
            'manifest' => [
                'name' => $name,
                'url' => url('/'),
                'hook_attributes' => ['url' => url('/webhooks/github'), 'active' => true],
                'redirect_url' => url('/api/admin/github/manifest/callback'),
                'setup_url' => url('/api/github/setup'),
                'setup_on_update' => true,
                'public' => true,
                'default_permissions' => ['contents' => 'write', 'metadata' => 'read'],
                'default_events' => ['push'],
            ],
        ]]);
    }

    /**
     * GitHub sends the administrator's browser back here with a one-hour code.
     * The App's id, slug, private key and webhook secret are stored in the
     * database (key and secret encrypted with APP_KEY) and are never shown;
     * the browser returns to Administration → Storage.
     */
    public function manifestCallback(Request $request): RedirectResponse
    {
        $this->authorizeInstanceAdmin($request);
        $expected = (string) $request->session()->pull('github_manifest_state', '');
        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state', ''))) {
            return $this->backToStorage('state_invalid');
        }
        if ($this->app->source() !== null) {
            return $this->backToStorage('already_configured');
        }

        try {
            $app = $this->app->convertManifest((string) $request->query('code', ''));
        } catch (GitHubApiError $error) {
            // GitHub's answer, never the code or any key: the admin needs it to retry.
            \Illuminate\Support\Facades\Log::warning('GitHub App manifest conversion failed', ['status' => $error->status, 'error' => $error->getMessage()]);

            return $this->backToStorage('exchange_failed', $error->status > 0 ? (string) $error->status : 'network');
        }
        if ($app['id'] === '' || $app['slug'] === '' || openssl_pkey_get_private($app['pem']) === false) {
            \Illuminate\Support\Facades\Log::warning('GitHub App manifest conversion returned an incomplete App', [
                'app_id' => $app['id'], 'slug' => $app['slug'], 'pem_valid' => openssl_pkey_get_private($app['pem']) !== false,
            ]);

            return $this->backToStorage('exchange_failed', 'incomplete');
        }

        DB::transaction(function () use ($app, $request): void {
            GitHubAppRegistration::query()->delete();
            GitHubAppRegistration::query()->create([
                'app_id' => $app['id'],
                'slug' => $app['slug'],
                'owner' => $app['owner'],
                'html_url' => is_string($app['html_url']) ? $app['html_url'] : null,
                'private_key' => $app['pem'],
                'webhook_secret' => is_string($app['webhook_secret']) && $app['webhook_secret'] !== '' ? $app['webhook_secret'] : null,
                'created_by' => $this->actorId($request),
            ]);
        });
        app(GitHubAppRegistry::class)->forget();

        $this->audit->record(AuditEvent::VAULT_STORE_GITHUB_INSTALLED, null, null, $this->actorId($request), [
            'action' => 'app_registered',
            'app_id' => $app['id'],
            'app_slug' => $app['slug'],
            'owner' => $app['owner'],
            'manifest' => true,
        ]);

        return $this->backToStorage('registered');
    }

    private function backToStorage(string $result, ?string $detail = null): RedirectResponse
    {
        return redirect('/?storage=github-app&result='.$result.($detail === null ? '' : '&detail='.rawurlencode($detail)));
    }

    private function authorizeGitHub(Request $request, ?Tenant $tenant): void
    {
        $this->access->authorize($request, $tenant);
        if (! $this->drivers->isEnabled(VaultStoreDrivers::GITHUB)) {
            abort(404, __('messages.github_not_enabled'));
        }
        if (! self::tenantInBeta($tenant)) {
            abort(403, __('messages.github_not_in_beta'));
        }
    }

    /** Gradual launch (#445): JOTTER_GITHUB_BETA_TENANTS, empty = every tenant. */
    public static function tenantInBeta(?Tenant $tenant): bool
    {
        $beta = (array) config('jotter.github.beta_tenants', []);
        if ($beta === [] || $tenant === null) {
            return $tenant !== null;
        }

        return in_array((string) $tenant->id, $beta, true) || in_array((string) $tenant->slug, $beta, true);
    }

    private function authorizeInstanceAdmin(Request $request): void
    {
        if (! $this->drivers->isEnabled(VaultStoreDrivers::GITHUB)) {
            abort(404);
        }
        if (config('jotter.auth_bypass', false)) {
            return;
        }
        $subject = $this->access->subject($request);
        if ($subject === null) {
            abort(401);
        }
        if (! $subject->isAdmin) {
            abort(403);
        }
    }

    private function actorId(Request $request): ?string
    {
        return $this->access->subject($request)?->subjectId;
    }
}
