<?php

namespace App\Domain\Vault\Store\GitHub;

use App\Domain\Health\DoctorCheck;
use App\Domain\Vault\Store\VaultStoreDrivers;
use App\Models\TenantVaultStore;
use App\Models\VaultJournalEntry;
use App\Models\VaultSyncState;
use App\Models\Workspace;
use Illuminate\Support\Facades\Schema;

/**
 * `jotter:doctor` for GitHub stores (#439, #440, #441, #444): App
 * configuration, PAT expiry (14 days ahead), pending flush queue and how long
 * it has been failing, age of the last webhook delivery, repository size,
 * ignored or renamed paths, and the LGPD reminder about Git history. No
 * network call: it reads what the sync recorded.
 */
class GitHubDoctor
{
    public const PAT_WARN_DAYS = 14;

    public function __construct(private readonly GitHubAppAuth $app = new GitHubAppAuth) {}

    public function check(bool $databaseReachable): DoctorCheck
    {
        $label = 'GitHub vault stores';
        if (! (new VaultStoreDrivers)->isEnabled(VaultStoreDrivers::GITHUB)) {
            return DoctorCheck::pass('github_vault', $label, false, 'GitHub driver off (JOTTER_VAULT_DRIVERS).');
        }
        if (! $databaseReachable || ! Schema::hasTable('vault_sync_states')) {
            return DoctorCheck::pass('github_vault', $label, false, 'Database not reachable; GitHub stores not checked.');
        }

        $problems = [];
        $warnings = [];
        $details = ['stores' => []];
        $stores = TenantVaultStore::query()->where('driver', VaultStoreDrivers::GITHUB)->orderBy('id')->get();
        $usesApp = $stores->contains(fn (TenantVaultStore $s): bool => ! empty($s->settings['installation_id']));

        if ($usesApp || $this->app->appId() !== null) {
            foreach ($this->app->configurationProblems() as $problem) {
                $problems[] = $problem;
            }
        }

        foreach ($stores as $store) {
            $state = VaultSyncState::query()->where('vault_store_id', $store->id)->first();
            $pending = VaultJournalEntry::query()->where('vault_store_id', $store->id)->whereNull('flushed_at');
            $oldest = (clone $pending)->min('created_at');
            $workspace = Workspace::query()->where('vault_store_id', $store->id)->value('slug');
            $name = $store->name;

            $row = [
                'id' => $store->id,
                'name' => $name,
                'repository' => ($store->settings['owner'] ?? '').'/'.($store->settings['repo'] ?? '').'@'.($store->settings['branch'] ?? 'main'),
                'mode' => empty($store->settings['installation_id']) ? 'pat' : 'app',
                'workspace' => $workspace,
                'status' => $store->status,
                'pending_writes' => (clone $pending)->count(),
                'oldest_pending_at' => $oldest,
                'last_flush_at' => $state?->last_flush_at?->toIso8601String(),
                'last_pull_at' => $state?->last_pull_at?->toIso8601String(),
                'last_webhook_at' => $state?->last_webhook_at?->toIso8601String(),
                'failing_since' => $state?->failing_since?->toIso8601String(),
                'repo_size_kb' => $state?->repo_size_kb,
                'ignored_paths' => [],
                'renamed_paths' => [],
            ];
            foreach (($state?->warnings ?? []) as $warning) {
                if (($warning['type'] ?? '') === 'ignored') {
                    $row['ignored_paths'][] = $warning['path'].' ('.$warning['reason'].')';
                } elseif (in_array($warning['type'] ?? '', ['case_twin', 'nfd'], true)) {
                    $row['renamed_paths'][] = $warning['path'].' → '.$warning['renamed_to'];
                }
            }
            $details['stores'][] = $row;

            if ($store->status === TenantVaultStore::STATUS_FAILED) {
                $problems[] = "{$name}: GitHub refused access ({$store->last_error}); the workspace is read-only until it is reconnected";
            }
            if ($state?->failing_since !== null && $state->failing_since->lte(now()->subDay())) {
                $problems[] = "{$name}: changes have not reached GitHub since {$state->failing_since->toDateTimeString()}";
            } elseif ($state?->failing_since !== null) {
                $warnings[] = "{$name}: flush retrying ({$state->last_error})";
            }
            if ($oldest !== null && now()->diffInMinutes($oldest, true) > 30 && $state?->failing_since === null) {
                $warnings[] = "{$name}: {$row['pending_writes']} edit(s) waiting for GitHub for more than 30 minutes (write budget?)";
            }
            $expires = $store->settings['token_expires_at'] ?? null;
            if (empty($store->settings['installation_id']) && is_string($expires) && strtotime($expires) !== false) {
                $days = (int) floor((strtotime($expires) - time()) / 86400);
                if ($days < 0) {
                    $problems[] = "{$name}: the fine-grained token expired; paste a new one";
                } elseif ($days <= self::PAT_WARN_DAYS) {
                    $warnings[] = "{$name}: the fine-grained token expires in {$days} day(s)";
                }
            }
            if ($state?->repo_size_kb !== null && $state->repo_size_kb > (int) config('jotter.github.repo_warn_kb', 800 * 1024)) {
                $warnings[] = sprintf('%s: the repository is %d MB (GitHub recommends staying under 1 GB)', $name, (int) round($state->repo_size_kb / 1024));
            }
            if ($workspace !== null && $state?->last_webhook_at === null && ! empty($store->settings['installation_id']) && (string) config('jotter.github.webhook_secret', '') !== '') {
                $warnings[] = "{$name}: no webhook delivery received yet (external edits arrive by polling, up to 5 minutes)";
            } elseif ($state?->last_webhook_at !== null && $state->last_webhook_at->lte(now()->subDays(7))) {
                $warnings[] = "{$name}: last webhook delivery {$state->last_webhook_at->diffForHumans()}";
            }
            if ($row['ignored_paths'] !== []) {
                $warnings[] = sprintf('%s: %d path(s) in the repository are ignored', $name, count($row['ignored_paths']));
            }
            if ($row['renamed_paths'] !== []) {
                $warnings[] = sprintf('%s: %d path(s) were renamed (case twins or NFD)', $name, count($row['renamed_paths']));
            }
        }

        $details['warnings'] = $warnings;
        $lgpd = ' Git history keeps deleted content: the organization that owns the repository controls it (LGPD, docs/legal).';

        if ($problems !== []) {
            $extra = $warnings === [] ? '' : ' Warnings: '.implode('; ', $warnings).'.';

            return DoctorCheck::fail('github_vault', $label, false, implode('; ', $problems).'.'.$extra.$lgpd, $details);
        }

        $count = $stores->count();
        $summary = $count === 0 ? 'No GitHub store yet.' : sprintf('%d GitHub store%s working.', $count, $count === 1 ? '' : 's');
        if ($warnings !== []) {
            $summary .= ' Warnings: '.implode('; ', $warnings).'.';
        }

        return DoctorCheck::pass('github_vault', $label, false, $summary.$lgpd, $details);
    }
}
