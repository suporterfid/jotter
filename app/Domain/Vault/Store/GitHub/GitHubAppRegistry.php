<?php

namespace App\Domain\Vault\Store\GitHub;

use App\Models\GitHubAppRegistration;
use Illuminate\Database\QueryException;

/**
 * The App registered from the admin panel, read once per request or job
 * (bound as a scoped singleton). Missing table = nothing registered, so the
 * doctor still runs before migrations.
 */
class GitHubAppRegistry
{
    private bool $loaded = false;

    private ?GitHubAppRegistration $registration = null;

    public function current(): ?GitHubAppRegistration
    {
        if (! $this->loaded) {
            try {
                $this->registration = GitHubAppRegistration::query()->latest('id')->first();
            } catch (QueryException) {
                $this->registration = null;
            }
            $this->loaded = true;
        }

        return $this->registration;
    }

    public function forget(): void
    {
        $this->loaded = false;
        $this->registration = null;
    }
}
