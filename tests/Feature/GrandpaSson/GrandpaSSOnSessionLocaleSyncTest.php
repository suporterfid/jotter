<?php

namespace Tests\Feature\GrandpaSson;

use App\Domain\Auth\Providers\GrandpaSSOnIdentityProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GrandpaSSOnSessionLocaleSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE TABLE IF NOT EXISTS sso_users (
            id CHAR(36) NOT NULL PRIMARY KEY,
            primary_email VARCHAR(255) NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL,
            locale VARCHAR(10) NOT NULL DEFAULT "pt-BR"
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS sso_sessions (
            id CHAR(64) NOT NULL PRIMARY KEY,
            user_id CHAR(36) NULL,
            expires_at INT UNSIGNED NOT NULL
        )');

        config(['jotter.auth_provider' => 'grandpasson', 'jotter.sso.db_prefix' => 'sso_']);
    }

    protected function tearDown(): void
    {
        DB::statement('DROP TABLE IF EXISTS sso_sessions');
        DB::statement('DROP TABLE IF EXISTS sso_users');

        parent::tearDown();
    }

    public function test_locale_is_read_from_the_sso_user_row(): void
    {
        DB::table('sso_users')->insert([
            'id' => 'sso-user-1',
            'primary_email' => 'synced@example.com',
            'display_name' => 'Synced User',
            'status' => 'active',
            'locale' => 'en',
        ]);
        DB::table('sso_sessions')->insert([
            'id' => str_repeat('a', 64),
            'user_id' => 'sso-user-1',
            'expires_at' => time() + 3600,
        ]);

        $request = Request::create('/api/auth/me', 'GET');
        $request->cookies->set('AUTHSESSID', str_repeat('a', 64));

        $subject = (new GrandpaSSOnIdentityProvider())->resolveIdentity($request);

        $this->assertNotNull($subject);
        $this->assertSame('en', $subject->locale);
        $this->assertSame('en', User::where('email', 'synced@example.com')->first()->locale);
    }

    public function test_locale_updates_on_every_login_not_just_the_first(): void
    {
        DB::table('sso_users')->insert([
            'id' => 'sso-user-2',
            'primary_email' => 'resynced@example.com',
            'display_name' => 'Resynced User',
            'status' => 'active',
            'locale' => 'pt-BR',
        ]);
        DB::table('sso_sessions')->insert([
            'id' => str_repeat('b', 64),
            'user_id' => 'sso-user-2',
            'expires_at' => time() + 3600,
        ]);

        $request = Request::create('/api/auth/me', 'GET');
        $request->cookies->set('AUTHSESSID', str_repeat('b', 64));
        (new GrandpaSSOnIdentityProvider())->resolveIdentity($request);
        $this->assertSame('pt-BR', User::where('email', 'resynced@example.com')->first()->locale);

        DB::table('sso_users')->where('id', 'sso-user-2')->update(['locale' => 'en']);
        (new GrandpaSSOnIdentityProvider())->resolveIdentity($request);

        $this->assertSame('en', User::where('email', 'resynced@example.com')->first()->locale);
    }
}
