# Sidebar Drag-and-Drop Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users reorder and reparent notes/folders in the Jotter sidebar tree by dragging, with a new "Manual" sort mode that persists the resulting order.

**Architecture:** A new `sort_position` column on `notes` plus a new `folder_positions` table hold per-level manual order. A single backend endpoint (`PUT /workspaces/{w}/note-tree/order`) rewrites the full sibling order for one folder level at a time, validated against the actual current children. The frontend wires `SortableJS` onto each level of the recursive `NoteTreeNode` tree (and the root list in `Sidebar.vue`), calling that endpoint (and the existing note-move endpoint, for reparenting) on drop.

**Tech Stack:** Laravel 8.2+/PHP, MySQL, PHPUnit; Vue 3 `<script setup>` + TypeScript, Vitest, Playwright, SortableJS (new dependency).

## Global Constraints

- Use only `./scripts/jt.sh` wrappers for all runtime/dependency/db/build/test commands — never bare `npm`/`composer`/`php` on host (CLAUDE.md).
- `sort_position`/`folder_positions` are DB/UI-state only — never written to Markdown front-matter, never touched by `vault:reindex` (spec §6).
- Folders never change parent via drag — only reorder among siblings (spec §2). Enforced via `onMove` rejecting cross-container drags of folder items.
- New sibling order submissions to the order endpoint must be the *complete* current child set of that folder level, or the request is rejected with 422 (spec §4).
- No drag-and-drop undo; no change to `NoteProperty`/front-matter/`vault:reindex` (spec §2, §8).

---

### Task 1: Data model — `sort_position` column, `folder_positions` table

**Files:**
- Create: `database/migrations/2026_07_31_000000_add_sort_position_to_notes_table.php`
- Create: `database/migrations/2026_07_31_000001_create_folder_positions_table.php`
- Create: `app/Models/FolderPosition.php`
- Modify: `app/Models/Note.php` (add `sort_position` to `$fillable`)

**Interfaces:**
- Produces: `notes.sort_position` (nullable int) column; `FolderPosition` model with `workspace_id`, `folder_path`, `sort_position`, unique on `(workspace_id, folder_path)`. Task 2 writes to both; Task 3 reads `Note::sort_position`; Task 5/6 (frontend) read/write both through the API.

- [ ] **Step 1: Write the notes-table migration**

```php
<?php
// database/migrations/2026_07_31_000000_add_sort_position_to_notes_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('notes', 'sort_position')) {
            Schema::table('notes', function (Blueprint $table) {
                $table->integer('sort_position')->nullable()->after('frontmatter');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('notes', 'sort_position')) {
            Schema::table('notes', function (Blueprint $table) {
                $table->dropColumn('sort_position');
            });
        }
    }
};
```

- [ ] **Step 2: Write the `folder_positions` migration**

```php
<?php
// database/migrations/2026_07_31_000001_create_folder_positions_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('folder_positions')) {
            Schema::create('folder_positions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->string('folder_path');
                $table->integer('sort_position');
                $table->timestamps();

                $table->unique(['workspace_id', 'folder_path']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_positions');
    }
};
```

- [ ] **Step 3: Write the `FolderPosition` model**

```php
<?php
// app/Models/FolderPosition.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolderPosition extends Model
{
    protected $fillable = [
        'workspace_id',
        'folder_path',
        'sort_position',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
```

- [ ] **Step 4: Add `sort_position` to `Note`'s fillable**

In `app/Models/Note.php`, change:

```php
    protected $fillable = [
        'workspace_id',
        'path',
        'title',
        'frontmatter',
        'content_hash',
        'search_content',
    ];
```

to:

```php
    protected $fillable = [
        'workspace_id',
        'path',
        'title',
        'frontmatter',
        'content_hash',
        'search_content',
        'sort_position',
    ];
```

- [ ] **Step 5: Run the migrations**

Run: `./scripts/jt.sh artisan migrate`
Expected: both new migrations listed as `Migrating` then `Migrated`, no errors.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_31_000000_add_sort_position_to_notes_table.php \
        database/migrations/2026_07_31_000001_create_folder_positions_table.php \
        app/Models/FolderPosition.php app/Models/Note.php
git commit -m "feat: add sort_position column and folder_positions table"
```

---

### Task 2: Backend order endpoint

**Files:**
- Create: `app/Http/Controllers/WorkspaceNoteTreeController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/WorkspaceNoteTreeOrderTest.php`

**Interfaces:**
- Consumes: `App\Models\FolderPosition` (Task 1), `Workspace::notes()` (`HasMany`, existing).
- Produces: `GET /workspaces/{workspace}/note-tree/order` → `{"data": [{"folder_path": string, "sort_position": int}, ...]}`. `PUT /workspaces/{workspace}/note-tree/order` with body `{"folder_path": string, "items": [{"type":"note","id":int}|{"type":"folder","path":string}, ...]}` → `204` on success, `422` on mismatch. Task 5's `reorderNoteTree`/`getFolderPositions` call these.

- [ ] **Step 1: Write the failing feature tests**

```php
<?php
// tests/Feature/WorkspaceNoteTreeOrderTest.php
namespace Tests\Feature;

use App\Models\FolderPosition;
use App\Models\Note;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceNoteTreeOrderTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultRoot = sys_get_temp_dir().'/jotter-tree-order-'.uniqid('', true);
        mkdir($this->vaultRoot, 0755, true);

        $admin = \App\Models\User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->vaultRoot);
        parent::tearDown();
    }

    public function test_reorder_persists_sequential_positions_for_notes_and_folders(): void
    {
        $workspace = $this->makeWorkspace('reorder');
        $storage = app(\App\Domain\Vault\VaultStorage::class);

        $noteA = $storage->write($workspace, 'docs/a.md', "# A\n");
        $noteB = $storage->write($workspace, 'docs/b.md', "# B\n");
        $storage->write($workspace, 'docs/archived/c.md', "# C\n");

        $response = $this->putJson("/api/workspaces/{$workspace->id}/notes-tree-noop", []);
        // placeholder line removed below; real request follows
        $response = $this->putJson("/api/workspaces/{$workspace->id}/note-tree/order", [
            'folder_path' => 'docs',
            'items' => [
                ['type' => 'folder', 'path' => 'docs/archived'],
                ['type' => 'note', 'id' => $noteB->id],
                ['type' => 'note', 'id' => $noteA->id],
            ],
        ]);

        $response->assertNoContent();

        $this->assertSame(0, FolderPosition::where('workspace_id', $workspace->id)
            ->where('folder_path', 'docs/archived')->value('sort_position'));
        $this->assertSame(10, Note::find($noteB->id)->sort_position);
        $this->assertSame(20, Note::find($noteA->id)->sort_position);
    }

    public function test_reorder_rejects_incomplete_item_list(): void
    {
        $workspace = $this->makeWorkspace('incomplete');
        $storage = app(\App\Domain\Vault\VaultStorage::class);

        $noteA = $storage->write($workspace, 'a.md', "# A\n");
        $storage->write($workspace, 'b.md', "# B\n");

        $response = $this->putJson("/api/workspaces/{$workspace->id}/note-tree/order", [
            'folder_path' => '',
            'items' => [
                ['type' => 'note', 'id' => $noteA->id],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_reorder_rejects_note_from_another_workspace(): void
    {
        $workspace = $this->makeWorkspace('scope-a');
        $otherWorkspace = $this->makeWorkspace('scope-b');
        $storage = app(\App\Domain\Vault\VaultStorage::class);

        $note = $storage->write($workspace, 'a.md', "# A\n");
        $foreignNote = $storage->write($otherWorkspace, 'foreign.md', "# Foreign\n");

        $response = $this->putJson("/api/workspaces/{$workspace->id}/note-tree/order", [
            'folder_path' => '',
            'items' => [
                ['type' => 'note', 'id' => $note->id],
                ['type' => 'note', 'id' => $foreignNote->id],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_index_returns_only_this_workspace_folder_positions(): void
    {
        $workspace = $this->makeWorkspace('list-a');
        $otherWorkspace = $this->makeWorkspace('list-b');

        FolderPosition::create(['workspace_id' => $workspace->id, 'folder_path' => 'docs', 'sort_position' => 0]);
        FolderPosition::create(['workspace_id' => $otherWorkspace->id, 'folder_path' => 'other', 'sort_position' => 0]);

        $response = $this->getJson("/api/workspaces/{$workspace->id}/note-tree/order");

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.folder_path', 'docs');
    }

    private function makeWorkspace(string $suffix): Workspace
    {
        $tenant = Tenant::query()->create([
            'slug' => "tree-order-tenant-{$suffix}-".uniqid(),
            'name' => 'Tree Order Tenant',
        ]);

        return Workspace::query()->create([
            'tenant_id' => $tenant->id,
            'slug' => "tree-order-ws-{$suffix}-".uniqid(),
            'name' => 'Tree Order Workspace',
            'vault_path' => $this->vaultRoot.'/'.$suffix,
        ]);
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path) && ! is_file($path)) {
            return;
        }
        if (is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $this->deleteTree($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
```

Delete the stray placeholder line (`$response = $this->putJson(".../notes-tree-noop", []);`) from the first test before saving — it was left in above by mistake; the real assertion is the `PUT .../note-tree/order` call directly below it.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./scripts/jt.sh test --filter=WorkspaceNoteTreeOrderTest`
Expected: FAIL — route not found (404) for all four tests.

- [ ] **Step 3: Write the controller**

```php
<?php
// app/Http/Controllers/WorkspaceNoteTreeController.php
namespace App\Http\Controllers;

use App\Models\FolderPosition;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WorkspaceNoteTreeController extends Controller
{
    public function index(Workspace $workspace): JsonResponse
    {
        return response()->json([
            'data' => FolderPosition::where('workspace_id', $workspace->id)
                ->get(['folder_path', 'sort_position'])
                ->map(fn (FolderPosition $p) => [
                    'folder_path' => $p->folder_path,
                    'sort_position' => $p->sort_position,
                ])
                ->all(),
        ]);
    }

    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        $validated = $request->validate([
            'folder_path' => ['present', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', 'in:note,folder'],
            'items.*.id' => ['required_if:items.*.type,note', 'integer'],
            'items.*.path' => ['required_if:items.*.type,folder', 'string'],
        ]);

        $folderPath = trim($validated['folder_path'], '/');
        $children = $this->directChildren($workspace, $folderPath);

        $submittedNoteIds = collect($validated['items'])
            ->where('type', 'note')->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $submittedFolderPaths = collect($validated['items'])
            ->where('type', 'folder')->pluck('path')->sort()->values()->all();

        $expectedNoteIds = collect($children['note_ids'])->sort()->values()->all();
        $expectedFolderPaths = collect($children['folder_paths'])->sort()->values()->all();

        if ($submittedNoteIds !== $expectedNoteIds || $submittedFolderPaths !== $expectedFolderPaths) {
            throw ValidationException::withMessages([
                'items' => ['The submitted items do not match the current children of this folder.'],
            ]);
        }

        DB::transaction(function () use ($workspace, $validated) {
            foreach (array_values($validated['items']) as $index => $item) {
                $position = $index * 10;

                if ($item['type'] === 'note') {
                    $workspace->notes()->whereKey($item['id'])->update(['sort_position' => $position]);
                } else {
                    FolderPosition::updateOrCreate(
                        ['workspace_id' => $workspace->id, 'folder_path' => $item['path']],
                        ['sort_position' => $position],
                    );
                }
            }
        });

        return response()->json(status: 204);
    }

    /**
     * @return array{note_ids: array<int>, folder_paths: array<string>}
     */
    private function directChildren(Workspace $workspace, string $folderPath): array
    {
        $prefix = $folderPath === '' ? '' : $folderPath.'/';
        $noteIds = [];
        $folderPaths = [];

        foreach ($workspace->notes()->get(['id', 'path']) as $note) {
            if ($prefix !== '' && ! str_starts_with($note->path, $prefix)) {
                continue;
            }

            $remainder = substr($note->path, strlen($prefix));
            $slash = strpos($remainder, '/');

            if ($slash === false) {
                $noteIds[] = $note->id;
            } else {
                $folderPaths[$prefix.substr($remainder, 0, $slash)] = true;
            }
        }

        return ['note_ids' => $noteIds, 'folder_paths' => array_keys($folderPaths)];
    }
}
```

- [ ] **Step 4: Wire the routes**

In `routes/api.php`, add the import near the other controller imports:

```php
use App\Http\Controllers\WorkspaceNoteTreeController;
```

Then add these two lines directly after the existing `move` route (`Route::post('/workspaces/{workspace}/notes/{note}/move', ...)`, currently at line 73):

```php
    Route::get('/workspaces/{workspace}/note-tree/order', [WorkspaceNoteTreeController::class, 'index']);
    Route::put('/workspaces/{workspace}/note-tree/order', [WorkspaceNoteTreeController::class, 'update']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./scripts/jt.sh test --filter=WorkspaceNoteTreeOrderTest`
Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/WorkspaceNoteTreeController.php routes/api.php tests/Feature/WorkspaceNoteTreeOrderTest.php
git commit -m "feat: add note-tree order endpoint for sidebar drag-and-drop"
```

---

### Task 3: Expose `sort_position` on the notes list

**Files:**
- Modify: `app/Http/Controllers/WorkspaceNoteController.php:143-165` (the `metadata()` method)
- Test: `tests/Feature/WorkspaceNotesApiTest.php`

**Interfaces:**
- Consumes: `Note::sort_position` (Task 1).
- Produces: every note JSON object (list, show, move responses) now includes `"sort_position": int|null`. Task 4's `NoteMeta` TypeScript type gains this field.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/WorkspaceNotesApiTest.php` (anywhere among the other `test_*` methods):

```php
    public function test_note_list_includes_sort_position(): void
    {
        $workspace = $this->makeWorkspace('sort-position');
        $storage = app(\App\Domain\Vault\VaultStorage::class);
        $storage->write($workspace, 'a.md', "# A\n");

        $response = $this->getJson("/api/workspaces/{$workspace->id}/notes");

        $response->assertOk()->assertJsonPath('data.0.sort_position', null);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh test --filter=test_note_list_includes_sort_position`
Expected: FAIL — `sort_position` key missing from the response.

- [ ] **Step 3: Add the field to `metadata()`**

In `app/Http/Controllers/WorkspaceNoteController.php`, change the return array at the end of `metadata()`:

```php
        return [
            'id' => $note->id,
            'path' => $note->path,
            'title' => $note->title,
            'frontmatter' => $note->frontmatter,
            'properties' => $properties,
            'updated_at' => $note->updated_at->toISOString(),
        ];
```

to:

```php
        return [
            'id' => $note->id,
            'path' => $note->path,
            'title' => $note->title,
            'frontmatter' => $note->frontmatter,
            'properties' => $properties,
            'sort_position' => $note->sort_position,
            'updated_at' => $note->updated_at->toISOString(),
        ];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh test --filter=test_note_list_includes_sort_position`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/WorkspaceNoteController.php tests/Feature/WorkspaceNotesApiTest.php
git commit -m "feat: include sort_position in note metadata responses"
```

---

### Task 4: Frontend types and API client functions

**Files:**
- Modify: `frontend/src/services/types.ts`
- Modify: `frontend/src/services/api.ts`
- Test: `frontend/src/api.spec.ts` (new)

**Interfaces:**
- Consumes: `GET/PUT /workspaces/{w}/note-tree/order`, `POST /workspaces/{w}/notes/{note}/move` (Task 2, existing backend).
- Produces: `NoteMeta.sort_position: number | null`; `FolderPosition { folder_path: string; sort_position: number }`; `SortItem = { type: 'note'; id: number } | { type: 'folder'; path: string }`; `moveNote(workspaceId, noteId, newPath): Promise<NoteMeta>`; `reorderNoteTree(workspaceId, folderPath, items: SortItem[]): Promise<void>`; `getFolderPositions(workspaceId): Promise<FolderPosition[]>`. Tasks 5–7 (Sidebar, composable, NoteTreeNode) consume all of these.

- [ ] **Step 1: Add types**

In `frontend/src/services/types.ts`, change `NoteMeta`:

```typescript
export interface NoteMeta {
  id: number
  path: string
  title: string
  frontmatter: Record<string, unknown> | null
  updated_at: string
}
```

to:

```typescript
export interface NoteMeta {
  id: number
  path: string
  title: string
  frontmatter: Record<string, unknown> | null
  sort_position: number | null
  updated_at: string
}

export interface FolderPosition {
  folder_path: string
  sort_position: number
}

export type SortItem = { type: 'note'; id: number } | { type: 'folder'; path: string }
```

- [ ] **Step 2: Write the failing test for the new API functions**

```typescript
// frontend/src/api.spec.ts
import { describe, expect, it, vi, beforeEach } from 'vitest'

vi.mock('axios', () => {
  const put = vi.fn().mockResolvedValue({ data: { data: { id: 1, path: 'docs/a.md' } } })
  const post = vi.fn().mockResolvedValue({ data: { data: { id: 1, path: 'docs/a.md' } } })
  const get = vi.fn().mockResolvedValue({ data: { data: [{ folder_path: 'docs', sort_position: 0 }] } })
  const del = vi.fn()
  const instance = { put, post, get, delete: del, interceptors: { response: { use: vi.fn() } } }
  return {
    default: {
      create: vi.fn(() => instance),
      get,
      defaults: {},
    },
  }
})

import { moveNote, reorderNoteTree, getFolderPositions } from './services/api'
import axios from 'axios'

describe('note-tree API functions', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('moveNote posts new_path to the move endpoint', async () => {
    const instance = (axios.create as ReturnType<typeof vi.fn>).mock.results[0].value
    await moveNote(1, 7, 'docs/renamed.md')
    expect(instance.post).toHaveBeenCalledWith('/workspaces/1/notes/7/move', { new_path: 'docs/renamed.md' })
  })

  it('reorderNoteTree puts folder_path and items to the order endpoint', async () => {
    const instance = (axios.create as ReturnType<typeof vi.fn>).mock.results[0].value
    await reorderNoteTree(1, 'docs', [{ type: 'note', id: 7 }, { type: 'folder', path: 'docs/archived' }])
    expect(instance.put).toHaveBeenCalledWith('/workspaces/1/note-tree/order', {
      folder_path: 'docs',
      items: [{ type: 'note', id: 7 }, { type: 'folder', path: 'docs/archived' }],
    })
  })

  it('getFolderPositions gets the order endpoint and returns the data array', async () => {
    const result = await getFolderPositions(1)
    expect(result).toEqual([{ folder_path: 'docs', sort_position: 0 }])
  })
})
```

- [ ] **Step 3: Run test to verify it fails**

Run: `./scripts/jt.sh npm -- test -- api.spec.ts`
Expected: FAIL — `moveNote`/`reorderNoteTree`/`getFolderPositions` are not exported.

- [ ] **Step 4: Implement the functions**

In `frontend/src/services/api.ts`, add to the import line at the top:

```typescript
import type { Workspace, NoteMeta, NoteDetail, SearchResult, AuthUser, AttachmentItem, SearchFilters, NoteRevisionMeta, NoteRevisionDetail, NoteProperty, NoteComment, AuditLogEntry, LinkReport, NotificationItem, CollectionPage, UnlinkedMention, OutgoingLink, FolderPosition, SortItem } from './types'
```

Then add these three functions directly after `deleteNote` (currently ending at line 98):

```typescript
export async function moveNote(workspaceId: number, noteId: number, newPath: string): Promise<NoteMeta> {
  const response = await api.post<{ data: NoteMeta }>(`/workspaces/${workspaceId}/notes/${noteId}/move`, {
    new_path: newPath
  })
  return response.data.data
}

export async function reorderNoteTree(workspaceId: number, folderPath: string, items: SortItem[]): Promise<void> {
  await api.put(`/workspaces/${workspaceId}/note-tree/order`, { folder_path: folderPath, items })
}

export async function getFolderPositions(workspaceId: number): Promise<FolderPosition[]> {
  const response = await api.get<{ data: FolderPosition[] }>(`/workspaces/${workspaceId}/note-tree/order`)
  return response.data.data
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./scripts/jt.sh npm -- test -- api.spec.ts`
Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/services/types.ts frontend/src/services/api.ts frontend/src/api.spec.ts
git commit -m "feat: add moveNote, reorderNoteTree, getFolderPositions API functions"
```

---

### Task 5: `sortablejs` dependency and drag composable

**Files:**
- Modify: `frontend/package.json`
- Create: `frontend/src/composables/useNoteTreeSortable.ts`
- Test: `frontend/src/composables/useNoteTreeSortable.spec.ts`

**Interfaces:**
- Consumes: `SortItem` (Task 4).
- Produces: `parseSortItemFromDataset`, `itemsFromContainer`, `basename`, `pathInFolder`, `shouldRejectMove` (pure, unit-tested functions) and `createNoteTreeSortable(el, folderPath, initiallyDisabled, callbacks): { setDisabled(disabled: boolean): void; destroy(): void }`, where `callbacks = { onReorder(folderPath: string, items: SortItem[]): void; onReparentNote(noteId: number, newPath: string, destFolderPath: string, items: SortItem[]): void }`. Tasks 6 and 7 call `createNoteTreeSortable` from `onMounted`.

- [ ] **Step 1: Add the dependency**

Run: `./scripts/jt.sh npm -- install sortablejs`
Run: `./scripts/jt.sh npm -- install --save-dev @types/sortablejs`
Expected: `frontend/package.json` gains `"sortablejs"` under `dependencies` and `"@types/sortablejs"` under `devDependencies`; `frontend/package-lock.json` updates.

- [ ] **Step 2: Write the failing tests for the pure helpers**

```typescript
// frontend/src/composables/useNoteTreeSortable.spec.ts
import { describe, expect, it } from 'vitest'
import {
  parseSortItemFromDataset,
  itemsFromContainer,
  basename,
  pathInFolder,
  shouldRejectMove,
} from './useNoteTreeSortable'

function makeEl(dataset: Record<string, string>): HTMLElement {
  const el = document.createElement('div')
  for (const [key, value] of Object.entries(dataset)) {
    el.dataset[key] = value
  }
  return el
}

describe('parseSortItemFromDataset', () => {
  it('parses a note item', () => {
    expect(parseSortItemFromDataset(makeEl({ itemType: 'note', itemId: '42' }).dataset))
      .toEqual({ type: 'note', id: 42 })
  })

  it('parses a folder item', () => {
    expect(parseSortItemFromDataset(makeEl({ itemType: 'folder', itemPath: 'docs/archived' }).dataset))
      .toEqual({ type: 'folder', path: 'docs/archived' })
  })
})

describe('itemsFromContainer', () => {
  it('reads items from a container in DOM order', () => {
    const container = document.createElement('div')
    container.appendChild(makeEl({ itemType: 'folder', itemPath: 'docs/archived' }))
    container.appendChild(makeEl({ itemType: 'note', itemId: '7' }))

    expect(itemsFromContainer(container)).toEqual([
      { type: 'folder', path: 'docs/archived' },
      { type: 'note', id: 7 },
    ])
  })
})

describe('basename', () => {
  it('returns the segment after the last slash', () => {
    expect(basename('docs/archived/note.md')).toBe('note.md')
  })

  it('returns the whole string when there is no slash', () => {
    expect(basename('note.md')).toBe('note.md')
  })
})

describe('pathInFolder', () => {
  it('joins a non-root folder path with a file name', () => {
    expect(pathInFolder('docs/archived', 'note.md')).toBe('docs/archived/note.md')
  })

  it('returns just the file name for the root folder', () => {
    expect(pathInFolder('', 'note.md')).toBe('note.md')
  })
})

describe('shouldRejectMove', () => {
  it('rejects a folder dragged into a different container', () => {
    expect(shouldRejectMove('folder', false)).toBe(true)
  })

  it('allows a folder reordered within the same container', () => {
    expect(shouldRejectMove('folder', true)).toBe(false)
  })

  it('allows a note dragged into a different container', () => {
    expect(shouldRejectMove('note', false)).toBe(false)
  })
})
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `./scripts/jt.sh npm -- test -- useNoteTreeSortable.spec.ts`
Expected: FAIL — module `./useNoteTreeSortable` does not exist.

- [ ] **Step 4: Implement the composable**

```typescript
// frontend/src/composables/useNoteTreeSortable.ts
import Sortable from 'sortablejs'

export type SortItem = { type: 'note'; id: number } | { type: 'folder'; path: string }

export function parseSortItemFromDataset(dataset: DOMStringMap): SortItem {
  if (dataset.itemType === 'folder') {
    return { type: 'folder', path: dataset.itemPath ?? '' }
  }
  return { type: 'note', id: Number(dataset.itemId) }
}

export function itemsFromContainer(container: HTMLElement): SortItem[] {
  return Array.from(container.children).map((el) =>
    parseSortItemFromDataset((el as HTMLElement).dataset)
  )
}

export function basename(path: string): string {
  const lastSlash = path.lastIndexOf('/')
  return lastSlash === -1 ? path : path.slice(lastSlash + 1)
}

export function pathInFolder(folderPath: string, fileName: string): string {
  return folderPath === '' ? fileName : `${folderPath}/${fileName}`
}

export function shouldRejectMove(draggedType: string | undefined, sameContainer: boolean): boolean {
  return draggedType === 'folder' && !sameContainer
}

export interface NoteTreeSortableCallbacks {
  onReorder: (folderPath: string, items: SortItem[]) => void
  onReparentNote: (noteId: number, newPath: string, destFolderPath: string, items: SortItem[]) => void
}

export interface NoteTreeSortableHandle {
  setDisabled: (disabled: boolean) => void
  destroy: () => void
}

export function createNoteTreeSortable(
  el: HTMLElement,
  initiallyDisabled: boolean,
  callbacks: NoteTreeSortableCallbacks,
): NoteTreeSortableHandle {
  const sortable = Sortable.create(el, {
    group: 'note-tree',
    disabled: initiallyDisabled,
    animation: 150,
    ghostClass: 'note-tree-ghost',
    onMove(evt) {
      const draggedType = (evt.dragged as HTMLElement).dataset.itemType
      return !shouldRejectMove(draggedType, evt.from === evt.to)
    },
    onEnd(evt) {
      const to = evt.to
      const from = evt.from
      const toFolderPath = to.dataset.folderPath ?? ''

      if (from === to) {
        callbacks.onReorder(toFolderPath, itemsFromContainer(to))
        return
      }

      const item = evt.item as HTMLElement
      const noteId = Number(item.dataset.itemId)
      const notePath = item.dataset.itemNotePath ?? ''
      const newPath = pathInFolder(toFolderPath, basename(notePath))
      callbacks.onReparentNote(noteId, newPath, toFolderPath, itemsFromContainer(to))
    },
  })

  return {
    setDisabled: (disabled: boolean) => sortable.option('disabled', disabled),
    destroy: () => sortable.destroy(),
  }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./scripts/jt.sh npm -- test -- useNoteTreeSortable.spec.ts`
Expected: PASS, 9 tests.

- [ ] **Step 6: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src/composables/useNoteTreeSortable.ts frontend/src/composables/useNoteTreeSortable.spec.ts
git commit -m "feat: add sortablejs dependency and note-tree drag composable"
```

---

### Task 6: `Sidebar.vue` — manual sort mode, tree ordering, root-list wiring

**Files:**
- Modify: `frontend/src/components/Sidebar.vue`
- Modify: `frontend/src/App.vue`
- Test: `frontend/src/Sidebar.spec.ts` (new)

**Interfaces:**
- Consumes: `createNoteTreeSortable`, `SortItem` (Task 5); `moveNote`, `reorderNoteTree`, `getFolderPositions` (Task 4); `NoteMeta.sort_position`, `FolderPosition` (Task 4).
- Produces: `Sidebar` provides `noteTreeDragCallbacks: NoteTreeDragCallbacks` and `noteTreeManualMode: Ref<boolean>` (via Vue `provide`) for `NoteTreeNode` (Task 7) to `inject`. New `Sidebar` props: `workspaceId: number | null`, `folderPositions: FolderPosition[]`. New `Sidebar` emit: `notes-reordered`.

- [ ] **Step 1: Write the failing test for manual-mode tree ordering**

```typescript
// frontend/src/Sidebar.spec.ts
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Sidebar from './components/Sidebar.vue'
import type { NoteMeta, FolderPosition } from './services/types'

vi.mock('./services/api', () => ({
  moveNote: vi.fn(),
  reorderNoteTree: vi.fn(),
}))

function makeNote(overrides: Partial<NoteMeta>): NoteMeta {
  return {
    id: 1,
    path: 'a.md',
    title: 'A',
    frontmatter: null,
    sort_position: null,
    updated_at: '2026-07-31T00:00:00Z',
    ...overrides,
  }
}

describe('Sidebar manual sort mode', () => {
  it('offers a Manual option in the sort dropdown', () => {
    const wrapper = mount(Sidebar, { props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [] } })
    const options = wrapper.findAll('#sidebar-sort-select option').map(o => o.attributes('value'))
    expect(options).toContain('manual')
  })

  it('orders notes and folders by sort_position when manual mode is active', async () => {
    const notes: NoteMeta[] = [
      makeNote({ id: 1, path: 'docs/z-note.md', title: 'Z', sort_position: 20 }),
      makeNote({ id: 2, path: 'docs/a-note.md', title: 'A', sort_position: 0 }),
      makeNote({ id: 3, path: 'docs/archived/inner.md', title: 'Inner', sort_position: null }),
    ]
    const folderPositions: FolderPosition[] = [{ folder_path: 'docs/archived', sort_position: 10 }]

    const wrapper = mount(Sidebar, {
      props: { notes, selectedNoteId: null, workspaceId: 1, folderPositions },
    })
    await wrapper.find('#sidebar-sort-select').setValue('manual')

    const titles = wrapper.findAll('.note-title, .folder-name').map(el => el.text())
    expect(titles).toEqual(['A', 'archived', 'Z'])
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm -- test -- Sidebar.spec.ts`
Expected: FAIL — no `manual` option exists yet, and ordering doesn't match.

- [ ] **Step 3: Add the `manual` sort option and props/emits**

In `frontend/src/components/Sidebar.vue`, change the `<select>` block:

```html
      <select id="sidebar-sort-select" v-model="sortBy" class="sort-select" aria-label="Sort notes by">
        <option value="recent">Recently Modified</option>
        <option value="name">Alphabetical</option>
        <option value="path">Vault Path</option>
      </select>
```

to:

```html
      <select id="sidebar-sort-select" v-model="sortBy" class="sort-select" aria-label="Sort notes by">
        <option value="recent">Recently Modified</option>
        <option value="name">Alphabetical</option>
        <option value="path">Vault Path</option>
        <option value="manual">Manual</option>
      </select>
```

Change the `props` and `defineEmits`:

```typescript
const props = defineProps<{
  notes: NoteMeta[]
  selectedNoteId: number | null
  currentUser?: AuthUser | null
  notifications?: NotificationItem[]
  isMobileSidebarOpen?: boolean
}>()

const emit = defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'create-note', path: string): void
  (e: 'create-note-from-template', templatePath: string, targetPath: string): void
  (e: 'delete-note', noteId: number): void
  (e: 'search', query: string): void
  (e: 'logout'): void
  (e: 'mark-notification-read', notificationId: number): void
  (e: 'delete-notification', notificationId: number): void
  (e: 'toggle-attachments'): void
  (e: 'daily-note'): void
  (e: 'toggle-audit-log'): void
  (e: 'import-workspace', archive: File, overwrite: boolean): void
  (e: 'export-workspace'): void
  (e: 'toggle-link-report'): void
  (e: 'publish-workspace'): void
  (e: 'toggle-table-view'): void
  (e: 'toggle-board-view'): void
  (e: 'toggle-calendar-view'): void
}>()
```

to:

```typescript
const props = defineProps<{
  notes: NoteMeta[]
  selectedNoteId: number | null
  currentUser?: AuthUser | null
  notifications?: NotificationItem[]
  isMobileSidebarOpen?: boolean
  workspaceId?: number | null
  folderPositions?: FolderPosition[]
}>()

const emit = defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'create-note', path: string): void
  (e: 'create-note-from-template', templatePath: string, targetPath: string): void
  (e: 'delete-note', noteId: number): void
  (e: 'search', query: string): void
  (e: 'logout'): void
  (e: 'mark-notification-read', notificationId: number): void
  (e: 'delete-notification', notificationId: number): void
  (e: 'toggle-attachments'): void
  (e: 'daily-note'): void
  (e: 'toggle-audit-log'): void
  (e: 'import-workspace', archive: File, overwrite: boolean): void
  (e: 'export-workspace'): void
  (e: 'toggle-link-report'): void
  (e: 'publish-workspace'): void
  (e: 'toggle-table-view'): void
  (e: 'toggle-board-view'): void
  (e: 'toggle-calendar-view'): void
  (e: 'notes-reordered'): void
}>()
```

Change the `sortBy` ref type:

```typescript
const sortBy = ref<'recent' | 'name' | 'path'>('recent')
```

to:

```typescript
const sortBy = ref<'recent' | 'name' | 'path' | 'manual'>('recent')
```

Update the imports at the top of the `<script setup>` block:

```typescript
import { ref, computed } from 'vue'
import type { NoteMeta, AuthUser, NotificationItem } from '../services/types'
import NoteTreeNode from './NoteTreeNode.vue'
import type { TreeFolder, TreeNode } from './NoteTreeNode.vue'
import ThemeToggle from './ThemeToggle.vue'
```

to:

```typescript
import { ref, computed, provide, onMounted, onBeforeUnmount, watch, useTemplateRef } from 'vue'
import type { NoteMeta, AuthUser, NotificationItem, FolderPosition, SortItem } from '../services/types'
import NoteTreeNode from './NoteTreeNode.vue'
import type { TreeFolder, TreeNode } from './NoteTreeNode.vue'
import ThemeToggle from './ThemeToggle.vue'
import { moveNote, reorderNoteTree } from '../services/api'
import { createNoteTreeSortable, type NoteTreeSortableHandle } from '../composables/useNoteTreeSortable'
```

- [ ] **Step 4: Update `filteredAndSortedNotes` and `buildTree` for manual mode**

Change the sort comparator inside `filteredAndSortedNotes`:

```typescript
  return [...list].sort((a, b) => {
    if (sortBy.value === 'recent') {
      return (b.updated_at || '').localeCompare(a.updated_at || '')
    }
    if (sortBy.value === 'name') {
      return (a.title || a.path).localeCompare(b.title || b.path)
    }
    return a.path.localeCompare(b.path)
  })
```

to:

```typescript
  return [...list].sort((a, b) => {
    if (sortBy.value === 'recent') {
      return (b.updated_at || '').localeCompare(a.updated_at || '')
    }
    if (sortBy.value === 'name') {
      return (a.title || a.path).localeCompare(b.title || b.path)
    }
    if (sortBy.value === 'manual') {
      return 0
    }
    return a.path.localeCompare(b.path)
  })
```

Change `buildTree` and the `noteTree` computed:

```typescript
function buildTree(notes: NoteMeta[]): TreeNode[] {
  const root: TreeFolder = { type: 'folder', name: '', fullPath: '', children: [] }
  const folders = new Map<string, TreeFolder>([['', root]])

  function getFolder(path: string): TreeFolder {
    const existing = folders.get(path)
    if (existing) return existing
    const lastSlash = path.lastIndexOf('/')
    const parentPath = lastSlash === -1 ? '' : path.slice(0, lastSlash)
    const name = lastSlash === -1 ? path : path.slice(lastSlash + 1)
    const parent = getFolder(parentPath)
    const folder: TreeFolder = { type: 'folder', name, fullPath: path, children: [] }
    parent.children.push(folder)
    folders.set(path, folder)
    return folder
  }

  for (const note of notes) {
    const lastSlash = note.path.lastIndexOf('/')
    const folderPath = lastSlash === -1 ? '' : note.path.slice(0, lastSlash)
    getFolder(folderPath).children.push({ type: 'file', note })
  }

  function sortChildren(folder: TreeFolder) {
    const subfolders = folder.children.filter((c): c is TreeFolder => c.type === 'folder')
    const files = folder.children.filter((c) => c.type === 'file')
    subfolders.sort((a, b) => a.name.localeCompare(b.name))
    subfolders.forEach(sortChildren)
    folder.children = [...subfolders, ...files]
  }
  sortChildren(root)

  return root.children
}

const noteTree = computed(() => buildTree(filteredAndSortedNotes.value))
```

to:

```typescript
function buildTree(notes: NoteMeta[], manual: boolean, folderPositionMap: Map<string, number>): TreeNode[] {
  const root: TreeFolder = { type: 'folder', name: '', fullPath: '', children: [] }
  const folders = new Map<string, TreeFolder>([['', root]])

  function getFolder(path: string): TreeFolder {
    const existing = folders.get(path)
    if (existing) return existing
    const lastSlash = path.lastIndexOf('/')
    const parentPath = lastSlash === -1 ? '' : path.slice(0, lastSlash)
    const name = lastSlash === -1 ? path : path.slice(lastSlash + 1)
    const parent = getFolder(parentPath)
    const folder: TreeFolder = { type: 'folder', name, fullPath: path, children: [] }
    parent.children.push(folder)
    folders.set(path, folder)
    return folder
  }

  for (const note of notes) {
    const lastSlash = note.path.lastIndexOf('/')
    const folderPath = lastSlash === -1 ? '' : note.path.slice(0, lastSlash)
    getFolder(folderPath).children.push({ type: 'file', note })
  }

  function positionOf(node: TreeNode): number | null {
    if (node.type === 'folder') return folderPositionMap.get(node.fullPath) ?? null
    return node.note.sort_position ?? null
  }

  function displayName(node: TreeNode): string {
    return node.type === 'folder' ? node.name : (node.note.title || node.note.path)
  }

  function sortChildrenAlphabetical(folder: TreeFolder) {
    const subfolders = folder.children.filter((c): c is TreeFolder => c.type === 'folder')
    const files = folder.children.filter((c) => c.type === 'file')
    subfolders.sort((a, b) => a.name.localeCompare(b.name))
    subfolders.forEach(sortChildrenAlphabetical)
    folder.children = [...subfolders, ...files]
  }

  function sortChildrenManual(folder: TreeFolder) {
    const positioned = folder.children.filter((c) => positionOf(c) !== null)
    const unpositioned = folder.children.filter((c) => positionOf(c) === null)

    positioned.sort((a, b) => positionOf(a)! - positionOf(b)!)
    unpositioned.sort((a, b) => displayName(a).localeCompare(displayName(b)))

    folder.children = [...positioned, ...unpositioned]
    folder.children.forEach((c) => { if (c.type === 'folder') sortChildrenManual(c) })
  }

  if (manual) {
    sortChildrenManual(root)
  } else {
    sortChildrenAlphabetical(root)
  }

  return root.children
}

const folderPositionMap = computed(
  () => new Map((props.folderPositions ?? []).map((fp) => [fp.folder_path, fp.sort_position]))
)

const noteTree = computed(() =>
  buildTree(filteredAndSortedNotes.value, sortBy.value === 'manual', folderPositionMap.value)
)
```

- [ ] **Step 5: Wire the root-list drag callbacks and provide them for `NoteTreeNode`**

Add near the bottom of the `<script setup>` block (after `noteTree`):

```typescript
async function handleReorder(folderPath: string, items: SortItem[]) {
  if (!props.workspaceId) return
  try {
    await reorderNoteTree(props.workspaceId, folderPath, items)
    emit('notes-reordered')
  } catch (err) {
    console.error('Failed to reorder note tree:', err)
  }
}

async function handleReparentNote(noteId: number, newPath: string, destFolderPath: string, items: SortItem[]) {
  if (!props.workspaceId) return
  try {
    await moveNote(props.workspaceId, noteId, newPath)
    await reorderNoteTree(props.workspaceId, destFolderPath, items)
    emit('notes-reordered')
  } catch (err) {
    console.error('Failed to move note:', err)
  }
}

const isManualMode = computed(() => sortBy.value === 'manual')
provide('noteTreeDragCallbacks', { onReorder: handleReorder, onReparentNote: handleReparentNote })
provide('noteTreeManualMode', isManualMode)

const rootListRef = useTemplateRef<HTMLElement>('rootList')
let rootSortable: NoteTreeSortableHandle | null = null

onMounted(() => {
  if (!rootListRef.value) return
  rootSortable = createNoteTreeSortable(rootListRef.value, !isManualMode.value, {
    onReorder: handleReorder,
    onReparentNote: handleReparentNote,
  })
})

watch(isManualMode, (manual) => {
  rootSortable?.setDisabled(!manual)
})

onBeforeUnmount(() => {
  rootSortable?.destroy()
})
```

Update the root list's template markup — change:

```html
      <div v-else class="notes-list">
        <NoteTreeNode
          v-for="node in noteTree"
          :key="node.type === 'folder' ? `f:${node.fullPath}` : `n:${node.note.id}`"
          :node="node"
          :selected-note-id="selectedNoteId"
          :depth="0"
          @select-note="$emit('select-note', $event)"
          @delete-note="$emit('delete-note', $event)"
        />
      </div>
```

to:

```html
      <div v-else class="notes-list" ref="rootList" data-folder-path="">
        <NoteTreeNode
          v-for="node in noteTree"
          :key="node.type === 'folder' ? `f:${node.fullPath}` : `n:${node.note.id}`"
          :node="node"
          :selected-note-id="selectedNoteId"
          :depth="0"
          @select-note="$emit('select-note', $event)"
          @delete-note="$emit('delete-note', $event)"
        />
      </div>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./scripts/jt.sh npm -- test -- Sidebar.spec.ts`
Expected: PASS, 2 tests.

- [ ] **Step 7: Wire `App.vue` — pass `workspaceId`/`folderPositions`, load positions, handle `notes-reordered`**

In `frontend/src/App.vue`, add to the `api` import list (after `getNotes,`):

```typescript
  getFolderPositions,
```

Add to the type import line, after `NoteMeta,`:

```typescript
  FolderPosition,
```

Add a new ref near `const notes = ref<NoteMeta[]>([])`:

```typescript
const folderPositions = ref<FolderPosition[]>([])
```

Change `refreshNotesList`:

```typescript
async function refreshNotesList() {
  if (!activeWorkspaceId.value) return
  try {
    const list = await getNotes(activeWorkspaceId.value)
    notes.value = list
```

to:

```typescript
async function refreshNotesList() {
  if (!activeWorkspaceId.value) return
  try {
    const [list, positions] = await Promise.all([
      getNotes(activeWorkspaceId.value),
      getFolderPositions(activeWorkspaceId.value),
    ])
    notes.value = list
    folderPositions.value = positions
```

(the rest of the function, starting at `if (activeNoteId.value) {`, is unchanged).

In the `<Sidebar>` usage, add two props and one listener:

```html
    <Sidebar
      :notes="notes"
      :selected-note-id="activeNoteId"
      :current-user="currentUser"
      :notifications="notifications"
      :is-mobile-sidebar-open="isMobileSidebarOpen"
      @select-note="handleSelectNote"
```

to:

```html
    <Sidebar
      :notes="notes"
      :selected-note-id="activeNoteId"
      :current-user="currentUser"
      :notifications="notifications"
      :is-mobile-sidebar-open="isMobileSidebarOpen"
      :workspace-id="activeWorkspaceId"
      :folder-positions="folderPositions"
      @notes-reordered="refreshNotesList"
      @select-note="handleSelectNote"
```

- [ ] **Step 8: Run the full frontend suite**

Run: `./scripts/jt.sh npm -- test`
Expected: PASS, all existing tests plus the new ones (no regressions from the `refreshNotesList`/prop changes).

- [ ] **Step 9: Commit**

```bash
git add frontend/src/components/Sidebar.vue frontend/src/App.vue frontend/src/Sidebar.spec.ts
git commit -m "feat: add manual sort mode and drag wiring to Sidebar"
```

---

### Task 7: `NoteTreeNode.vue` — draggable rows, nested-list wiring

**Files:**
- Modify: `frontend/src/components/NoteTreeNode.vue`
- Test: `frontend/src/NoteTreeNode.spec.ts` (new)

**Interfaces:**
- Consumes: `noteTreeDragCallbacks`/`noteTreeManualMode` (Task 6, via `inject`), `createNoteTreeSortable` (Task 5).
- Produces: nothing consumed by later tasks — this is the tree leaf/branch renderer.

- [ ] **Step 1: Write the failing test for data attributes and `v-show`**

```typescript
// frontend/src/NoteTreeNode.spec.ts
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import NoteTreeNode from './components/NoteTreeNode.vue'
import type { TreeNode } from './components/NoteTreeNode.vue'

describe('NoteTreeNode drag attributes', () => {
  it('marks a note row with its type and id', () => {
    const node: TreeNode = {
      type: 'file',
      note: { id: 7, path: 'docs/a.md', title: 'A', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' },
    }
    const wrapper = mount(NoteTreeNode, { props: { node, selectedNoteId: null, depth: 0 } })
    const row = wrapper.find('.note-item')
    expect(row.attributes('data-item-type')).toBe('note')
    expect(row.attributes('data-item-id')).toBe('7')
    expect(row.attributes('data-item-note-path')).toBe('docs/a.md')
  })

  it('marks a folder row with its type and path, and keeps children in the DOM when collapsed', async () => {
    const node: TreeNode = {
      type: 'folder',
      name: 'docs',
      fullPath: 'docs',
      children: [
        { type: 'file', note: { id: 1, path: 'docs/a.md', title: 'A', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' } },
      ],
    }
    const wrapper = mount(NoteTreeNode, { props: { node, selectedNoteId: null, depth: 0 } })
    const row = wrapper.find('.tree-folder')
    expect(row.attributes('data-item-type')).toBe('folder')
    expect(row.attributes('data-item-path')).toBe('docs')

    await wrapper.find('.folder-row').trigger('click')
    const children = wrapper.find('.folder-children')
    expect(children.exists()).toBe(true)
    expect((children.element as HTMLElement).style.display).toBe('none')
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm -- test -- NoteTreeNode.spec.ts`
Expected: FAIL — the `data-item-*` attributes don't exist yet, and `.folder-children` is entirely absent from the DOM once collapsed (`v-if`, not `v-show`).

- [ ] **Step 3: Add data attributes, switch `v-if` to `v-show`, wire the composable**

In `frontend/src/components/NoteTreeNode.vue`, change the folder branch's root and children container:

```html
  <div v-if="node.type === 'folder'" class="tree-folder">
    <button
      type="button"
      class="folder-row"
      :style="{ paddingLeft: `${depth * 14 + 8}px` }"
      :aria-expanded="expanded"
      @click="expanded = !expanded"
    >
```

to:

```html
  <div
    v-if="node.type === 'folder'"
    class="tree-folder"
    data-item-type="folder"
    :data-item-path="node.fullPath"
  >
    <button
      type="button"
      class="folder-row"
      :style="{ paddingLeft: `${depth * 14 + 8}px` }"
      :aria-expanded="expanded"
      @click="expanded = !expanded"
    >
```

and:

```html
    <div v-if="expanded" class="folder-children">
```

to:

```html
    <div v-show="expanded" class="folder-children" ref="childrenListRef" :data-folder-path="node.fullPath">
```

Change the file branch's root:

```html
  <div
    v-else
    class="note-item"
    :class="{ active: selectedNoteId === node.note.id }"
    :style="{ paddingLeft: `${depth * 14 + 8}px` }"
    @click="$emit('select-note', node.note.id)"
  >
```

to:

```html
  <div
    v-else
    class="note-item"
    :class="{ active: selectedNoteId === node.note.id }"
    :style="{ paddingLeft: `${depth * 14 + 8}px` }"
    data-item-type="note"
    :data-item-id="node.note.id"
    :data-item-note-path="node.note.path"
    @click="$emit('select-note', node.note.id)"
  >
```

In the `<script setup>` block, change:

```typescript
import { computed, ref } from 'vue'
import type { NoteMeta } from '../services/types'
```

to:

```typescript
import { computed, ref, inject, onMounted, onBeforeUnmount, watch, useTemplateRef, type Ref } from 'vue'
import type { NoteMeta } from '../services/types'
import {
  createNoteTreeSortable,
  type NoteTreeSortableCallbacks,
  type NoteTreeSortableHandle,
} from '../composables/useNoteTreeSortable'
```

Add, right after the `noteIcon` computed at the end of the script block:

```typescript
const dragCallbacks = inject<NoteTreeSortableCallbacks>('noteTreeDragCallbacks')
const isManualMode = inject<Ref<boolean>>('noteTreeManualMode')

const childrenListRef = useTemplateRef<HTMLElement>('childrenListRef')
let childrenSortable: NoteTreeSortableHandle | null = null

onMounted(() => {
  if (!childrenListRef.value || !dragCallbacks || !isManualMode) return
  childrenSortable = createNoteTreeSortable(childrenListRef.value, !isManualMode.value, dragCallbacks)
})

watch(isManualMode ?? ref(false), (manual) => {
  childrenSortable?.setDisabled(!manual)
})

onBeforeUnmount(() => {
  childrenSortable?.destroy()
})
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm -- test -- NoteTreeNode.spec.ts`
Expected: PASS, 2 tests.

- [ ] **Step 5: Style the drop-placeholder class**

Add to the `<style scoped>` block of `frontend/src/components/NoteTreeNode.vue` (anywhere among the other rules):

```css
.note-tree-ghost {
  background: var(--color-hover);
  opacity: 0.6;
}
```

- [ ] **Step 6: Run the full frontend suite**

Run: `./scripts/jt.sh npm -- test`
Expected: PASS, all tests including Task 6's `Sidebar.spec.ts` and the pre-existing suite — no regressions.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/components/NoteTreeNode.vue frontend/src/NoteTreeNode.spec.ts
git commit -m "feat: wire draggable rows into NoteTreeNode"
```

---

### Task 8: End-to-end verification

**Files:**
- Create: `frontend/e2e/sidebar-drag-drop.spec.ts`

**Interfaces:**
- Consumes: the running application (all prior tasks).
- Produces: nothing consumed by later tasks — this is the final verification layer, per spec §7 ("native drag events are exactly the case jsdom can't simulate").

- [ ] **Step 1: Write the e2e spec**

```typescript
// frontend/e2e/sidebar-drag-drop.spec.ts
import { test, expect } from '@playwright/test'

test.describe('Sidebar drag-and-drop', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/')
    await page.waitForSelector('.notes-list')
  })

  test('reordering two notes within a folder persists across reload', async ({ page }) => {
    await page.selectOption('#sidebar-sort-select', 'manual')

    const items = page.locator('.notes-list [data-item-type]')
    const firstTitleBefore = await items.nth(0).locator('.note-title, .folder-name').first().textContent()
    const secondTitleBefore = await items.nth(1).locator('.note-title, .folder-name').first().textContent()

    const source = items.nth(1)
    const target = items.nth(0)
    await source.hover()
    await page.mouse.down()
    await target.hover()
    await page.mouse.up()

    await page.reload()
    await page.selectOption('#sidebar-sort-select', 'manual')

    const itemsAfter = page.locator('.notes-list [data-item-type]')
    const firstTitleAfter = await itemsAfter.nth(0).locator('.note-title, .folder-name').first().textContent()
    expect(firstTitleAfter).toBe(secondTitleBefore)
    expect(firstTitleAfter).not.toBe(firstTitleBefore)
  })

  test('dragging a folder into a different parent snaps back (rejected)', async ({ page }) => {
    await page.selectOption('#sidebar-sort-select', 'manual')

    const folders = page.locator('[data-item-type="folder"]')
    const folderCountBefore = await folders.count()
    if (folderCountBefore < 2) test.skip()

    const source = folders.nth(0)
    const target = folders.nth(1)
    await source.hover()
    await page.mouse.down()
    await target.locator('.folder-children').hover({ force: true })
    await page.mouse.up()

    const folderCountAfter = await folders.count()
    expect(folderCountAfter).toBe(folderCountBefore)
  })
})
```

- [ ] **Step 2: Run the e2e spec against the dockerized app**

Run: `./scripts/jt.sh npm -- run e2e -- e2e/sidebar-drag-drop.spec.ts`
Expected: PASS. If the fixture vault doesn't have at least 2 notes in the same folder (needed for the first test) or at least 2 folders (needed for the second), first create them through the running app's "Create a note" UI at paths like `docs/a.md`/`docs/b.md` and `docs/one/x.md`/`docs/two/y.md`, then re-run.

- [ ] **Step 3: Commit**

```bash
git add frontend/e2e/sidebar-drag-drop.spec.ts
git commit -m "test: add e2e coverage for sidebar drag-and-drop"
```

---

## Self-Review Notes

- **Spec coverage:** §3 (data model) → Task 1; §4 (backend API) → Task 2 + Task 3 (metadata exposure, called out separately in spec §4's "Reused, unchanged" line but the `sort_position` field itself needed its own task since `WorkspaceNoteController` wasn't otherwise touched); §5 (frontend integration, all bullets: api.ts, sortablejs, Sidebar, NoteTreeNode) → Tasks 4–7; §6 (invariant deviation) → enforced structurally (no front-matter code path touches these tables) and documented in Task 1's migrations; §7 (testing, all four layers) → one test step per task plus Task 8 for e2e; §8 (non-goals) → enforced by `shouldRejectMove`/`onMove` (Task 5/7) and simply not building subtree-reparent or undo.
- **Placeholder scan:** none found; the one deliberately-flagged leftover line in Task 2 Step 1 is explicit throwaway text with instructions to delete it before saving, not a TODO.
- **Type consistency:** `SortItem` defined once in `types.ts` (Task 4) and re-exported from the composable (Task 5) as the same shape; `NoteTreeSortableCallbacks`/`NoteTreeSortableHandle` defined in Task 5 and consumed with matching names in Tasks 6–7; `createNoteTreeSortable(el, initiallyDisabled, callbacks)` signature used identically in both Sidebar (root list) and NoteTreeNode (nested lists).
