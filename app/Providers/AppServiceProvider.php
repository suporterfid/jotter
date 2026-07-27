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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
