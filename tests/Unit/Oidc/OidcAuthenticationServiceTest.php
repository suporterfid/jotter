<?php

namespace Tests\Unit\Oidc;

use App\Domain\Auth\Oidc\OidcAuthenticationException;
use App\Domain\Auth\Oidc\OidcAuthenticationService;
use App\Domain\Auth\Oidc\OidcClaims;
use App\Models\Identity;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OidcAuthenticationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_verified_subject_creates_a_non_admin_without_membership(): void
    {
        $subject = app(OidcAuthenticationService::class)->provision($this->claims());

        $this->assertNotNull($subject->user);
        $this->assertFalse($subject->user->is_admin);
        $this->assertTrue($subject->user->is_active);
        $this->assertSame('Person Example', $subject->user->name);
        $this->assertSame('en', $subject->user->locale);
        $this->assertNotEmpty($subject->user->password);
        $this->assertFalse(Hash::check('known-password', $subject->user->password));

        $this->assertDatabaseHas('identities', [
            'user_id' => $subject->user->id,
            'provider' => 'oidc',
            'subject_id' => 'https://issuer.example.test|subject-123',
        ]);
        $this->assertSame(0, Membership::query()->count());
    }

    public function test_same_issuer_and_subject_reuses_the_existing_user(): void
    {
        $service = app(OidcAuthenticationService::class);
        $first = $service->provision($this->claims());
        $second = $service->provision($this->claims(name: 'Renamed Person'));

        $this->assertSame($first->user->id, $second->user->id);
        $this->assertSame('Renamed Person', $first->user->fresh()->name);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Identity::query()->where('provider', 'oidc')->count());
    }

    public function test_verified_email_links_an_existing_local_user(): void
    {
        $user = User::factory()->create(['email' => 'person@example.test']);

        $subject = app(OidcAuthenticationService::class)->provision($this->claims());

        $this->assertSame($user->id, $subject->user->id);
        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseHas('identities', [
            'user_id' => $user->id,
            'provider' => 'oidc',
        ]);
    }

    public function test_inactive_user_for_an_existing_identity_is_rejected(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        Identity::create([
            'user_id' => $user->id,
            'provider' => 'oidc',
            'subject_id' => 'https://issuer.example.test|subject-123',
        ]);

        $this->expectException(OidcAuthenticationException::class);
        app(OidcAuthenticationService::class)->provision($this->claims());
    }

    private function claims(
        string $name = 'Person Example',
        string $email = 'person@example.test',
    ): OidcClaims {
        return new OidcClaims(
            issuer: 'https://issuer.example.test/',
            subject: 'subject-123',
            email: $email,
            emailVerified: true,
            name: $name,
            locale: 'en',
        );
    }
}
