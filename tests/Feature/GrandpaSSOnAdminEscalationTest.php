<?php

namespace Tests\Feature;

use App\Domain\Auth\Providers\GrandpaSSOnIdentityProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GrandpaSSOnIdentityProvider::resolveIdentity() reads GrandpaSSOn's own `sessions`/`users`
 * tables directly via a raw PDO connection with a configurable prefix (jotter.sso.db_prefix),
 * since on shared hosting they live in the same MySQL database/schema as Jotter's own tables
 * but under a different prefix. These tables are created here (with GrandpaSSOn's real column
 * shape: id CHAR(36) UUID, primary_email, display_name, status, expires_at as a Unix
 * timestamp int) only for the duration of this test.
 */
final class GrandpaSSOnAdminEscalationTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = 'test_sso_';

    protected function setUp(): void
    {
        parent::setUp();

        config(['jotter.sso.db_prefix' => self::PREFIX]);

        Schema::create(self::PREFIX.'users', function ($table) {
            $table->string('id', 36)->primary();
            $table->string('primary_email');
            $table->string('display_name')->nullable();
            $table->string('status')->default('active');
        });

        Schema::create(self::PREFIX.'sessions', function ($table) {
            $table->string('id', 64)->primary();
            $table->string('user_id', 36)->nullable();
            $table->unsignedBigInteger('expires_at');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::PREFIX.'sessions');
        Schema::dropIfExists(self::PREFIX.'users');

        parent::tearDown();
    }

    public function test_a_brand_new_sso_user_is_not_granted_admin_by_default(): void
    {
        $ssoUserId = $this->insertSsoUser('new-sso-user@example.com', 'New SSO User');
        $sessionId = $this->insertSsoSession($ssoUserId, time() + 3600);

        $subject = $this->resolveWithCookie($sessionId);

        $this->assertNotNull($subject);
        $this->assertFalse($subject->isAdmin);
        $this->assertSame('new-sso-user@example.com', $subject->email);

        $localUser = User::query()->where('email', 'new-sso-user@example.com')->firstOrFail();
        $this->assertFalse((bool) $localUser->is_admin);
    }

    public function test_an_expired_session_is_not_resolved(): void
    {
        $ssoUserId = $this->insertSsoUser('expired-user@example.com', 'Expired User');
        $sessionId = $this->insertSsoSession($ssoUserId, time() - 60);

        $subject = $this->resolveWithCookie($sessionId);

        $this->assertNull($subject);
        $this->assertDatabaseMissing('users', ['email' => 'expired-user@example.com']);
    }

    public function test_an_unknown_session_id_is_not_resolved(): void
    {
        $subject = $this->resolveWithCookie('does-not-exist');

        $this->assertNull($subject);
    }

    public function test_a_disabled_sso_user_is_not_resolved(): void
    {
        $ssoUserId = $this->insertSsoUser('disabled-user@example.com', 'Disabled User', 'disabled');
        $sessionId = $this->insertSsoSession($ssoUserId, time() + 3600);

        $subject = $this->resolveWithCookie($sessionId);

        $this->assertNull($subject);
    }

    private function insertSsoUser(string $email, string $displayName, string $status = 'active'): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();

        DB::table(self::PREFIX.'users')->insert([
            'id' => $id,
            'primary_email' => $email,
            'display_name' => $displayName,
            'status' => $status,
        ]);

        return $id;
    }

    private function insertSsoSession(string $ssoUserId, int $expiresAt): string
    {
        $sessionId = 'sso-session-'.uniqid();

        DB::table(self::PREFIX.'sessions')->insert([
            'id' => $sessionId,
            'user_id' => $ssoUserId,
            'expires_at' => $expiresAt,
        ]);

        return $sessionId;
    }

    private function resolveWithCookie(string $authSessId): ?\App\Domain\Auth\AuthenticatedSubject
    {
        $request = Request::create('/api/notes', 'GET');
        $request->cookies->set('AUTHSESSID', $authSessId);

        return (new GrandpaSSOnIdentityProvider())->resolveIdentity($request);
    }
}
