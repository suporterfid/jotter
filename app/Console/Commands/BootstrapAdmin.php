<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BootstrapAdmin extends Command
{
    protected $signature = 'platform:bootstrap-admin {email} {password}';

    protected $description = 'Create or update the first local platform administrator';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $password = (string) $this->argument('password');

        $validator = Validator::make(
            compact('email', 'password'),
            [
                'email' => ['required', 'email:rfc', 'max:255'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        /** @var User|null $existing */
        $existing = User::query()->where('email', $email)->first();

        if ($existing) {
            $existing->update([
                'password' => Hash::make($password),
                'is_admin' => true,
            ]);

            $this->info("Administrator password updated for {$email}.");

            return self::SUCCESS;
        }

        User::query()->create([
            'name' => Str::before($email, '@') ?: 'Administrator',
            'email' => $email,
            'password' => Hash::make($password),
            'is_admin' => true,
        ]);

        $this->info("Administrator created for {$email}.");

        return self::SUCCESS;
    }
}
