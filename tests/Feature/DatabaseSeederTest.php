<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_idempotently_seeds_exactly_one_configured_tenant_and_workspace(): void
    {
        config()->set('jotter.seed.tenant_name', 'Configured Tenant');
        config()->set('jotter.seed.tenant_slug', 'configured-tenant');
        config()->set('jotter.seed.workspace_name', 'Configured Workspace');
        config()->set('jotter.seed.workspace_slug', 'configured-workspace');
        config()->set('jotter.seed.vault_path', '/srv/jotter/configured-vault');

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $tenant = Tenant::sole();
        $workspace = Workspace::sole();

        $this->assertSame('configured-tenant', $tenant->slug);
        $this->assertSame('Configured Tenant', $tenant->name);
        $this->assertSame($tenant->id, $workspace->tenant_id);
        $this->assertSame('configured-workspace', $workspace->slug);
        $this->assertSame('Configured Workspace', $workspace->name);
        $this->assertSame('/srv/jotter/configured-vault', $workspace->vault_path);
        $this->assertSame(0, User::count());
    }
}
