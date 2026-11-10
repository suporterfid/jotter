<?php

namespace Tests\Feature;

use App\Domain\Auth\MachineTokenIssuer;
use App\Domain\Auth\MachineTokenOptions;
use App\Domain\Auth\TwoFactor\Totp;
use App\Domain\Auth\TwoFactor\TwoFactorChallenge;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Block 4b: TOTP two-step verification for password sign-ins.
 */
final class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'uma-senha-bem-longa-123';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jotter.auth_bypass' => false, 'jotter.auth_provider' => 'local', 'jotter.two_factor.required_for_admins' => false]);
        $this->user = User::factory()->create(['email' => 'ana@example.com', 'password' => self::PASSWORD, 'is_admin' => false]);
        RateLimiter::clear('two-factor:'.$this->user->id);
        RateLimiter::clear('two-factor-password:'.$this->user->id);
    }

    /** Turns 2FA on through the API; returns [secret, recovery codes]. */
    private function enable(User $user): array
    {
        $secret = $this->actingAs($user)->postJson('/api/user/two-factor', ['password' => self::PASSWORD])->assertOk()->json('data.secret');
        $codes = $this->actingAs($user)->postJson('/api/user/two-factor/confirm', ['code' => Totp::code($secret, Totp::step())])->assertOk()->json('data.recovery_codes');
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return [$secret, $codes];
    }

    private function login(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => self::PASSWORD]);
    }

    public function test_set_up_needs_the_password_and_a_working_code(): void
    {
        $this->actingAs($this->user)->postJson('/api/user/two-factor', ['password' => 'errada'])->assertStatus(422)->assertJsonValidationErrors('password');

        $data = $this->actingAs($this->user)->postJson('/api/user/two-factor', ['password' => self::PASSWORD])->assertOk()->json('data');
        $this->assertSame(32, strlen($data['secret']));
        $this->assertStringStartsWith('otpauth://totp/', $data['otpauth_uri']);
        $this->assertFalse($this->user->refresh()->hasTwoFactor(), 'not on before a code confirms it');

        $this->actingAs($this->user)->postJson('/api/user/two-factor/confirm', ['code' => '000000'])->assertStatus(422);
        $codes = $this->actingAs($this->user)->postJson('/api/user/two-factor/confirm', ['code' => Totp::code($data['secret'], Totp::step())])
            ->assertOk()
            ->assertJsonPath('data.two_factor.enabled', true)
            ->json('data.recovery_codes');
        $this->assertCount(10, $codes);

        $user = $this->user->refresh();
        $this->assertTrue($user->hasTwoFactor());
        $this->assertNotSame($data['secret'], \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->value('two_factor_secret'), 'the secret is encrypted at rest');
        $this->assertDatabaseHas('audit_log', ['event' => 'user.two_factor_enabled']);
        $this->actingAs($this->user)->getJson('/api/auth/me')->assertJsonPath('data.two_factor.enabled', true)->assertJsonPath('data.two_factor.recovery_codes_left', 10);
        $this->actingAs($this->user)->postJson('/api/user/two-factor', ['password' => self::PASSWORD])->assertStatus(409);
    }

    public function test_sign_in_waits_for_the_code_and_a_code_works_once(): void
    {
        [$secret] = $this->enable($this->user);

        $this->login()->assertStatus(202)->assertJsonPath('data.two_factor_required', true);
        $this->assertGuest('web');

        $this->postJson('/api/auth/two-factor', ['code' => '000000'])->assertStatus(422);
        $this->assertGuest('web');

        $step = Totp::step();
        $code = Totp::code($secret, $step + 1);
        $this->postJson('/api/auth/two-factor', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.email', 'ana@example.com')
            ->assertJsonPath('data.two_factor.enabled', true);
        $this->assertAuthenticatedAs($this->user, 'web');
        $this->assertSame(1, AuditLog::query()->where('event', 'auth.login_success')->where('metadata->two_factor', 'totp')->count());

        // The same code cannot open a second session.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->login()->assertStatus(202);
        $this->postJson('/api/auth/two-factor', ['code' => $code])->assertStatus(422);
    }

    public function test_a_recovery_code_signs_in_once(): void
    {
        [, $codes] = $this->enable($this->user);

        $this->login()->assertStatus(202);
        $this->postJson('/api/auth/two-factor', ['recovery_code' => strtoupper($codes[0])])->assertOk();
        $this->assertSame(9, count((array) $this->user->refresh()->two_factor_recovery_codes));
        $this->assertDatabaseHas('audit_log', ['event' => 'user.two_factor_recovery_code_used']);

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->login()->assertStatus(202);
        $this->postJson('/api/auth/two-factor', ['recovery_code' => $codes[0]])->assertStatus(422);
    }

    public function test_too_many_wrong_codes_or_too_much_time_restart_the_sign_in(): void
    {
        [$secret] = $this->enable($this->user);

        $this->login()->assertStatus(202);
        for ($i = 1; $i < TwoFactorChallenge::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/auth/two-factor', ['code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/auth/two-factor', ['code' => '000000'])->assertStatus(401)->assertJsonPath('two_factor_expired', true);
        $this->postJson('/api/auth/two-factor', ['code' => Totp::code($secret, Totp::step() + 1)])->assertStatus(401);
        $this->assertGuest('web');

        RateLimiter::clear('two-factor:'.$this->user->id);
        $this->login()->assertStatus(202);
        $this->travel(TwoFactorChallenge::TTL_SECONDS + 60)->seconds();
        $this->postJson('/api/auth/two-factor', ['code' => Totp::code($secret, Totp::step() + 1)])->assertStatus(401);
        $this->assertGuest('web');
    }

    public function test_without_a_pending_challenge_nothing_signs_in(): void
    {
        $this->postJson('/api/auth/two-factor', ['code' => '123456'])->assertStatus(401);
        $this->assertGuest('web');
    }

    public function test_turning_it_off_needs_the_password_and_restores_plain_sign_in(): void
    {
        $this->enable($this->user);

        $this->actingAs($this->user)->deleteJson('/api/user/two-factor', ['password' => 'errada'])->assertStatus(422);
        $this->assertTrue($this->user->refresh()->hasTwoFactor());

        $this->actingAs($this->user)->deleteJson('/api/user/two-factor', ['password' => self::PASSWORD])->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertFalse($this->user->refresh()->hasTwoFactor());
        $this->assertDatabaseHas('audit_log', ['event' => 'user.two_factor_disabled']);

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->login()->assertOk();
    }

    public function test_recovery_codes_can_be_renewed(): void
    {
        [, $old] = $this->enable($this->user);

        $new = $this->actingAs($this->user)->postJson('/api/user/two-factor/recovery-codes', ['password' => self::PASSWORD])->assertOk()->json('data.recovery_codes');
        $this->assertCount(10, $new);
        $this->assertEmpty(array_intersect($old, $new));
        $this->assertDatabaseHas('audit_log', ['event' => 'user.two_factor_recovery_codes_regenerated']);
    }

    public function test_administrators_can_be_required_to_use_it(): void
    {
        $admin = User::factory()->create(['email' => 'root@example.com', 'password' => self::PASSWORD, 'is_admin' => true]);
        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();

        config(['jotter.two_factor.required_for_admins' => true]);
        $this->actingAs($admin)->getJson('/api/admin/users')->assertStatus(403)->assertJsonPath('code', 'two_factor_setup_required');
        $this->actingAs($admin)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.two_factor.required', true);
        $this->actingAs($this->user)->getJson('/api/auth/me')->assertJsonPath('data.two_factor.required', false);

        $this->enable($admin);
        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();

        // Under an identity provider the requirement does not apply.
        config(['jotter.auth_provider' => 'oidc']);
        $admin->forceFill(['two_factor_confirmed_at' => null])->save();
        $this->assertSame(200, $this->actingAs($admin)->getJson('/api/admin/users')->status());
    }

    public function test_it_is_not_offered_under_oidc_and_tokens_never_reach_it(): void
    {
        config(['jotter.auth_provider' => 'oidc']);
        $this->actingAs($this->user)->postJson('/api/user/two-factor', ['password' => self::PASSWORD])->assertStatus(409);

        config(['jotter.auth_provider' => 'local']);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $options = MachineTokenOptions::fromInput($this->user, $tenant, ['api:write'], null, []);
        $plain = app(MachineTokenIssuer::class)->issue($this->user, $tenant, 'agent', $options)['plain'];
        $this->app['auth']->forgetGuards();
        $this->assertContains($this->withToken($plain)->postJson('/api/user/two-factor', ['password' => self::PASSWORD])->status(), [401, 403]);
    }

    public function test_the_reset_command_turns_it_off(): void
    {
        $this->enable($this->user);

        $this->artisan('user:two-factor-reset', ['email' => 'ana@example.com', '--force' => true])->assertSuccessful();

        $this->assertFalse($this->user->refresh()->hasTwoFactor());
        $this->assertNull($this->user->two_factor_secret);
        $this->assertDatabaseHas('audit_log', ['event' => 'user.two_factor_reset']);
        $this->artisan('user:two-factor-reset', ['email' => 'nobody@example.com', '--force' => true])->assertFailed();
    }
}
