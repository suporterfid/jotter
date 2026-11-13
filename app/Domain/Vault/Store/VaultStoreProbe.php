<?php

namespace App\Domain\Vault\Store;

use App\Models\TenantVaultStore;
use App\Models\Workspace;

/**
 * "Test connection": proves the store is reachable and writable with a
 * write/read/delete round trip of a throwaway file at the store root, then
 * records status, last_checked_at and last_error. The error text never
 * contains credentials.
 */
final class VaultStoreProbe
{
    public function __construct(
        private readonly VaultStoreResolver $resolver,
        private readonly EndpointGuard $endpoints = new EndpointGuard,
    ) {}

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function probe(TenantVaultStore $record): array
    {
        $error = null;

        try {
            if ($record->decryptedCredentials() === null) {
                throw new StoreUnavailable('The stored credentials cannot be decrypted (APP_KEY changed). Enter them again.');
            }
            if ($record->driver === VaultStoreDrivers::S3) {
                $this->endpoints->check((string) ($record->settings['endpoint'] ?? ''));
            }

            $context = new Workspace(['tenant_id' => $record->tenant_id, 'slug' => 'probe', 'name' => 'probe']);
            $store = $this->resolver->forStore($record, $context);
            $path = '.jotter-probe-'.bin2hex(random_bytes(6)).'.txt';
            $payload = 'jotter probe '.now()->toIso8601String();

            $store->write($path, $payload);
            try {
                if ($store->read($path) !== $payload) {
                    throw new StoreUnavailable('The store returned different bytes than were written.');
                }
            } finally {
                $store->delete($path);
            }
        } catch (\Throwable $exception) {
            $error = mb_substr($exception->getMessage(), 0, 1000);
        }

        $record->forceFill([
            'status' => $error === null ? TenantVaultStore::STATUS_OK : TenantVaultStore::STATUS_FAILED,
            'last_checked_at' => now(),
            'last_error' => $error,
        ])->save();

        return ['ok' => $error === null, 'error' => $error];
    }
}
