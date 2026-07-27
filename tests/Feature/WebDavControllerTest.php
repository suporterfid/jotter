<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WebDavControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_webdav_propfind_returns_multistatus_xml(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $vaultPath = storage_path('app/vaults/webdav_test');
        @mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => $vaultPath,
        ]);

        $response = $this->withoutExceptionHandling()
            ->actingAs($admin)
            ->call('PROPFIND', "/api/webdav/{$workspace->id}/welcome.md");

        $response->assertStatus(207);
        $this->assertStringContainsString('multistatus', $response->getContent());
    }
}
