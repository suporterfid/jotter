<?php

namespace Tests\Feature;

use App\Domain\Vault\VaultStorage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Issue #410: Obsidian (WebDAV) gets the same overwrite protection as the web
 * editor (base_sha256) and the MCP tools (expected_sha256).
 */
final class WebDavConditionalWriteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Workspace $workspace;
    private string $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::create(['slug' => 'default', 'name' => 'Default']);
        $this->vault = storage_path('app/vaults/webdav_etag_'.uniqid());
        File::ensureDirectoryExists($this->vault);
        $this->workspace = Workspace::create(['tenant_id' => $tenant->id, 'slug' => 'main', 'name' => 'Main', 'vault_path' => $this->vault]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vault);
        parent::tearDown();
    }

    public function test_get_and_propfind_expose_the_sha256_of_the_file_as_etag(): void
    {
        $this->davPut('diario/2026-11-06.md', "# Diário\n")->assertStatus(201);
        $expected = '"'.hash('sha256', "# Diário\n").'"';

        $this->dav('GET', 'diario/2026-11-06.md')->assertOk()->assertHeader('ETag', $expected);
        $propfind = $this->dav('PROPFIND', 'diario/2026-11-06.md')->assertStatus(207);
        $this->assertStringContainsString('<d:getetag>'.htmlspecialchars($expected).'</d:getetag>', (string) $propfind->getContent());

        $this->dav('GET', 'diario/2026-11-06.md', ['HTTP_IF_NONE_MATCH' => $expected])->assertStatus(304);
    }

    public function test_a_stale_if_match_after_an_agent_append_is_refused_and_the_file_is_kept(): void
    {
        $etag = (string) $this->davPut('diario/2026-11-06.md', "# Diário\n")->headers->get('ETag');

        // An agent appends meanwhile (MCP append_to_note writes through VaultStorage).
        app(VaultStorage::class)->write($this->workspace, 'diario/2026-11-06.md', "# Diário\n\n## Hermes\nfeito\n", 'hermes');

        $this->davPut('diario/2026-11-06.md', "# Diário\n\nObsidian\n", ['HTTP_IF_MATCH' => $etag])->assertStatus(412)
            ->assertHeader('ETag', '"'.hash('sha256', "# Diário\n\n## Hermes\nfeito\n").'"');
        $this->assertSame("# Diário\n\n## Hermes\nfeito\n", file_get_contents($this->vault.'/diario/2026-11-06.md'));

        $current = (string) $this->dav('GET', 'diario/2026-11-06.md')->headers->get('ETag');
        $this->davPut('diario/2026-11-06.md', "# Diário\n\n## Hermes\nfeito\n\nObsidian\n", ['HTTP_IF_MATCH' => $current])
            ->assertStatus(201)
            ->assertHeader('ETag', '"'.hash('sha256', "# Diário\n\n## Hermes\nfeito\n\nObsidian\n").'"');
        $this->assertStringEndsWith("Obsidian\n", (string) file_get_contents($this->vault.'/diario/2026-11-06.md'));
    }

    public function test_if_match_on_a_missing_file_and_weak_tags_fail(): void
    {
        $this->davPut('novo.md', "x\n", ['HTTP_IF_MATCH' => '*'])->assertStatus(412);
        $this->assertFileDoesNotExist($this->vault.'/novo.md');

        $this->davPut('novo.md', "x\n")->assertStatus(201);
        $weak = 'W/"'.hash('sha256', "x\n").'"';
        $this->davPut('novo.md', "y\n", ['HTTP_IF_MATCH' => $weak])->assertStatus(412);
        $this->davPut('novo.md', "y\n", ['HTTP_IF_MATCH' => '"other", "'.hash('sha256', "x\n").'"'])->assertStatus(201);
    }

    public function test_if_none_match_star_creates_only(): void
    {
        $this->davPut('inbox/a.md', "a\n", ['HTTP_IF_NONE_MATCH' => '*'])->assertStatus(201);
        $this->davPut('inbox/a.md', "b\n", ['HTTP_IF_NONE_MATCH' => '*'])->assertStatus(412);
        $this->assertSame("a\n", file_get_contents($this->vault.'/inbox/a.md'));
    }

    public function test_an_unconditional_put_still_overwrites(): void
    {
        $this->davPut('a.md', "a\n")->assertStatus(201);
        $this->davPut('a.md', "b\n")->assertStatus(201);
        $this->assertSame("b\n", file_get_contents($this->vault.'/a.md'));
    }

    public function test_delete_honors_if_match(): void
    {
        $this->davPut('a.md', "a\n")->assertStatus(201);
        $this->dav('DELETE', 'a.md', ['HTTP_IF_MATCH' => '"'.str_repeat('0', 64).'"'])->assertStatus(412);
        $this->assertFileExists($this->vault.'/a.md');

        $this->dav('DELETE', 'a.md', ['HTTP_IF_MATCH' => '"'.hash('sha256', "a\n").'"'])->assertStatus(204);
    }

    public function test_a_conditional_put_waits_for_the_note_lock(): void
    {
        $this->davPut('a.md', "a\n")->assertStatus(201);
        $lock = Cache::lock('note:'.$this->workspace->id.':'.sha1('a.md'), 30);
        $this->assertTrue($lock->get());

        try {
            $this->davPut('a.md', "b\n", ['HTTP_IF_MATCH' => '"'.hash('sha256', "a\n").'"'])->assertStatus(423);
            $this->assertSame("a\n", file_get_contents($this->vault.'/a.md'));
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, string> $server
     */
    private function davPut(string $path, string $content, array $server = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->call('PUT', "/api/webdav/{$this->workspace->id}/{$path}", [], [], [], $server, $content);
    }

    /**
     * @param array<string, string> $server
     */
    private function dav(string $method, string $path, array $server = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->call($method, "/api/webdav/{$this->workspace->id}/{$path}", [], [], [], $server);
    }
}
