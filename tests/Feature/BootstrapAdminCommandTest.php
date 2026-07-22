<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_admin_with_a_hashed_password(): void
    {
        $password = 'correct horse battery staple';

        $this->artisan('platform:bootstrap-admin', [
            'email' => 'admin@example.test',
            'password' => $password,
        ])->assertSuccessful();

        $user = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this->assertTrue($user->is_admin);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNotSame($password, $user->password);
    }

    public function test_it_does_not_print_the_plaintext_password(): void
    {
        $password = 'never-print-this-password';

        $exitCode = Artisan::call('platform:bootstrap-admin', [
            'email' => 'quiet@example.test',
            'password' => $password,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString($password, Artisan::output());
    }

    public function test_it_rejects_an_existing_email_without_changing_its_password(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.test']);
        $originalHash = $user->password;

        $this->artisan('platform:bootstrap-admin', [
            'email' => $user->email,
            'password' => 'replacement-password',
        ])->assertFailed();

        $this->assertSame($originalHash, $user->fresh()->password);
    }
}
