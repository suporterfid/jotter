<?php

namespace App\Domain\Vault\Store\S3;

use App\Models\TenantVaultStore;
use AsyncAws\Core\Exception\Http\ClientException;

/**
 * Bucket-level facts that decide backups and drive warnings (plan §6.2, §6.4):
 *
 * - versioning (`GetBucketVersioning`): `Enabled`, `Suspended` or `Off`. Only
 *   `Enabled` lets backups leave the workspace out of the ZIP;
 * - the shortest noncurrent-version expiry among enabled lifecycle rules
 *   (`GetBucketLifecycleConfiguration`): the doctor recommends ≤ 30 days.
 *
 * The result is kept in `settings.bucket` with the time it was read, so the
 * storage screen and the doctor can show it without calling the provider.
 * When the provider refuses or fails, versioning is `null` (unknown) and the
 * caller treats it as off: backups include the workspace.
 */
class S3BucketInspector
{
    public const ENABLED = 'Enabled';

    public const SUSPENDED = 'Suspended';

    public const OFF = 'Off';

    public function __construct(private readonly S3StoreFactory $factory) {}

    /**
     * @return array{versioning: ?string, noncurrent_expiry_days: ?int, lifecycle_rules: ?int, error: ?string, checked_at: string}
     */
    public function inspect(TenantVaultStore $record, bool $save = true): array
    {
        $result = ['versioning' => null, 'noncurrent_expiry_days' => null, 'lifecycle_rules' => null, 'error' => null, 'checked_at' => now()->toIso8601String()];

        try {
            $client = $this->factory->client($record);
            $bucket = (string) ($record->settings['bucket'] ?? '');

            $versioning = $client->getBucketVersioning(['Bucket' => $bucket]);
            $versioning->resolve();
            $status = $versioning->getStatus();
            $result['versioning'] = match ($status) {
                'Enabled' => self::ENABLED,
                'Suspended' => self::SUSPENDED,
                default => self::OFF,
            };

            try {
                $lifecycle = $client->getBucketLifecycleConfiguration(['Bucket' => $bucket]);
                $lifecycle->resolve();
                $days = null;
                $rules = 0;
                foreach ($lifecycle->getRules() as $rule) {
                    $rules++;
                    if ($rule->getStatus() !== 'Enabled') {
                        continue;
                    }
                    $noncurrent = $rule->getNoncurrentVersionExpiration()?->getNoncurrentDays();
                    if ($noncurrent !== null) {
                        $days = $days === null ? (int) $noncurrent : min($days, (int) $noncurrent);
                    }
                }
                $result['lifecycle_rules'] = $rules;
                $result['noncurrent_expiry_days'] = $days;
            } catch (ClientException $exception) {
                // 404 NoSuchLifecycleConfiguration: no rules. 403/501: unknown, not fatal.
                $result['lifecycle_rules'] = $exception->getResponse()->getStatusCode() === 404 ? 0 : null;
            }
        } catch (\Throwable $exception) {
            $result['error'] = mb_substr($exception instanceof ClientException
                ? 'HTTP '.$exception->getResponse()->getStatusCode().' '.($exception->getAwsCode() ?? '')
                : $exception->getMessage(), 0, 300);
        }

        if ($save && $record->id !== null) {
            $settings = $record->settings ?? [];
            $settings['bucket_state'] = $result;
            // Not an edit by an admin: keep updated_at (it keys the client cache).
            $record->timestamps = false;
            $record->settings = $settings;
            $record->save();
            $record->timestamps = true;
        }

        return $result;
    }

    /** Whether the store's bucket keeps old versions (backups may skip it). Unknown counts as no. */
    public function versioned(TenantVaultStore $record): bool
    {
        return $this->inspect($record)['versioning'] === self::ENABLED;
    }

    /**
     * Human warnings for the move preflight, the storage test and the doctor.
     *
     * @param  array{versioning: ?string, noncurrent_expiry_days: ?int, lifecycle_rules: ?int, error: ?string}  $state
     * @return list<string>
     */
    public static function warnings(array $state, string $name): array
    {
        $maxDays = (int) config('jotter.s3.noncurrent_expiry_max_days', 30);
        $warnings = [];

        if ($state['versioning'] === null) {
            $warnings[] = "{$name}: bucket versioning could not be read".($state['error'] ? " ({$state['error']})" : '').'; backups include this workspace';
        } elseif ($state['versioning'] !== self::ENABLED) {
            $warnings[] = "{$name}: bucket versioning is off; a deletion by mistake cannot be undone at the provider, so backups include this workspace (turn versioning on, or use --without-remote-vaults at your own risk)";
        } elseif ($state['noncurrent_expiry_days'] === null) {
            $warnings[] = "{$name}: bucket versioning is on but old versions never expire; add a lifecycle rule that expires noncurrent versions within {$maxDays} days (deleted notes must not outlive the backup window)";
        } elseif ($state['noncurrent_expiry_days'] > $maxDays) {
            $warnings[] = "{$name}: old versions expire after {$state['noncurrent_expiry_days']} days; {$maxDays} days or less is recommended";
        }

        return $warnings;
    }
}
