<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

final class SetLocaleFromSubjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_requests_use_the_subjects_locale(): void
    {
        // config('app.locale') defaults to 'en' — use pt-BR here so the
        // assertion actually proves the middleware ran, instead of trivially
        // matching whatever the app's default locale already is.
        $this->assertNotSame('pt-BR', config('app.locale'));

        $user = User::create([
            'name' => 'PT User',
            'email' => 'pt-locale@example.com',
            'password' => bcrypt('irrelevant'),
            'locale' => 'pt-BR',
        ]);

        $this->actingAs($user)
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->assertSame('pt-BR', App::getLocale());
    }

    public function test_unauthenticated_requests_keep_the_default_locale(): void
    {
        $default = config('app.locale');

        $this->getJson('/api/auth/me')->assertStatus(401);

        $this->assertSame($default, App::getLocale());
    }
}
