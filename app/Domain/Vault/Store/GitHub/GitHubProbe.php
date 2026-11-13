<?php

namespace App\Domain\Vault\Store\GitHub;

use App\Models\TenantVaultStore;
use App\Models\VaultSyncState;

/**
 * Read-only connection check for a GitHub store (plan §4.2 step 5): never a
 * test commit, so the first commit in the repository is the one of the move.
 *
 * - `GET /repos/{r}`: exists, is private, the token may push;
 * - `GET /git/ref/heads/{branch}`: the branch exists, or the repository is
 *   still empty (the first flush creates it);
 * - a fine-grained PAT's expiry (`github-authentication-token-expiration`) is
 *   kept so the doctor can warn 14 days before.
 */
class GitHubProbe
{
    public function __construct(private readonly GitHubStoreFactory $factory) {}

    public function check(TenantVaultStore $record): void
    {
        $client = $this->factory->client($record);
        $repository = $client->repository();

        if (! ($repository['private'] ?? false)) {
            throw new GitHubApiError('The repository is public. Jotter only stores a workspace in a private repository.');
        }
        // Installation tokens may omit `permissions`; the token itself was asked with contents:write.
        if (isset($repository['permissions']) && ! ($repository['permissions']['push'] ?? false)) {
            throw new GitHubApiError('The token cannot push to this repository (Contents: read and write is required).');
        }

        $ref = $client->ref($this->factory->branch($record));
        $settings = $record->settings ?? [];
        $settings['repository_empty'] = $ref->status === GitHubRef::EMPTY;
        if ($client->tokenExpiration !== null) {
            $expires = strtotime($client->tokenExpiration);
            $settings['token_expires_at'] = $expires === false ? null : gmdate('c', $expires);
        }
        $record->settings = $settings;
        $record->save();

        $state = VaultSyncState::for($record);
        $state->forceFill(['repo_size_kb' => isset($repository['size']) ? (int) $repository['size'] : null])->save();
    }
}
