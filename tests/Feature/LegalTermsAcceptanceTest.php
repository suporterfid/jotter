<?php

namespace Tests\Feature;

use App\Domain\Auth\MachineTokenIssuer;
use App\Domain\Auth\MachineTokenOptions;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * LGPD: the accepted Terms/Privacy version is recorded, and a user is asked to
 * accept again when the operator publishes a new version (JOTTER_TERMS_VERSION).
 */
final class LegalTermsAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'jotter.auth_bypass' => false,
            'jotter.auth_provider' => 'local',
            'jotter.brand.terms_url' => 'https://example.com/termos',
            'jotter.brand.privacy_url' => 'https://example.com/privacidade',
            'jotter.legal.terms_version' => '2026-11-09',
        ]);
        $this->user = User::factory()->create(['is_admin' => false]);
    }

    public function test_unversioned_installations_never_ask_and_cannot_accept(): void
    {
        config(['jotter.legal.terms_version' => null]);

        $this->actingAs($this->user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.terms.version', null)
            ->assertJsonPath('data.terms.acceptance_required', false);
        $this->actingAs($this->user)->postJson('/api/auth/terms/accept')->assertStatus(409);
    }

    public function test_a_version_without_published_texts_asks_nothing(): void
    {
        config(['jotter.brand.terms_url' => null, 'jotter.brand.privacy_url' => null]);

        $this->actingAs($this->user)->getJson('/api/auth/me')
            ->assertJsonPath('data.terms.version', null)
            ->assertJsonPath('data.terms.acceptance_required', false);
    }

    public function test_a_user_accepts_the_current_version_and_is_asked_again_when_it_changes(): void
    {
        $this->actingAs($this->user)->getJson('/api/auth/me')
            ->assertJsonPath('data.terms.version', '2026-11-09')
            ->assertJsonPath('data.terms.accepted_version', null)
            ->assertJsonPath('data.terms.acceptance_required', true);

        $this->actingAs($this->user)->postJson('/api/auth/terms/accept')
            ->assertOk()
            ->assertJsonPath('data.terms.accepted_version', '2026-11-09')
            ->assertJsonPath('data.terms.acceptance_required', false);

        $this->user->refresh();
        $this->assertSame('2026-11-09', $this->user->terms_version);
        $this->assertNotNull($this->user->terms_accepted_at);
        $this->assertDatabaseHas('audit_log', ['event' => 'user.terms_accepted', 'actor_subject_id' => (string) $this->user->id]);

        // Accepting again is idempotent: no second audit row.
        $this->actingAs($this->user)->postJson('/api/auth/terms/accept')->assertOk();
        $this->assertSame(1, \App\Models\AuditLog::query()->where('event', 'user.terms_accepted')->count());

        config(['jotter.legal.terms_version' => '2027-01-15']);
        $this->actingAs($this->user)->getJson('/api/auth/me')
            ->assertJsonPath('data.terms.accepted_version', '2026-11-09')
            ->assertJsonPath('data.terms.acceptance_required', true);
    }

    public function test_the_login_answer_carries_the_terms_status(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => 'uma-senha-bem-longa-123']);

        $this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'uma-senha-bem-longa-123'])
            ->assertOk()
            ->assertJsonPath('data.terms.acceptance_required', true);
        $this->assertNull($user->refresh()->terms_version);
    }

    public function test_only_a_signed_in_browser_session_can_accept(): void
    {
        $this->postJson('/api/auth/terms/accept')->assertUnauthorized();

        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $options = MachineTokenOptions::fromInput($this->user, $tenant, ['api:write'], null, []);
        $plain = app(MachineTokenIssuer::class)->issue($this->user, $tenant, 'terms test', $options)['plain'];

        $status = $this->withToken($plain)->postJson('/api/auth/terms/accept')->status();
        $this->assertContains($status, [401, 403]);
        $this->assertNull($this->user->refresh()->terms_version);
    }

    public function test_sign_up_records_the_accepted_version(): void
    {
        config(['jotter.signup.enabled' => true]);
        RateLimiter::clear('signup:127.0.0.1');
        Mail::fake();

        $this->postJson('/api/auth/register', [
            'name' => 'Maria Souza',
            'email' => 'maria@example.com',
            'password' => 'uma-senha-bem-longa-123',
            'password_confirmation' => 'uma-senha-bem-longa-123',
            'accept_terms' => true,
        ])->assertStatus(202);

        $this->assertSame('2026-11-09', User::query()->where('email', 'maria@example.com')->value('terms_version'));
    }
}
