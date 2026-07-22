<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => config('jotter.seed.tenant_slug')],
            ['name' => config('jotter.seed.tenant_name')],
        );

        Workspace::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'slug' => config('jotter.seed.workspace_slug'),
            ],
            [
                'name' => config('jotter.seed.workspace_name'),
                'vault_path' => config('jotter.seed.vault_path'),
            ],
        );
    }
}
