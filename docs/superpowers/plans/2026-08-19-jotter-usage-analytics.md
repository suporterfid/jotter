# Usage Analytics / Engagement Rollup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver issue #357 with durable, workspace-scoped engagement analytics that are incrementally derived from `audit_log`, survive the 90-day audit prune, and expose an authorized dashboard without querying the raw audit table at read time.

**Architecture:** Add a MySQL rollup table keyed by workspace, UTC calendar day, dimension (`note`, `actor`, or `event`), and dimension key, plus a singleton cursor recording the last processed `audit_log.id`. A bounded `analytics:rollup` Artisan command processes the audit stream in transactions: each batch updates rollups and advances the cursor atomically, so reruns do not double-count. The dashboard reads only rollups and current note/workspace projections; note visibility is filtered through `NoteAccess` before results leave the API.

**Tech Stack:** Laravel 12, Eloquent, MySQL migrations/upserts, Artisan commands, Vue 3, TypeScript, Vitest, PHPUnit, existing `IdentityProvider` and `NoteAccess` seams.

**Spec:** `docs/20260817-jotter-confluence-parity-audit.md` §2 gap 10, GitHub issue #357, and the acceptance criteria recorded in `BACKLOG.md`.

## Global Constraints

- Never compute dashboard metrics live from `audit_log`; the dashboard queries durable rollups only.
- The rollup command must be bounded, cron-runnable, transactionally idempotent, and safe to run concurrently.
- Rollups must remain after `audit:prune` deletes their source rows.
- Every dashboard endpoint is workspace-authorized and filters note-level results through `NoteAccess::scopeVisible()`.
- No read event is emitted by default. `NOTE_VIEWED` is opt-in, configuration-gated, and must be recorded only after a successful authorized note read.
- No daemon, queue worker, websocket, or unbounded request-time aggregation may be introduced; shared-hosting execution is via cron and `JobDispatcher` is not needed for the bounded rollup.
- Preserve the Markdown-on-disk invariant, existing audit redaction, i18n in `en` and `pt-BR`, semantic design tokens, and current branch protection.

---

### Task 1: Define durable rollup storage and domain contracts

**Files:**
- Create: `database/migrations/2026_08_19_000009_create_audit_rollups_tables.php`
- Create: `app/Models/AuditRollup.php`
- Create: `app/Models/AuditRollupCursor.php`
- Create: `app/Domain/Analytics/RollupDimension.php`
- Modify: `config/jotter.php`
- Modify: `.env.example`
- Test: `tests/Feature/AuditRollupStorageTest.php`

**Interfaces:**
- `AuditRollup` stores `workspace_id`, `period_start`, `dimension`, `dimension_key`, `count`, `first_seen_at`, and `last_seen_at`; enforce a unique key on `(workspace_id, period_start, dimension, dimension_key)`.
- `AuditRollupCursor` stores the singleton `stream = audit_log` and `last_audit_id`, with a unique stream key.
- `RollupDimension` exposes the exact values `note`, `actor`, and `event`; no free-form dimension types are accepted.
- Configuration exposes `analytics.rollup_batch_size` (default `500`), `analytics.stale_days` (default `30`), and `analytics.record_reads` (default `false`).

- [ ] **Step 1: Write failing schema/model tests.** Assert both tables exist, the rollup unique key prevents duplicate dimension rows for a day, the cursor defaults to `0`, and the configuration defaults are stable.

```php
public function test_audit_rollup_storage_has_a_unique_daily_dimension_key(): void
{
    $this->assertTrue(Schema::hasTable('audit_rollups'));
    $this->assertTrue(Schema::hasTable('audit_rollup_cursors'));
    $this->assertTrue(Schema::hasColumn('audit_rollups', 'last_seen_at'));
    $this->assertSame(0, AuditRollupCursor::query()->firstOrCreate(
        ['stream' => 'audit_log'],
        ['last_audit_id' => 0],
    )->last_audit_id);
}
```

- [ ] **Step 2: Run the focused test to verify it fails.**

Run: `php artisan test tests/Feature/AuditRollupStorageTest.php`
Expected: FAIL because the migrations, models, and configuration do not exist yet.

- [ ] **Step 3: Add the migration and models.** Use foreign keys to `workspaces` with cascade delete, a `date` `period_start`, an unsigned-big-integer `count`, nullable timestamps for first/last observation, and an indexed cursor table. Cast `period_start` and timestamps in the model; cast `count` and `last_audit_id` to integers.

- [ ] **Step 4: Add bounded analytics configuration.** Add the three `JOTTER_ANALYTICS_*` environment variables to `.env.example` and read them from `config/jotter.php`; keep `JOTTER_ANALYTICS_RECORD_READS=false` so existing deployments do not add a write to note reads.

- [ ] **Step 5: Run the focused test to verify it passes.**

Run: `php artisan test tests/Feature/AuditRollupStorageTest.php`
Expected: PASS.

- [ ] **Step 6: Commit the storage contract.**

```bash
git add database/migrations/2026_08_19_000009_create_audit_rollups_tables.php app/Models/AuditRollup.php app/Models/AuditRollupCursor.php app/Domain/Analytics/RollupDimension.php config/jotter.php .env.example tests/Feature/AuditRollupStorageTest.php
git commit -m "feat: add durable audit rollup storage"
```

### Task 2: Implement the bounded, transactional rollup command

**Files:**
- Create: `app/Domain/Analytics/AuditRollupProcessor.php`
- Create: `app/Domain/Analytics/AuditRollupBatchResult.php`
- Create: `app/Console/Commands/AuditRollupCommand.php`
- Test: `tests/Feature/AuditRollupCommandTest.php`

**Interfaces:**
- `AuditRollupBatchResult` is a readonly value object with integer fields `processed`, `skipped`, and `lastAuditId`.
- `AuditRollupProcessor::process(int $batchSize): AuditRollupBatchResult` reads `audit_log.id > cursor`, ordered ascending, limited by `$batchSize`; it aggregates only rows with a non-null `workspace_id`.
- Each workspace audit row contributes one event-dimension row; rows with `note_id` contribute one note-dimension row; rows with `actor_subject_id` contribute one actor-dimension row.
- `AuditRollupCommand` exposes `analytics:rollup {--batch= : Maximum source rows per invocation}` and returns success with processed/skipped counts.
- The processor locks the cursor row, updates rollups, and advances the cursor to the batch maximum in one database transaction. A failed transaction leaves both rollups and cursor unchanged.

- [ ] **Step 1: Write failing command tests for aggregation and bounded processing.** Cover note, actor, and event dimensions; a batch size of `2`; workspace isolation; and the command output.

```php
public function test_rollup_processes_a_bounded_batch_into_note_actor_and_event_dimensions(): void
{
    $workspace = $this->workspaceFixture();
    $this->audit($workspace, 'note.updated', noteId: 11, actor: 'user:1');
    $this->audit($workspace, 'note.updated', noteId: 11, actor: 'user:1');
    $this->audit($workspace, 'note.created', noteId: 12, actor: 'user:2');

    $this->artisan('analytics:rollup', ['--batch' => 2])->assertExitCode(0);

    $this->assertDatabaseHas('audit_rollups', [
        'workspace_id' => $workspace->id,
        'dimension' => 'note',
        'dimension_key' => '11',
        'count' => 2,
    ]);
    $this->assertDatabaseHas('audit_rollup_cursors', [
        'stream' => 'audit_log',
        'last_audit_id' => 2,
    ]);
}

private function workspaceFixture(): Workspace
{
    $tenant = Tenant::create([
        'slug' => 'analytics-'.bin2hex(random_bytes(3)),
        'name' => 'Analytics tenant',
    ]);
    $vaultPath = storage_path('app/vaults/analytics-'.bin2hex(random_bytes(3)));
    mkdir($vaultPath, 0755, true);
    $this->vaultDirectories[] = $vaultPath;

    return Workspace::create([
        'tenant_id' => $tenant->id,
        'slug' => 'workspace',
        'name' => 'Analytics workspace',
        'vault_path' => $vaultPath,
    ]);
}

private function audit(Workspace $workspace, string $event, ?int $noteId, ?string $actor): AuditLog
{
    return AuditLog::query()->create([
        'workspace_id' => $workspace->id,
        'note_id' => $noteId,
        'actor_subject_id' => $actor,
        'event' => $event,
        'metadata' => [],
        'ip_address' => '127.0.0.1',
    ]);
}
```

- [ ] **Step 2: Run the focused test to verify it fails.**

Run: `php artisan test tests/Feature/AuditRollupCommandTest.php`
Expected: FAIL because `analytics:rollup` and the processor do not exist.

- [ ] **Step 3: Implement the processor.** Ensure the singleton cursor is created before locking; use a database transaction around cursor lock, source selection, rollup increments, and cursor advancement. Use the rollup unique key for deterministic increments and derive `period_start` from the source row's UTC `created_at`, not the current runtime date.

- [ ] **Step 4: Implement the Artisan command.** Resolve the batch from `--batch` or `config('jotter.analytics.rollup_batch_size')`, reject non-positive values by falling back to the configured default, invoke the processor once, print processed/skipped rows, and return `Command::SUCCESS`.

- [ ] **Step 5: Add idempotence, prune-survival, and concurrency tests.** Run the command twice and assert counts remain unchanged; prune the original `audit_log` rows and assert rollups remain; create two workspaces and assert no cross-workspace rows; verify a batch with only system/tenant rows advances the cursor without creating workspace metrics.

- [ ] **Step 6: Run the focused test to verify it passes.**

Run: `php artisan test tests/Feature/AuditRollupCommandTest.php`
Expected: PASS, including the double-run and post-prune assertions.

- [ ] **Step 7: Commit the rollup pipeline.**

```bash
git add app/Domain/Analytics/AuditRollupProcessor.php app/Console/Commands/AuditRollupCommand.php tests/Feature/AuditRollupCommandTest.php
git commit -m "feat: add bounded idempotent audit rollups"
```

### Task 3: Make note views measurable only when explicitly enabled

**Files:**
- Modify: `app/Domain/Audit/AuditEvent.php`
- Modify: `app/Http/Controllers/WorkspaceNoteController.php`
- Create: `tests/Feature/NoteViewAuditTest.php`
- Modify: `config/jotter.php`
- Modify: `.env.example`

**Interfaces:**
- Add `AuditEvent::NOTE_VIEWED = 'note.viewed'`.
- Inject `AuditRecorder` into `WorkspaceNoteController` and record `NOTE_VIEWED` only after `assertView()` succeeds and the note content has been read successfully.
- Pass the authenticated subject, workspace, and note id to the recorder; do not emit for unauthorized, missing, deleted, or missing-on-disk notes.
- Guard the write with `config('jotter.analytics.record_reads', false)`; the default path must remain write-free.

- [ ] **Step 1: Write failing tests for the feature flag and authorization boundary.** Assert disabled mode creates no `note.viewed`, enabled mode creates exactly one row with `workspace_id` and `note_id`, and a user who cannot view the note creates no event.

- [ ] **Step 2: Run the focused test to verify it fails.**

Run: `php artisan test tests/Feature/NoteViewAuditTest.php`
Expected: FAIL because the event and controller hook do not exist.

- [ ] **Step 3: Implement the enum and guarded recorder call.** Keep public-share, WebDAV, attachment, and list endpoints out of this first read metric; the issue asks for a deliberate read-path decision, and the authenticated note detail endpoint is the smallest auditable surface.

- [ ] **Step 4: Run the focused test to verify it passes.**

Run: `php artisan test tests/Feature/NoteViewAuditTest.php`
Expected: PASS with the default flag off and the opt-in path covered.

- [ ] **Step 5: Commit the opt-in view event.**

```bash
git add app/Domain/Audit/AuditEvent.php app/Http/Controllers/WorkspaceNoteController.php config/jotter.php .env.example tests/Feature/NoteViewAuditTest.php
git commit -m "feat: add opt-in note view audit events"
```

### Task 4: Expose an ACL-safe workspace analytics API

**Files:**
- Create: `app/Domain/Analytics/WorkspaceAnalyticsQuery.php`
- Create: `app/Http/Controllers/WorkspaceAnalyticsController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/WorkspaceAnalyticsTest.php`

**Interfaces:**
- `WorkspaceAnalyticsQuery::forWorkspace(Workspace $workspace, AuthenticatedSubject $subject, int $days, int $limit): array` reads only `audit_rollups` and returns `most_active_notes`, `activity_over_time`, `activity_by_user`, and `stale_notes`.
- The period is clamped to `1..90` days so the endpoint stays bounded and aligns with the raw-audit retention window without depending on it.
- `most_active_notes` joins current, non-deleted notes and applies `NoteAccess::scopeVisible()`; stale notes use `notes.updated_at` and the configured `stale_days`, so a note with no audit history can still appear as stale.
- `activity_by_user` returns opaque `actor_subject_id` values and counts only; the first version does not perform a display-name lookup, so external identity-provider subjects cannot leak profile data.
- `WorkspaceAnalyticsController::index()` uses the existing `authenticated_subject` middleware attribute, validates `days` and `limit`, and returns a stable JSON shape with no raw audit metadata or IP addresses.

- [ ] **Step 1: Write failing endpoint tests.** Cover the JSON shape, period and limit validation, workspace isolation, ACL filtering for restricted notes, stale-note cutoff, and the absence of raw audit rows from the response.

```php
$this->actingAs($viewer)
    ->getJson("/api/workspaces/{$workspace->id}/analytics?days=30&limit=10")
    ->assertOk()
    ->assertJsonStructure([
        'workspace_id',
        'period' => ['days', 'from', 'to'],
        'most_active_notes',
        'activity_over_time',
        'activity_by_user',
        'stale_notes',
    ])
    ->assertJsonMissingPath('most_active_notes.0.metadata')
    ->assertJsonMissingPath('most_active_notes.0.ip_address');
```

- [ ] **Step 2: Run the focused test to verify it fails.**

Run: `php artisan test tests/Feature/WorkspaceAnalyticsTest.php`
Expected: FAIL because the route, controller, and query object do not exist.

- [ ] **Step 3: Implement the query object with rollup-only reads.** Use grouped rollups for counts and daily activity; use `NoteAccess::scopeVisible()` on note joins; return empty arrays for workspaces with no rollup history; never fall back to `audit_log` in the request path.

- [ ] **Step 4: Implement the controller and route.** Keep the route within the existing workspace authorization group and add it beside `audit-logs` in `routes/api.php`.

- [ ] **Step 5: Run the focused test to verify it passes.**

Run: `php artisan test tests/Feature/WorkspaceAnalyticsTest.php`
Expected: PASS, including restricted-note and cross-workspace assertions.

- [ ] **Step 6: Commit the analytics API.**

```bash
git add app/Domain/Analytics/WorkspaceAnalyticsQuery.php app/Http/Controllers/WorkspaceAnalyticsController.php routes/api.php tests/Feature/WorkspaceAnalyticsTest.php
git commit -m "feat: expose workspace engagement analytics"
```

### Task 5: Replace the forensic-only view with a localized analytics dashboard

**Files:**
- Create: `frontend/src/components/WorkspaceAnalytics.vue`
- Create: `frontend/src/WorkspaceAnalytics.spec.ts`
- Modify: `frontend/src/services/api.ts`
- Modify: `frontend/src/services/types.ts`
- Modify: `frontend/src/App.vue`
- Modify: `frontend/src/i18n/locales/en.ts`
- Modify: `frontend/src/i18n/locales/pt-BR.ts`

**Interfaces:**
- Add `WorkspaceAnalytics` TypeScript types matching the API sections exactly.
- Add `getWorkspaceAnalytics(workspaceId: number, params?: { days?: number; limit?: number }): Promise<WorkspaceAnalytics>` to `frontend/src/services/api.ts`.
- `WorkspaceAnalytics.vue` accepts `workspaceId` and `loading`/data props or follows the existing panel pattern; it renders summary cards, a text/table daily activity series, ranked active notes, ranked contributors, and stale notes without introducing a chart dependency.
- Keep `AuditLogViewer.vue` and the raw audit-log action available as the forensic view; analytics complements it rather than replacing audit evidence.

- [ ] **Step 1: Write failing component and API tests.** Assert loading/empty/error states, localized headings in both locales, accessible table headings, no raw metadata rendering, and the API request path/query parameters.

- [ ] **Step 2: Run the focused frontend tests to verify they fail.**

Run: `npm run test -- --run src/WorkspaceAnalytics.spec.ts` from `frontend/`
Expected: FAIL because the component, API types, and request helper do not exist.

- [ ] **Step 3: Implement the API types/helper and dashboard component.** Use existing semantic tokens, responsive tables, `data-testid` hooks for stable tests, and an explicit note that “most active” is mutation/activity-based unless read tracking is enabled; do not label it “most viewed” by default.

- [ ] **Step 4: Wire the dashboard into `App.vue`.** Add a workspace analytics view state and navigation action adjacent to the existing audit-log action; load data only for the active workspace and reset the view when workspace context changes.

- [ ] **Step 5: Add English and Brazilian Portuguese strings.** Cover title, period selector, active notes, activity timeline, contributors, stale notes, no-data, loading, and error states; do not leave user-facing English literals in the component.

- [ ] **Step 6: Run focused and full frontend verification.**

Run: `npm run test -- --run src/WorkspaceAnalytics.spec.ts src/AuditLogViewer.spec.ts`
Run: `npm run test`
Run: `npm run build`
Expected: all focused tests, the full Vitest suite, and the production build pass.

- [ ] **Step 7: Commit the dashboard.**

```bash
git add frontend/src/components/WorkspaceAnalytics.vue frontend/src/WorkspaceAnalytics.spec.ts frontend/src/services/api.ts frontend/src/services/types.ts frontend/src/App.vue frontend/src/i18n/locales/en.ts frontend/src/i18n/locales/pt-BR.ts
git commit -m "feat: add workspace analytics dashboard"
```

### Task 6: Document operations and verify the complete issue contract

**Files:**
- Modify: `docs/deployment.md`
- Modify: `STATUS.md`
- Modify: `BACKLOG.md`
- Modify: `tests/Feature/AuditRollupCommandTest.php`
- Modify: `tests/Feature/WorkspaceAnalyticsTest.php`

- [ ] **Step 1: Document cron and retention behavior.** Add a bounded cron example using `php artisan analytics:rollup --batch=500`, explain that rollups survive `audit:prune`, document `JOTTER_ANALYTICS_RECORD_READS=false`, and state that enabling reads adds an audit write to successful authenticated note-detail requests.

- [ ] **Step 2: Run the existing release verification against the analytics changes.** The existing release build and `release:verify` scan cover the compiled UI, migrations, command, route, and secret exclusion; no second release scanner is needed for this issue.

- [ ] **Step 3: Run the complete verification gate.**

Run: `php artisan test`
Run: `npm run test` from `frontend/`
Run: `npm run build` from `frontend/`
Run: `php -l app/Domain/Analytics/AuditRollupProcessor.php` and `php -l app/Http/Controllers/WorkspaceAnalyticsController.php`
Run: `git diff --check`
Run: `./scripts/jt.sh release` and `./scripts/jt.sh release:verify` (or the PowerShell equivalents).

Expected: all existing and new tests pass, the build succeeds, PHP lint is clean, the diff has no whitespace errors, and the release verification finds no secrets.

- [ ] **Step 4: Commit documentation and final acceptance updates.**

```bash
git add docs/deployment.md STATUS.md BACKLOG.md tests
git commit -m "docs: document usage analytics operations"
```

## Priority decision

Implement #357 before #358–#360 because it is the next unshipped item in the audit sequence, has a bounded M-sized scope, and establishes a durable metrics foundation without changing the shared-hosting model. #358 is larger and UI/DOM-risky; #359 is explicitly low value for the current audience; #360 is a separate XS documentation decision that can be scheduled independently after the analytics plan is accepted.

## Acceptance checklist

- [ ] A bounded `analytics:rollup` command advances from a durable cursor and is safe to rerun.
- [ ] Running the command twice does not double-count.
- [ ] Rollup rows remain after `audit:prune` deletes source audit rows.
- [ ] Dashboard metrics are workspace-scoped, ACL-safe, and read only from rollup tables.
- [ ] Dashboard includes active notes, daily activity, contributors, and stale notes.
- [ ] `NOTE_VIEWED` is disabled by default and has authorization-boundary tests when enabled.
- [ ] Cron, retention, configuration, and the “activity vs. views” distinction are documented.
- [ ] Full PHP/Vitest/build/release verification is green before the implementation PR is opened.

