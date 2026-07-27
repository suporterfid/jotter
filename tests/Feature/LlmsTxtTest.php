<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LlmsTxtTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_llms_txt_returns_plain_text(): void
    {
        $response = $this->get('/llms.txt');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8');

        $this->assertStringContainsString('Jotter Knowledge Base', $response->getContent());
    }

    public function test_workspace_llms_txt_returns_workspace_notes_index(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/llms_test'),
        ]);

        $response = $this->actingAs($admin)
            ->get("/api/workspaces/{$workspace->id}/llms.txt");

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8');

        $this->assertStringContainsString('Vault: Main', $response->getContent());
    }
}
