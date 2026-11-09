<?php

namespace Tests\Feature;

use App\Mail\SignupApprovedEmail;
use App\Mail\SignupRejectedEmail;
use App\Mail\SignupRequestedEmail;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * #390 item 1: self sign-up with administrator approval.
 */
final class SelfSignupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local', 'jotter.signup.enabled' => true, 'jotter.brand.terms_url' => null, 'jotter.brand.privacy_url' => null]);
        RateLimiter::clear('signup:127.0.0.1');
        Mail::fake();
        $this->admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@acme.test']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function register(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/register', array_merge([
            'name' => 'Maria Souza',
            'email' => 'Maria@Example.com',
            'password' => 'uma-senha-bem-longa-123',
            'password_confirmation' => 'uma-senha-bem-longa-123',
            'locale' => 'pt-BR',
        ], $overrides));
    }

    public function test_a_request_creates_an_inactive_pending_account_and_tells_the_admins(): void
    {
        $this->register()->assertStatus(202);

        $user = User::query()->where('email', 'maria@example.com')->sole();
        $this->assertFalse($user->is_active);
        $this->assertFalse($user->is_admin);
        $this->assertSame('pending', $user->signup_status);
        $this->assertSame('pt-BR', $user->locale);
        Mail::assertSent(SignupRequestedEmail::class, static fn (SignupRequestedEmail $mail): bool => $mail->hasTo('admin@acme.test') && $mail->applicant->is($user));
        $this->assertDatabaseHas('audit_log', ['event' => 'signup.requested']);
    }

    public function test_a_pending_account_cannot_sign_in_and_is_told_why_only_with_the_right_password(): void
    {
        $this->register();

        $this->postJson('/api/auth/login', ['email' => 'maria@example.com', 'password' => 'uma-senha-bem-longa-123'])
            ->assertStatus(403)->assertJsonPath('signup_pending', true);
        $this->postJson('/api/auth/login', ['email' => 'maria@example.com', 'password' => 'errada-errada-errada'])
            ->assertStatus(401)->assertJsonMissingPath('signup_pending');
        $this->assertGuest();
    }

    public function test_the_answer_is_the_same_for_an_existing_email(): void
    {
        $first = $this->postJson('/api/auth/register', [
            'name' => 'Intruso', 'email' => 'admin@acme.test',
            'password' => 'uma-senha-bem-longa-123', 'password_confirmation' => 'uma-senha-bem-longa-123',
        ]);

        $first->assertStatus(202)->assertJsonPath('message', __('messages.signup_received'));
        $this->assertSame(1, User::query()->where('email', 'admin@acme.test')->count());
        $this->assertTrue((bool) User::query()->where('email', 'admin@acme.test')->value('is_admin'), 'the existing account is untouched');
        Mail::assertNothingSent();
    }

    public function test_approval_activates_the_account_adds_the_default_membership_and_emails_the_person(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'geral', 'name' => 'Geral', 'vault_path' => storage_path('app/vaults/signup_'.uniqid())]);
        config(['jotter.signup.default_workspace' => 'geral', 'jotter.signup.default_role' => 'editor']);
        $this->register();
        $user = User::query()->where('email', 'maria@example.com')->sole();

        $this->actingAs($this->admin)->postJson("/api/admin/users/{$user->id}/approve")->assertOk()->assertJsonPath('data.workspace', 'geral');

        $user->refresh();
        $this->assertTrue($user->is_active);
        $this->assertSame('approved', $user->signup_status);
        $this->assertSame('editor', Membership::query()->where('workspace_id', $workspace->id)->where('subject_id', (string) $user->id)->value('role'));
        Mail::assertSent(SignupApprovedEmail::class, static fn (SignupApprovedEmail $mail): bool => $mail->hasTo('maria@example.com'));

        $this->actingAs($this->admin)->postJson("/api/admin/users/{$user->id}/approve")->assertStatus(422);

        auth()->logout();
        $this->postJson('/api/auth/login', ['email' => 'maria@example.com', 'password' => 'uma-senha-bem-longa-123'])->assertOk();
    }

    public function test_rejection_deletes_the_request_and_tells_the_person(): void
    {
        $this->register();
        $user = User::query()->where('email', 'maria@example.com')->sole();

        $this->actingAs($this->admin)->postJson("/api/admin/users/{$user->id}/reject")->assertOk();

        $this->assertNull(User::query()->find($user->id));
        Mail::assertSent(SignupRejectedEmail::class, static fn (SignupRejectedEmail $mail): bool => $mail->hasTo('maria@example.com'));
        $this->assertDatabaseHas('audit_log', ['event' => 'signup.rejected']);
    }

    public function test_only_administrators_decide(): void
    {
        $this->register();
        $user = User::query()->where('email', 'maria@example.com')->sole();
        $member = User::factory()->create(['is_admin' => false]);

        $this->actingAs($member)->postJson("/api/admin/users/{$user->id}/approve")->assertForbidden();
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_sign_up_is_off_by_default_and_under_other_providers(): void
    {
        config(['jotter.signup.enabled' => false]);
        $this->register()->assertNotFound();
        $this->getJson('/api/auth/config')->assertJsonPath('data.signup_enabled', false);

        config(['jotter.signup.enabled' => true, 'jotter.auth_provider' => 'oidc']);
        $this->register()->assertNotFound();

        config(['jotter.auth_provider' => 'local']);
        $this->getJson('/api/auth/config')->assertJsonPath('data.signup_enabled', true);
    }

    public function test_terms_must_be_accepted_when_the_operator_publishes_them(): void
    {
        config(['jotter.brand.terms_url' => 'https://example.com/termos', 'jotter.brand.privacy_url' => 'https://example.com/privacidade']);

        $this->register()->assertStatus(422)->assertJsonValidationErrors('accept_terms');
        $this->register(['accept_terms' => true])->assertStatus(202);
        $this->assertNotNull(User::query()->where('email', 'maria@example.com')->value('terms_accepted_at'));
    }

    public function test_domains_can_be_restricted_and_requests_are_rate_limited(): void
    {
        config(['jotter.signup.allowed_domains' => ['acme.test']]);
        $this->register()->assertStatus(422)->assertJsonValidationErrors('email');

        config(['jotter.signup.allowed_domains' => [], 'jotter.signup.requests_per_hour' => 2]);
        $this->register(['email' => 'a1@example.com'])->assertStatus(202);
        $this->register(['email' => 'a2@example.com'])->assertStatus(429);
    }
}
