<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Turns two-step verification off for someone who lost their authenticator
 * and their recovery codes (block 4b). Check who is asking first: this is the
 * way around the second factor.
 */
final class UserTwoFactorResetCommand extends Command
{
    protected $signature = 'user:two-factor-reset {email : E-mail of the account} {--force : Do not ask for confirmation}';

    protected $description = 'Turn two-step verification off for an account (lost authenticator).';

    public function handle(AuditRecorder $audit): int
    {
        $user = User::query()->where('email', strtolower(trim((string) $this->argument('email'))))->first();
        if ($user === null) {
            $this->error(sprintf('No user with e-mail [%s].', $this->argument('email')));

            return self::FAILURE;
        }
        if ((string) $user->two_factor_secret === '' && $user->two_factor_confirmed_at === null) {
            $this->info(sprintf('%s has no two-step verification; nothing to reset.', $user->email));

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm(sprintf('Turn two-step verification off for %s? Confirm who is asking first.', $user->email), false)) {
            $this->comment('Nothing was changed.');

            return self::FAILURE;
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_used_step' => null])->save();
        $audit->record(event: AuditEvent::TWO_FACTOR_RESET, actorId: 'cli:user:two-factor-reset', metadata: ['user_id' => $user->id]);

        $this->info(sprintf('Two-step verification is off for %s. They can set it up again in "Your profile".', $user->email));

        return self::SUCCESS;
    }
}
