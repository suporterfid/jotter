<?php

namespace Tests\Unit;

use App\Domain\Auth\Providers\LocalIdentityProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

final class LocalIdentityProviderLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolved_subject_carries_the_users_locale(): void
    {
        $user = User::create([
            'name' => 'EN User',
            'email' => 'en-user@example.com',
            'password' => bcrypt('irrelevant'),
            'locale' => 'en',
        ]);

        Auth::guard('web')->login($user);
        $request = Request::create('/api/auth/me', 'GET');
        $request->setLaravelSession(app('session.store'));

        $subject = (new LocalIdentityProvider())->resolveIdentity($request);

        $this->assertNotNull($subject);
        $this->assertSame('en', $subject->locale);
    }
}
