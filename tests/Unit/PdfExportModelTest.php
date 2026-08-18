<?php

namespace Tests\Unit;

use App\Models\PdfExport;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PdfExportModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_export_defaults_to_queued_and_casts_note_ids(): void
    {
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'pdf-model',
            'name' => 'PDF Model',
            'vault_path' => storage_path('app/vaults/pdf-model'),
        ]);

        $export = PdfExport::create([
            'workspace_id' => $workspace->id,
            'scope' => 'workspace',
            'requested_by_subject' => 'user:1',
            'note_ids' => [3, 5],
        ]);

        $this->assertSame('queued', $export->status);
        $this->assertSame([3, 5], $export->note_ids);
        $this->assertTrue($export->workspace->is($workspace));
        $this->assertArrayNotHasKey('output_path', $export->toArray());
    }

    public function test_pdf_configuration_exposes_private_storage_and_bounds(): void
    {
        config([
            'jotter.pdf.storage_path' => '/private/pdf',
            'jotter.pdf.retention_hours' => 48,
            'jotter.pdf.process_batch_size' => 7,
        ]);

        $this->assertSame('/private/pdf', config('jotter.pdf.storage_path'));
        $this->assertSame(48, config('jotter.pdf.retention_hours'));
        $this->assertSame(7, config('jotter.pdf.process_batch_size'));
    }
}
