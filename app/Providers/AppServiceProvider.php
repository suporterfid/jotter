<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Domain\Auth\Contracts\IdentityProvider::class, function (): \App\Domain\Auth\Contracts\IdentityProvider {
            $provider = config('jotter.auth_provider', 'local');

            return match ($provider) {
                'grandpasson' => new \App\Domain\Auth\Providers\GrandpaSSOnIdentityProvider,
                default => new \App\Domain\Auth\Providers\LocalIdentityProvider,
            };
        });

        $this->app->singleton(\App\Domain\Jobs\Contracts\JobDispatcher::class, function (): \App\Domain\Jobs\Contracts\JobDispatcher {
            $dispatcher = config('jotter.job_dispatcher', 'local');

            return match ($dispatcher) {
                'taskconnect' => new \App\Domain\Jobs\TaskConnectJobDispatcher,
                default => new \App\Domain\Jobs\LocalJobDispatcher,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Models\Workspace::observe(\App\Observers\WorkspaceObserver::class);
    }
}
