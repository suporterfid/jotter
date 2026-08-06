<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserLocaleColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_defaults_to_pt_br(): void
    {
        $user = User::create([
            'name' => 'Locale Test',
            'email' => 'locale-test@example.com',
            'password' => bcrypt('irrelevant'),
        ]);

        $this->assertSame('pt-BR', $user->fresh()->locale);
    }

    public function test_locale_is_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'Locale Test 2',
            'email' => 'locale-test-2@example.com',
            'password' => bcrypt('irrelevant'),
            'locale' => 'en',
        ]);

        $this->assertSame('en', $user->fresh()->locale);
    }
}
