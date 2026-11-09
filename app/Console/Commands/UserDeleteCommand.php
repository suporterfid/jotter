<?php

namespace App\Console\Commands;

use App\Domain\Users\UserDeletion;
use App\Models\User;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Deletes an account from SSH (#390, LGPD): same rules as Administration → Users.
 */
final class UserDeleteCommand extends Command
{
    protected $signature = 'user:delete {email : E-mail of the account} {--force : Do not ask for confirmation} {--json : Emit JSON}';

    protected $description = 'Delete a user account, its memberships, tokens and grants; anonymise its comments and audit entries.';

    public function handle(UserDeletion $deletion): int
    {
        $user = User::query()->where('email', strtolower(trim((string) $this->argument('email'))))->first();
        if ($user === null) {
            $this->error(sprintf('No user with e-mail [%s].', $this->argument('email')));

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm(sprintf('Delete %s <%s> for good? Notes stay in their workspaces.', $user->name, $user->email), false)) {
            $this->comment('Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $counts = $deletion->delete($user, 'cli:user:delete');
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->option('json')
            ? $this->line((string) json_encode(['deleted' => true] + $counts))
            : $this->info('Deleted. '.collect($counts)->map(static fn (int $n, string $k): string => "{$k}: {$n}")->implode(', '));

        return self::SUCCESS;
    }
}
