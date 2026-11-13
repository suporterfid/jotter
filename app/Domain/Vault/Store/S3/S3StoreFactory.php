<?php

namespace App\Domain\Vault\Store\S3;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Vault\Store\EndpointGuard;
use App\Domain\Vault\Store\StoreUnavailable;
use App\Domain\Vault\Store\UnsafeEndpoint;
use App\Models\TenantVaultStore;
use App\Models\Workspace;
use AsyncAws\Core\Credentials\Credentials;
use AsyncAws\S3\S3Client;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the client and the VaultStore of an `s3` store record:
 * `settings` = {endpoint, region?, bucket, prefix?, path_style?,
 * conditional_writes?}, `credentials` = {access_key_id, secret_access_key}.
 *
 * Every client passes the SSRF guard (EndpointGuard) and is pinned to the
 * addresses it validated, so DNS cannot change between the check and the
 * connection. Timeouts: 3 s to connect, 10 s per call (#446).
 *
 * Bound as a scoped singleton: clients and store views are reused for the
 * rest of the request, which is what makes the read memo per request.
 */
class S3StoreFactory
{
    public const CONNECT_TIMEOUT = 3;

    public const TOTAL_TIMEOUT = 10;

    private const HTTP_BINDING = 'jotter.s3.http_client';

    /** @var array<string, S3Client> */
    private array $clients = [];

    /** @var array<string, S3VaultStore> */
    private array $stores = [];

    private ?HttpClientInterface $http = null;

    public function __construct(
        private readonly ?EndpointGuard $endpoints = null,
        private readonly AuditRecorder $audit = new AuditRecorder,
    ) {}

    /** Tests (FakeS3) route every call through a mock HTTP client, for the rest of the app's life. */
    public function useHttpClient(?HttpClientInterface $http): void
    {
        $this->http = $http;
        app()->instance(self::HTTP_BINDING, $http);
        $this->clients = [];
        $this->stores = [];
    }

    /** Drops the store views and their read memo (tests simulating a new request). */
    public function forgetStores(): void
    {
        $this->stores = [];
    }

    public function client(TenantVaultStore $record): S3Client
    {
        $settings = $record->settings ?? [];
        $endpoint = rtrim(trim((string) ($settings['endpoint'] ?? '')), '/');
        $bucket = (string) ($settings['bucket'] ?? '');
        if ($endpoint === '' || $bucket === '') {
            throw new StoreUnavailable('The S3 store has no endpoint or bucket configured.');
        }

        $credentials = $record->decryptedCredentials();
        if ($credentials === null) {
            throw new StoreUnavailable('The stored S3 credentials cannot be decrypted (APP_KEY changed). Enter them again.');
        }
        $keyId = (string) ($credentials['access_key_id'] ?? '');
        $secret = (string) ($credentials['secret_access_key'] ?? '');
        if ($keyId === '' || $secret === '') {
            throw new StoreUnavailable('The S3 store has no access key configured.');
        }

        $cacheKey = $record->id.'|'.$record->updated_at?->getTimestamp().'|'.hash('sha256', $endpoint.$keyId);
        if (isset($this->clients[$cacheKey])) {
            return $this->clients[$cacheKey];
        }

        try {
            $checked = ($this->endpoints ?? app(EndpointGuard::class))->check($endpoint);
        } catch (UnsafeEndpoint $exception) {
            throw new StoreUnavailable($exception->getMessage(), 0, $exception);
        }

        $this->http ??= app()->bound(self::HTTP_BINDING) ? app(self::HTTP_BINDING) : null;
        $http = $this->http ?? HttpClient::create([
            'timeout' => self::TOTAL_TIMEOUT,
            'max_duration' => self::TOTAL_TIMEOUT,
            'max_redirects' => 0,
            // Pin the connection to the addresses EndpointGuard validated (DNS rebinding).
            'resolve' => filter_var($checked['host'], FILTER_VALIDATE_IP) === false && $checked['ips'] !== []
                ? [$checked['host'] => $checked['ips'][0]]
                : [],
            'max_connect_duration' => self::CONNECT_TIMEOUT,
        ]);

        return $this->clients[$cacheKey] = new S3Client([
            'endpoint' => $endpoint,
            'region' => (string) (($settings['region'] ?? '') ?: 'us-east-1'),
            'pathStyleEndpoint' => (bool) ($settings['path_style'] ?? false),
            // Never read ~/.aws, instance metadata or environment credentials.
            'sharedConfigFile' => '/dev/null',
            'sharedCredentialsFile' => '/dev/null',
        ], new Credentials($keyId, $secret), $http);
    }

    /**
     * The view of $workspace under $prefix (the workspace's `vault_prefix`, which
     * already includes `settings.prefix`; VaultMover::prefixFor builds it).
     */
    public function store(TenantVaultStore $record, Workspace $workspace, string $prefix): S3VaultStore
    {
        $client = $this->client($record);
        $prefix = trim($prefix, '/');
        $cacheKey = spl_object_id($client).'|'.($workspace->id ?? 'new:'.spl_object_id($workspace)).'|'.$prefix;

        return $this->stores[$cacheKey] ??= new S3VaultStore(
            $client,
            (string) $record->settings['bucket'],
            $prefix,
            $workspace,
            $record,
            $this->conditionalWrites($record),
            $this,
        );
    }

    /**
     * Native If-Match / If-None-Match: on by default (AWS, R2, MinIO), off for
     * GCS until its contract run passes (#448), or when the operator says so.
     */
    public function conditionalWrites(TenantVaultStore $record): bool
    {
        $settings = $record->settings ?? [];
        if (array_key_exists('conditional_writes', $settings) && $settings['conditional_writes'] !== null) {
            return (bool) $settings['conditional_writes'];
        }
        $host = strtolower((string) parse_url((string) ($settings['endpoint'] ?? ''), PHP_URL_HOST));

        return ! str_ends_with($host, 'storage.googleapis.com');
    }

    /**
     * The provider refused the credentials or the bucket is gone: the store
     * turns `failed`, its workspace `degraded` (writes answer 423), admins see
     * it in the doctor and on the storage screen until a test passes again.
     */
    public function markFailed(?TenantVaultStore $record, string $reason): void
    {
        if ($record === null || $record->id === null) {
            return;
        }

        $wasFailed = TenantVaultStore::query()->whereKey($record->id)->value('status') === TenantVaultStore::STATUS_FAILED;
        TenantVaultStore::query()->whereKey($record->id)->update([
            'status' => TenantVaultStore::STATUS_FAILED,
            'last_error' => mb_substr($reason, 0, 1000),
            'last_checked_at' => now(),
        ]);
        Workspace::query()->where('vault_store_id', $record->id)->where('storage_state', 'active')->update(['storage_state' => 'degraded']);

        if (! $wasFailed) {
            $this->audit->record(AuditEvent::VAULT_STORE_FAILED, (int) $record->tenant_id, null, null, [
                'vault_store_id' => $record->id,
                'error' => mb_substr($reason, 0, 500),
            ]);
        }
    }
}
