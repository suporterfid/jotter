<?php

namespace App\Http\Controllers;

use App\Domain\Vault\Store\GitHub\GitHubAppAuth;
use App\Domain\Vault\Store\GitHub\GitHubStoreFactory;
use App\Domain\Vault\Store\GitHub\GitHubSync;
use App\Domain\Vault\Store\VaultStoreDrivers;
use App\Models\GitHubInstallation;
use App\Models\TenantVaultStore;
use App\Models\VaultSyncState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `POST /webhooks/github` (plan §5.5, decision G5): an external push shows up
 * in seconds. No session, no CSRF, body ≤ 1 MB.
 *
 * 1. `X-Hub-Signature-256` (HMAC-SHA256, constant-time) with the App secret,
 *    or with the secret Jotter generated for a PAT store's repository webhook;
 * 2. a repeated `X-GitHub-Delivery` is acknowledged and ignored;
 * 3. `push`: the stores of that repository and branch (and installation) are
 *    marked `pull_pending`, the answer is 202 at once, and the pull runs
 *    after the response (20 s / 200 files); what is left, vault:pull
 *    --pending finishes within a minute;
 * 4. `installation` (deleted, suspended) and `installation_repositories`
 *    (removed) turn the affected stores `failed` at once.
 *
 * The payload only says where to look; content always comes from the API
 * with Jotter's own token.
 */
final class GitHubWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 1024 * 1024;

    public function __construct(private readonly VaultStoreDrivers $drivers = new VaultStoreDrivers) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->drivers->isEnabled(VaultStoreDrivers::GITHUB)) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return response()->json(['message' => 'Payload too large'], 413);
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            $payload = [];
        }
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $event = (string) $request->header('X-GitHub-Event', '');
        $delivery = mb_substr((string) $request->header('X-GitHub-Delivery', ''), 0, 64);

        // Which secret signed it decides which stores this delivery may touch.
        $appSigned = $this->signedWith($body, $signature, (string) config('jotter.github.webhook_secret', ''));
        $patStores = $appSigned ? collect() : $this->patStoresSignedFor($payload, $body, $signature);
        if (! $appSigned && $patStores->isEmpty()) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        if ($delivery !== '') {
            $inserted = DB::table('github_webhook_deliveries')->insertOrIgnore([
                'delivery_id' => $delivery,
                'event' => mb_substr($event, 0, 64),
                'received_at' => now(),
            ]);
            if ($inserted === 0) {
                return response()->json(['message' => 'Duplicate delivery ignored'], 200);
            }
        }

        return match ($event) {
            'ping' => response()->json(['message' => 'pong']),
            'push' => $this->push($payload, $appSigned, $patStores),
            'installation' => $appSigned ? $this->installation($payload) : response()->json(['message' => 'Ignored'], 202),
            'installation_repositories' => $appSigned ? $this->installationRepositories($payload) : response()->json(['message' => 'Ignored'], 202),
            default => response()->json(['message' => 'Ignored'], 202),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  \Illuminate\Support\Collection<int, TenantVaultStore>  $patStores
     */
    private function push(array $payload, bool $appSigned, $patStores): JsonResponse
    {
        $ref = (string) ($payload['ref'] ?? '');
        if (! str_starts_with($ref, 'refs/heads/')) {
            return response()->json(['message' => 'Not a branch'], 202);
        }
        $branch = substr($ref, strlen('refs/heads/'));
        $fullName = strtolower((string) ($payload['repository']['full_name'] ?? ''));
        $installationId = isset($payload['installation']['id']) ? (int) $payload['installation']['id'] : null;

        $candidates = $appSigned
            ? ($installationId === null ? collect() : $this->storesFor($fullName)->filter(fn (TenantVaultStore $store): bool => (int) ($store->settings['installation_id'] ?? 0) === $installationId))
            : $patStores;
        $stores = $candidates->filter(fn (TenantVaultStore $store): bool => app(GitHubStoreFactory::class)->branch($store) === $branch)->values();

        if ($stores->isEmpty()) {
            return response()->json(['message' => 'No store follows this branch'], 202);
        }

        foreach ($stores as $store) {
            VaultSyncState::for($store)->forceFill(['pull_pending' => true, 'last_webhook_at' => now()])->save();
        }

        $login = isset($payload['sender']['login']) ? mb_substr((string) $payload['sender']['login'], 0, 100) : null;
        $seconds = max(1, (int) config('jotter.github.webhook_pull_seconds', 20));
        $files = max(1, (int) config('jotter.github.webhook_pull_files', 200));
        $ids = $stores->pluck('id')->all();

        // After the 202 is sent (PHP-FPM: fastcgi_finish_request in Response::send()).
        // Terminating callbacks outlive the request in long-lived workers: run once.
        $ran = false;
        app()->terminating(function () use (&$ran, $ids, $login, $seconds, $files): void {
            if ($ran) {
                return;
            }
            $ran = true;
            $deadline = microtime(true) + $seconds;
            foreach (TenantVaultStore::query()->whereIn('id', $ids)->get() as $store) {
                if (microtime(true) > $deadline) {
                    break;
                }
                try {
                    app(GitHubSync::class)->pull($store, $files, $deadline, $login);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        });

        return response()->json(['message' => 'Accepted', 'stores' => count($ids)], 202);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function installation(array $payload): JsonResponse
    {
        $action = (string) ($payload['action'] ?? '');
        $installationId = (int) ($payload['installation']['id'] ?? 0);

        if (in_array($action, ['deleted', 'suspend'], true) && $installationId > 0) {
            GitHubInstallation::query()->where('installation_id', $installationId)->update(['suspended_at' => now()]);
            app(GitHubAppAuth::class)->forgetInstallationTokens($installationId);
            $this->failStores(
                TenantVaultStore::query()->where('driver', 'github')->get()
                    ->filter(fn (TenantVaultStore $store): bool => (int) ($store->settings['installation_id'] ?? 0) === $installationId),
                $action === 'deleted' ? 'The GitHub App was uninstalled.' : 'The GitHub App installation was suspended.',
            );
        }
        if ($action === 'unsuspend' && $installationId > 0) {
            GitHubInstallation::query()->where('installation_id', $installationId)->update(['suspended_at' => null]);
        }

        return response()->json(['message' => 'Accepted'], 202);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function installationRepositories(array $payload): JsonResponse
    {
        $installationId = (int) ($payload['installation']['id'] ?? 0);
        $removed = array_map(
            static fn (array $repository): string => strtolower((string) ($repository['full_name'] ?? '')),
            array_filter((array) ($payload['repositories_removed'] ?? []), 'is_array'),
        );

        if ($installationId > 0 && $removed !== []) {
            app(GitHubAppAuth::class)->forgetInstallationTokens($installationId);
            $this->failStores(
                TenantVaultStore::query()->where('driver', 'github')->get()->filter(
                    fn (TenantVaultStore $store): bool => (int) ($store->settings['installation_id'] ?? 0) === $installationId
                        && in_array($this->fullName($store), $removed, true),
                ),
                'The repository was removed from the GitHub App installation.',
            );
        }

        return response()->json(['message' => 'Accepted'], 202);
    }

    /**
     * @param  iterable<TenantVaultStore>  $stores
     */
    private function failStores(iterable $stores, string $reason): void
    {
        foreach ($stores as $store) {
            app(GitHubStoreFactory::class)->markFailed($store, $reason);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return \Illuminate\Support\Collection<int, TenantVaultStore>
     */
    private function patStoresSignedFor(array $payload, string $body, string $signature)
    {
        $fullName = strtolower((string) ($payload['repository']['full_name'] ?? ''));
        if ($fullName === '' || $signature === '') {
            return collect();
        }

        return $this->storesFor($fullName)
            ->filter(fn (TenantVaultStore $store): bool => empty($store->settings['installation_id']))
            ->filter(function (TenantVaultStore $store) use ($body, $signature): bool {
                $secret = (string) (($store->decryptedCredentials() ?? [])['webhook_secret'] ?? '');

                return $this->signedWith($body, $signature, $secret);
            })->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, TenantVaultStore>
     */
    private function storesFor(string $fullName)
    {
        return TenantVaultStore::query()->where('driver', 'github')->get()
            ->filter(fn (TenantVaultStore $store): bool => $this->fullName($store) === $fullName)->values();
    }

    private function fullName(TenantVaultStore $store): string
    {
        return strtolower(($store->settings['owner'] ?? '').'/'.($store->settings['repo'] ?? ''));
    }

    private function signedWith(string $body, string $signature, string $secret): bool
    {
        if ($secret === '' || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature);
    }
}
