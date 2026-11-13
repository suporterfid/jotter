<?php

namespace App\Domain\Vault\Store\GitHub;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Vault\Store\StoreUnavailable;
use App\Models\TenantVaultStore;
use App\Models\Workspace;

/**
 * Builds the client and the VaultStore of a `github` store record:
 * `settings` = {owner, repo, branch, installation_id?}, `credentials` =
 * {token?, webhook_secret?}. App mode when installation_id is set (no secret
 * per tenant), fine-grained PAT otherwise (D7).
 */
class GitHubStoreFactory
{
    public function __construct(
        private readonly GitHubAppAuth $app = new GitHubAppAuth,
        private readonly GitHubWriteBudget $budget = new GitHubWriteBudget,
        private readonly GitHubBlobCache $blobs = new GitHubBlobCache,
    ) {}

    public function client(TenantVaultStore $record): GitHubClient
    {
        $settings = $record->settings ?? [];
        $owner = (string) ($settings['owner'] ?? '');
        $repo = (string) ($settings['repo'] ?? '');
        if ($owner === '' || $repo === '') {
            throw new StoreUnavailable('The GitHub store has no repository configured.');
        }

        $installationId = isset($settings['installation_id']) && $settings['installation_id'] !== null && $settings['installation_id'] !== ''
            ? (int) $settings['installation_id']
            : null;

        $token = null;
        if ($installationId === null) {
            $credentials = $record->decryptedCredentials();
            if ($credentials === null) {
                throw new StoreUnavailable('The stored GitHub token cannot be decrypted (APP_KEY changed). Paste it again.');
            }
            $token = isset($credentials['token']) ? (string) $credentials['token'] : null;
        }

        return new GitHubClient($owner, $repo, $installationId, $token, $this->app, $this->budget);
    }

    public function branch(TenantVaultStore $record): string
    {
        $branch = (string) (($record->settings ?? [])['branch'] ?? 'main');

        return $branch === '' ? 'main' : $branch;
    }

    public function store(TenantVaultStore $record, Workspace $workspace): GitHubVaultStore
    {
        return new GitHubVaultStore(
            $record,
            $workspace,
            $this->client($record),
            $this->blobs,
            static function (TenantVaultStore $record): void {
                $result = app(GitHubSync::class)->pull($record);
                if (in_array($result['status'], ['failed', 'rate_limited'], true) && ! \App\Models\VaultSyncState::query()->where('vault_store_id', $record->id)->value('initialized')) {
                    throw new StoreUnavailable('GitHub is unreachable and this repository was never read: '.(string) \App\Models\VaultSyncState::query()->where('vault_store_id', $record->id)->value('last_error'));
                }
            },
        );
    }

    /**
     * The repository is no longer usable (credentials revoked, App uninstalled,
     * repository renamed or gone, branch deleted): the store turns `failed`, the
     * workspace read-only from the cache, the journal is kept, admins are told.
     */
    public function markFailed(TenantVaultStore $record, string $reason): void
    {
        $record->refresh();
        $wasFailed = $record->status === TenantVaultStore::STATUS_FAILED;
        $record->forceFill([
            'status' => TenantVaultStore::STATUS_FAILED,
            'last_error' => mb_substr($reason, 0, 1000),
            'last_checked_at' => now(),
        ])->save();

        $workspace = Workspace::query()->where('vault_store_id', $record->id)->first();
        if ($workspace !== null && $workspace->storage_state !== 'moving') {
            $workspace->forceFill(['storage_state' => 'degraded'])->save();
        }

        if ($wasFailed) {
            return;
        }

        (new AuditRecorder)->record(AuditEvent::VAULT_STORE_FAILED, (int) $record->tenant_id, $workspace?->id, null, [
            'vault_store_id' => $record->id,
            'error' => mb_substr($reason, 0, 500),
        ]);
        (new GitHubSyncNotifier)->aboutStore($record, $workspace?->id, 'store_failed', __('messages.github_store_failed', ['name' => $record->name]), [
            'error' => mb_substr($reason, 0, 500),
        ]);
    }

    /** Back to `ok` after a successful probe or pull. */
    public function markHealthy(TenantVaultStore $record): void
    {
        $record->forceFill(['status' => TenantVaultStore::STATUS_OK, 'last_error' => null, 'last_checked_at' => now()])->save();
        Workspace::query()->where('vault_store_id', $record->id)->where('storage_state', 'degraded')->update(['storage_state' => 'active']);
    }
}
