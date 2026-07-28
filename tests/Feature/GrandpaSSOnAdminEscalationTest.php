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
 * GrandpaSSOnIdentityProvider::resolveIdentity() reads its AUTHSESSID cookie path against
 * generic `sessions`/`users` columns (expires_at, primary_email, display_name, status) that
 * do not exist on Jotter's own schema; these columns are added here only for the duration of
 * this test to faithfully exercise that code path.
 */
final class GrandpaSSOnAdminEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // expires_at is an unsigned integer here (unix timestamp) to match how
        // resolveIdentity() actually compares it (`> time()`); a real GrandpaSSOn
        // deployment's `sessions` schema is unspecified and out of scope for this stub.
        Schema::table('sessions', function ($table) {
            $table->unsignedBigInteger('expires_at')->nullable();
        });

        Schema::table('users', function ($table) {
            $table->string('primary_email')->nullable();
            $table->string('display_name')->nullable();
            $table->string('status')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::table('sessions', function ($table) {
            $table->dropColumn('expires_at');
        });

        Schema::table('users', function ($table) {
            $table->dropColumn(['primary_email', 'display_name', 'status']);
        });

        parent::tearDown();
    }

    public function test_a_brand_new_sso_user_is_not_granted_admin_by_default(): void
    {
        $ssoUserId = 999001;

        DB::table('users')->insert([
            'id' => $ssoUserId,
            'name' => 'SSO Placeholder',
            'email' => 'sso-placeholder-'.uniqid().'@example.com',
            'password' => bcrypt('unused'),
            'is_admin' => false,
            'primary_email' => 'new-sso-user@example.com',
            'display_name' => 'New SSO User',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sessions')->insert([
            'id' => 'sso-session-'.uniqid(),
            'user_id' => $ssoUserId,
            'expires_at' => time() + 3600,
            'last_activity' => time(),
            'payload' => base64_encode(serialize([])),
        ]);

        $sessionId = DB::table('sessions')->where('user_id', $ssoUserId)->value('id');

        $request = Request::create('/api/notes', 'GET');
        $request->cookies->set('AUTHSESSID', $sessionId);

        $provider = new GrandpaSSOnIdentityProvider();
        $subject = $provider->resolveIdentity($request);

        $this->assertNotNull($subject);
        $this->assertFalse($subject->isAdmin);

        $localUser = User::query()->where('email', 'new-sso-user@example.com')->firstOrFail();
        $this->assertFalse((bool) $localUser->is_admin);
    }
}
