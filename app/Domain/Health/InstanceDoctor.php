<?php

namespace App\Domain\Health;

use App\Support\ReleaseVersion;
use App\Support\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;

/**
 * Self-diagnosis for one shared-hosting installation. Every check is pure PHP:
 * no shelling out, no network calls beyond the configured database connection.
 */
final class InstanceDoctor
{
    public const MINIMUM_PHP_VERSION = '8.2.0';

    public const SCHEDULER_MAX_AGE_MINUTES = 5;

    public const DISK_CRITICAL_BYTES = 100 * 1024 * 1024;

    public const DISK_WARNING_BYTES = 1024 * 1024 * 1024;

    /**
     * Extensions the runtime actually requires (composer.lock `ext-*` requirements
     * plus the drivers this application uses directly).
     *
     * @var list<string>
     */
    public const REQUIRED_EXTENSIONS = [
        'pdo_mysql',
        'mbstring',
        'openssl',
        'tokenizer',
        'ctype',
        'json',
        'fileinfo',
        'filter',
        'dom',
        'libxml',
        'iconv',
        'session',
        'curl',
        'zip',
    ];

    /**
     * Extensions that only degrade optional features when missing.
     *
     * @var list<string>
     */
    public const RECOMMENDED_EXTENSIONS = ['intl', 'bcmath', 'gd'];

    public function __construct(
        private readonly Migrator $migrator,
    ) {}

    public function run(): DoctorReport
    {
        $isProduction = $this->isProduction();
        $databaseCheck = $this->checkDatabase();

        $checks = [
            $this->checkPhpVersion(),
            $this->checkRequiredExtensions(),
            $this->checkRecommendedExtensions(),
            $this->checkInstanceSlug(),
            $this->checkAppEnvironment(),
            $this->checkAppKey(),
            $this->checkAppDebug($isProduction),
            $this->checkAppUrl($isProduction),
            $this->checkWritable('storage_writable', 'storage/ writable', $this->storageDirectories()),
            $this->checkWritable('bootstrap_cache_writable', 'bootstrap/cache writable', [base_path('bootstrap/cache')]),
            $this->checkVaultPath(),
            $this->checkVaultOutsideDocumentRoot(),
            $this->checkVaultDiskSpace(),
            $databaseCheck,
            $this->checkPendingMigrations($databaseCheck->passed),
            $this->checkMailer(),
            $this->checkScheduler(),
        ];

        return new DoctorReport(
            instance: $this->instanceSlug(),
            version: ReleaseVersion::current(),
            environment: (string) config('app.env'),
            checks: $checks,
        );
    }

    private function isProduction(): bool
    {
        return config('app.env') === 'production';
    }

    private function instanceSlug(): ?string
    {
        $slug = config('jotter.instance_slug');

        return is_string($slug) && trim($slug) !== '' ? trim($slug) : null;
    }

    private function checkPhpVersion(): DoctorCheck
    {
        $ok = version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '>=');

        return $ok
            ? DoctorCheck::pass('php_version', 'PHP version', true, 'PHP '.PHP_VERSION, ['version' => PHP_VERSION])
            : DoctorCheck::fail('php_version', 'PHP version', true, sprintf('PHP %s is below the required %s.', PHP_VERSION, self::MINIMUM_PHP_VERSION), ['version' => PHP_VERSION]);
    }

    private function checkRequiredExtensions(): DoctorCheck
    {
        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn (string $extension): bool => ! extension_loaded($extension)));

        return $missing === []
            ? DoctorCheck::pass('php_extensions', 'Required PHP extensions', true, 'All required extensions are loaded.', ['required' => self::REQUIRED_EXTENSIONS])
            : DoctorCheck::fail('php_extensions', 'Required PHP extensions', true, 'Missing: '.implode(', ', $missing), ['missing' => $missing]);
    }

    private function checkRecommendedExtensions(): DoctorCheck
    {
        $missing = array_values(array_filter(self::RECOMMENDED_EXTENSIONS, static fn (string $extension): bool => ! extension_loaded($extension)));

        return $missing === []
            ? DoctorCheck::pass('php_recommended_extensions', 'Recommended PHP extensions', false, 'All recommended extensions are loaded.', ['recommended' => self::RECOMMENDED_EXTENSIONS])
            : DoctorCheck::fail('php_recommended_extensions', 'Recommended PHP extensions', false, 'Missing (optional features degrade): '.implode(', ', $missing), ['missing' => $missing]);
    }

    private function checkInstanceSlug(): DoctorCheck
    {
        $slug = $this->instanceSlug();

        return $slug !== null
            ? DoctorCheck::pass('instance_slug', 'APP_INSTANCE_SLUG', false, $slug, ['slug' => $slug])
            : DoctorCheck::fail('instance_slug', 'APP_INSTANCE_SLUG', false, 'Not set. Give each installation a unique slug so logs and diagnostics can be told apart.');
    }

    private function checkAppEnvironment(): DoctorCheck
    {
        $environment = (string) config('app.env');

        return $environment === 'production'
            ? DoctorCheck::pass('app_env', 'APP_ENV', false, 'production', ['environment' => $environment])
            : DoctorCheck::fail('app_env', 'APP_ENV', false, sprintf("APP_ENV is '%s'; production installations must set APP_ENV=production.", $environment), ['environment' => $environment]);
    }

    private function checkAppKey(): DoctorCheck
    {
        $key = (string) config('app.key');

        return trim($key) !== ''
            ? DoctorCheck::pass('app_key', 'APP_KEY', true, 'Set.')
            : DoctorCheck::fail('app_key', 'APP_KEY', true, 'APP_KEY is empty. Run `php artisan key:generate --force` once per installation.');
    }

    private function checkAppDebug(bool $isProduction): DoctorCheck
    {
        $debug = (bool) config('app.debug');

        if (! $debug) {
            return DoctorCheck::pass('app_debug', 'APP_DEBUG', $isProduction, 'false');
        }

        return DoctorCheck::fail('app_debug', 'APP_DEBUG', $isProduction, 'APP_DEBUG=true leaks stack traces and configuration; set APP_DEBUG=false in production.');
    }

    private function checkAppUrl(bool $isProduction): DoctorCheck
    {
        $url = (string) config('app.url');

        return str_starts_with(strtolower($url), 'https://')
            ? DoctorCheck::pass('app_url_https', 'APP_URL uses HTTPS', $isProduction, $url, ['url' => $url])
            : DoctorCheck::fail('app_url_https', 'APP_URL uses HTTPS', $isProduction, sprintf("APP_URL is '%s'; production installations must use an https:// URL.", $url), ['url' => $url]);
    }

    /**
     * @return list<string>
     */
    private function storageDirectories(): array
    {
        return [
            storage_path(),
            storage_path('app'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
        ];
    }

    /**
     * @param  list<string>  $directories
     */
    private function checkWritable(string $id, string $label, array $directories): DoctorCheck
    {
        $problems = [];
        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                $problems[] = $directory.' (missing)';
            } elseif (! is_writable($directory)) {
                $problems[] = $directory.' (not writable)';
            }
        }

        return $problems === []
            ? DoctorCheck::pass($id, $label, true, 'Writable.', ['directories' => $directories])
            : DoctorCheck::fail($id, $label, true, implode('; ', $problems), ['problems' => $problems]);
    }

    private function vaultBasePath(): string
    {
        return (string) config('vault.base_path');
    }

    private function checkVaultPath(): DoctorCheck
    {
        $path = $this->vaultBasePath();
        $details = ['path' => $path, 'explicit' => $path !== storage_path('app/vaults')];

        if (trim($path) === '') {
            return DoctorCheck::fail('vault_path', 'VAULT_BASE_PATH', true, 'VAULT_BASE_PATH resolves to an empty path.', $details);
        }

        if (! is_dir($path)) {
            return DoctorCheck::fail('vault_path', 'VAULT_BASE_PATH', true, sprintf('%s does not exist or is not a directory.', $path), $details);
        }

        if (! is_writable($path)) {
            return DoctorCheck::fail('vault_path', 'VAULT_BASE_PATH', true, sprintf('%s is not writable by PHP.', $path), $details);
        }

        return DoctorCheck::pass('vault_path', 'VAULT_BASE_PATH', true, $path, $details);
    }

    private function checkVaultOutsideDocumentRoot(): DoctorCheck
    {
        // realpath('') resolves to the working directory; never let an empty path pass.
        $vault = trim($this->vaultBasePath()) === '' ? false : realpath($this->vaultBasePath());
        $documentRoot = realpath(public_path());
        $details = ['vault' => $vault ?: $this->vaultBasePath(), 'document_root' => $documentRoot ?: public_path()];

        if ($vault === false || $documentRoot === false) {
            return DoctorCheck::fail('vault_outside_docroot', 'Vault outside document root', true, 'Cannot resolve the vault or document root path.', $details);
        }

        $inside = $vault === $documentRoot || str_starts_with($vault.DIRECTORY_SEPARATOR, $documentRoot.DIRECTORY_SEPARATOR);

        return $inside
            ? DoctorCheck::fail('vault_outside_docroot', 'Vault outside document root', true, sprintf('%s is inside the document root %s; notes would be served as static files.', $vault, $documentRoot), $details)
            : DoctorCheck::pass('vault_outside_docroot', 'Vault outside document root', true, 'Vault is not under public/.', $details);
    }

    private function checkVaultDiskSpace(): DoctorCheck
    {
        $path = $this->vaultBasePath();
        $free = is_dir($path) ? @disk_free_space($path) : false;

        if ($free === false) {
            return DoctorCheck::fail('vault_disk_space', 'Vault free disk space', false, 'Could not determine free disk space for the vault path.', ['path' => $path]);
        }

        $details = ['path' => $path, 'free_bytes' => (int) $free, 'free_human' => $this->humanBytes((int) $free)];

        if ($free < self::DISK_CRITICAL_BYTES) {
            return DoctorCheck::fail('vault_disk_space', 'Vault free disk space', true, sprintf('Only %s free; writes will start failing.', $details['free_human']), $details);
        }

        if ($free < self::DISK_WARNING_BYTES) {
            return DoctorCheck::fail('vault_disk_space', 'Vault free disk space', false, sprintf('%s free; below the 1 GiB comfort margin.', $details['free_human']), $details);
        }

        return DoctorCheck::pass('vault_disk_space', 'Vault free disk space', true, $details['free_human'].' free', $details);
    }

    private function checkDatabase(): DoctorCheck
    {
        $connection = (string) config('database.default');

        try {
            DB::connection()->select('select 1');

            return DoctorCheck::pass('database', 'Database connection', true, sprintf('Connected (%s).', $connection), ['connection' => $connection]);
        } catch (\Throwable $exception) {
            return DoctorCheck::fail('database', 'Database connection', true, 'Cannot connect: '.$this->sanitize($exception->getMessage()), ['connection' => $connection]);
        }
    }

    private function checkPendingMigrations(bool $databaseReachable): DoctorCheck
    {
        if (! $databaseReachable) {
            return DoctorCheck::fail('migrations', 'Pending migrations', true, 'Skipped: database unavailable.');
        }

        try {
            if (! $this->migrator->repositoryExists()) {
                $pending = array_keys($this->migrator->getMigrationFiles($this->migrationPaths()));
            } else {
                $ran = $this->migrator->getRepository()->getRan();
                $pending = array_values(array_diff(array_keys($this->migrator->getMigrationFiles($this->migrationPaths())), $ran));
            }
        } catch (\Throwable $exception) {
            return DoctorCheck::fail('migrations', 'Pending migrations', true, 'Could not read migration state: '.$this->sanitize($exception->getMessage()));
        }

        return $pending === []
            ? DoctorCheck::pass('migrations', 'Pending migrations', true, 'None.')
            : DoctorCheck::fail('migrations', 'Pending migrations', true, sprintf('%d pending; run `php artisan migrate --force`.', count($pending)), ['pending' => $pending]);
    }

    /**
     * @return list<string>
     */
    private function migrationPaths(): array
    {
        return array_values(array_unique(array_merge($this->migrator->paths(), [database_path('migrations')])));
    }

    private function checkMailer(): DoctorCheck
    {
        $mailer = (string) config('mail.default');

        return $mailer !== 'log'
            ? DoctorCheck::pass('mail_mailer', 'MAIL_MAILER', false, $mailer, ['mailer' => $mailer])
            : DoctorCheck::fail('mail_mailer', 'MAIL_MAILER', false, 'MAIL_MAILER=log: notification emails are recorded as skipped and never delivered.', ['mailer' => $mailer]);
    }

    private function checkScheduler(): DoctorCheck
    {
        $lastRun = SchedulerHeartbeat::lastRunAt();
        $details = ['last_run_at' => $lastRun?->toIso8601String(), 'max_age_minutes' => self::SCHEDULER_MAX_AGE_MINUTES];

        if ($lastRun === null) {
            return DoctorCheck::fail('scheduler', 'Scheduler heartbeat', true, 'No `schedule:run` recorded yet. Add the per-minute cron entry for this installation.', $details);
        }

        $ageMinutes = $lastRun->diffInMinutes(CarbonImmutable::now());
        $details['age_minutes'] = (int) floor($ageMinutes);

        return $ageMinutes <= self::SCHEDULER_MAX_AGE_MINUTES
            ? DoctorCheck::pass('scheduler', 'Scheduler heartbeat', true, sprintf('Last run %s.', $lastRun->diffForHumans()), $details)
            : DoctorCheck::fail('scheduler', 'Scheduler heartbeat', true, sprintf('Last run %s; the cron entry is missing or stalled.', $lastRun->diffForHumans()), $details);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf($unit === 0 ? '%d %s' : '%.1f %s', $value, $units[$unit]);
    }

    /**
     * Keep credentials out of diagnostics: PDO messages can echo the DSN.
     */
    private function sanitize(string $message): string
    {
        $message = preg_replace('/password=[^;\s]*/i', 'password=***', $message) ?? $message;

        return mb_substr(trim($message), 0, 200);
    }
}
