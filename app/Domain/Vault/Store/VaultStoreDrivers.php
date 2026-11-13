<?php

namespace App\Domain\Vault\Store;

/**
 * Which drivers exist, which the operator turned on (`JOTTER_VAULT_DRIVERS`,
 * default `local`) and which this version implements. The tenant storage
 * screen and API exist only when a remote driver is on (D10).
 */
final class VaultStoreDrivers
{
    public const LOCAL = 'local';

    public const S3 = 's3';

    public const GITHUB = 'github';

    public const KNOWN = [self::LOCAL, self::S3, self::GITHUB];

    /** Drivers with a VaultStore implementation in this version (#432 adds S3). */
    public const IMPLEMENTED = [self::LOCAL, self::GITHUB];

    /**
     * @return list<string>
     */
    public function enabled(): array
    {
        $configured = (array) config('jotter.vault_stores.drivers', [self::LOCAL]);

        return array_values(array_unique([self::LOCAL, ...array_values(array_intersect($configured, self::KNOWN))]));
    }

    public function isEnabled(string $driver): bool
    {
        return in_array($driver, $this->enabled(), true);
    }

    /** True when the operator turned on at least one remote driver: the storage screen exists. */
    public function remoteEnabled(): bool
    {
        return array_diff($this->enabled(), [self::LOCAL]) !== [];
    }

    public function isImplemented(string $driver): bool
    {
        return in_array($driver, self::IMPLEMENTED, true);
    }

    /**
     * Validation rules for `settings.*` and `credentials.*` of a driver.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(string $driver, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return match ($driver) {
            self::LOCAL => [
                'settings.root' => [$required, 'string', 'max:700'],
            ],
            self::S3 => [
                'settings.endpoint' => [$required, 'string', 'max:500'],
                'settings.region' => ['sometimes', 'nullable', 'string', 'max:64'],
                'settings.bucket' => [$required, 'string', 'max:63', 'regex:/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/'],
                'settings.prefix' => ['sometimes', 'nullable', 'string', 'max:500'],
                'settings.path_style' => ['sometimes', 'boolean'],
                'credentials.access_key_id' => [$required, 'string', 'max:256'],
                'credentials.secret_access_key' => [$required, 'string', 'max:256'],
            ],
            self::GITHUB => [
                'settings.owner' => [$required, 'string', 'max:100', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9\-]{0,38})$/'],
                'settings.repo' => [$required, 'string', 'max:100', 'regex:/^[A-Za-z0-9._\-]+$/'],
                'settings.branch' => ['sometimes', 'string', 'max:255'],
                'settings.installation_id' => ['sometimes', 'nullable', 'integer'],
                'credentials.token' => ['sometimes', 'nullable', 'string', 'max:512'],
            ],
            default => [],
        };
    }
}
