<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PromoteAdmin extends Command
{
    protected $signature = 'platform:promote-admin {email}';

    protected $description = 'Flip is_admin=true for an existing user, regardless of how they authenticate (local password or SSO-provisioned)';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user found for {$email}. SSO-provisioned users are created on their first successful login; ask them to sign in once first.");

            return self::FAILURE;
        }

        if ($user->is_admin) {
            $this->info("{$email} is already an administrator.");

            return self::SUCCESS;
        }

        $user->update(['is_admin' => true]);

        $this->info("{$email} promoted to administrator.");

        return self::SUCCESS;
    }
}
