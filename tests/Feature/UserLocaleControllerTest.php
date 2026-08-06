<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class UserLocaleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_user_locale_updates_directly_with_no_grandpasson_call(): void
    {
        $user = User::create([
            'name' => 'Local Locale User',
            'email' => 'local-locale@example.com',
            'password' => bcrypt('irrelevant'),
            'locale' => 'pt-BR',
        ]);

        Http::fake();

        $this->actingAs($user)
            ->postJson('/api/user/locale', ['locale' => 'en'])
            ->assertOk()
            ->assertJson(['ok' => true, 'locale' => 'en']);

        Http::assertNothingSent();
        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_rejects_an_unsupported_locale(): void
    {
        $user = User::create([
            'name' => 'Local Locale User 2',
            'email' => 'local-locale-2@example.com',
            'password' => bcrypt('irrelevant'),
        ]);

        $this->actingAs($user)
            ->postJson('/api/user/locale', ['locale' => 'es'])
            ->assertStatus(400);

        $this->assertSame('pt-BR', $user->fresh()->locale);
    }

    public function test_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/api/user/locale', ['locale' => 'en'])
            ->assertStatus(401);
    }

    /**
     * These two SSO tests deliberately do NOT go through postJson()/withCookie().
     * EncryptCookies::decrypt() nulls out any cookie it can't decrypt (see
     * vendor/laravel/framework/src/Illuminate/Cookie/Middleware/EncryptCookies.php),
     * and AUTHSESSID is an unencrypted cookie set by GrandpaSSOn (a different app),
     * not by jotter — a real production request survives this via the raw $_COOKIE
     * superglobal fallback already present in GrandpaSSOnIdentityProvider, but
     * Laravel's in-process test client never populates $_COOKIE (there's no real
     * HTTP round-trip), so a full postJson() call would always see a null cookie
     * here. Instead, build the Request directly and call the controller in-process,
     * exactly like GrandpaSSOnSessionLocaleSyncTest already does.
     */
    public function test_sso_user_proxies_through_grandpasson_and_updates_local_cache(): void
    {
        config(['jotter.auth_provider' => 'grandpasson', 'jotter.sso.db_prefix' => 'sso_', 'jotter.sso.broker_base_url' => 'https://hub.example.test/sso']);

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
        DB::table('sso_users')->insert([
            'id' => 'sso-user-3', 'primary_email' => 'sso-locale@example.com',
            'display_name' => 'SSO Locale User', 'status' => 'active', 'locale' => 'pt-BR',
        ]);
        DB::table('sso_sessions')->insert([
            'id' => str_repeat('c', 64), 'user_id' => 'sso-user-3', 'expires_at' => time() + 3600,
        ]);

        Http::fake([
            'hub.example.test/sso/me/locale' => Http::sequence()
                ->push(['locale' => 'pt-BR', 'csrf' => 'test-csrf-token'], 200)
                ->push(['ok' => true, 'locale' => 'en'], 200),
        ]);

        $request = \Illuminate\Http\Request::create('/api/user/locale', 'POST', ['locale' => 'en']);
        $request->cookies->set('AUTHSESSID', str_repeat('c', 64));

        $controller = new \App\Http\Controllers\UserLocaleController(new \App\Domain\Auth\Providers\GrandpaSSOnIdentityProvider());
        $response = $controller->update($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true, 'locale' => 'en'], $response->getData(true));

        Http::assertSentCount(2);
        $this->assertSame('en', User::where('email', 'sso-locale@example.com')->first()->locale);

        DB::statement('DROP TABLE IF EXISTS sso_sessions');
        DB::statement('DROP TABLE IF EXISTS sso_users');
    }

    public function test_sso_proxy_failure_does_not_touch_local_cache(): void
    {
        config(['jotter.auth_provider' => 'grandpasson', 'jotter.sso.db_prefix' => 'sso_', 'jotter.sso.broker_base_url' => 'https://hub.example.test/sso']);

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
        DB::table('sso_users')->insert([
            'id' => 'sso-user-4', 'primary_email' => 'sso-locale-fail@example.com',
            'display_name' => 'SSO Locale Fail User', 'status' => 'active', 'locale' => 'pt-BR',
        ]);
        DB::table('sso_sessions')->insert([
            'id' => str_repeat('d', 64), 'user_id' => 'sso-user-4', 'expires_at' => time() + 3600,
        ]);

        Http::fake([
            'hub.example.test/sso/me/locale' => Http::response(['error' => 'server_error'], 500),
        ]);

        $request = \Illuminate\Http\Request::create('/api/user/locale', 'POST', ['locale' => 'en']);
        $request->cookies->set('AUTHSESSID', str_repeat('d', 64));

        $controller = new \App\Http\Controllers\UserLocaleController(new \App\Domain\Auth\Providers\GrandpaSSOnIdentityProvider());
        $response = $controller->update($request);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('pt-BR', User::where('email', 'sso-locale-fail@example.com')->first()->locale);

        DB::statement('DROP TABLE IF EXISTS sso_sessions');
        DB::statement('DROP TABLE IF EXISTS sso_users');
    }
}
