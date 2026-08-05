# GrandpaSSOn Service Tokens Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a GrandpaSSOn `client_credentials` bearer token authorize REST API access to one specific Jotter workspace, so systemic integrations can be managed centrally in GrandpaSSOn instead of Jotter's own siloed `MachineToken` table.

**Architecture:** `GrandpaSSOnIdentityProvider::resolveIdentity()` gains a new first branch: if the request carries a `Bearer` token, introspect it against GrandpaSSOn's existing `/oauth/introspect` endpoint (via a new `HttpIntrospectionClient`, ported from TaskConnect's identical integration) and build a synthetic `AuthenticatedSubject` with no `User` row. `isAuthorizedForWorkspace()`/`accessibleWorkspaceIds()`/`accessibleTenantIds()` gain a parallel branch that checks the token's `aud` claim (`workspace/{id}`) directly, bypassing the `Membership` lookup entirely. `AuthorizeWorkspaceAccess` middleware — the single chokepoint every workspace-scoped route already passes through — enforces `kb:read`/`kb:write` scope based on HTTP verb. No GrandpaSSOn repo changes; it already has the OAuth flow, the `kb:read`/`kb:write` scopes, and the `workspace/<id>` audience convention.

**Tech Stack:** Laravel 12 / PHP 8.2, Laravel's `Http` facade (`Http::fake()` in tests), PHPUnit (`php artisan test`), no frontend changes.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-05-grandpasson-service-tokens-design.md` — read it before starting; this plan implements it task-by-task.
- Working directory: `/home/ubuntu/projects/web/iroh/jotter`. Create branch `feature/grandpasson-service-tokens` off `main` before Task 1.
- Test runner: `./scripts/jt.sh artisan test --filter=<TestClass>` for one test class, `./scripts/jt.sh artisan test` for the full backend suite. This plan touches backend/PHP only — no frontend test runs needed, but run the full backend suite at the end regardless.
- Every new PHP class is `final` unless it's implementing an interface meant to be swapped in tests (match existing repo convention: `LocalIdentityProvider`, `NoteChecklistItemController` etc. are all `final`).
- Config default: `JOTTER_GRANDPASSON_INBOUND_ENABLED` defaults to `false` (`config('jotter.grandpasson_resource.inbound_enabled', false)`). With it `false`, every existing test and existing behavior must be provably unchanged — several tasks below have an explicit regression-check step for this.
- Audience convention: literal string `"workspace/{$workspaceId}"` (GrandpaSSOn's own existing convention — see `grandpasson/app/Domain/ScopeVocabulary.php`'s doc comment and TaskConnect's `workspace/<uuid>` usage). Scope names: literal strings `kb:read` and `kb:write` (already defined in GrandpaSSOn's `ScopeVocabulary::machineScopes()` — no new scopes to register).
- Commit style: lowercase `type: summary` (`feat:`, `test:`), one commit per task, test + implementation together.

---

### Task 1: Config block

**Files:**
- Modify: `config/jotter.php`

**Interfaces:**
- Produces: `config('jotter.grandpasson_resource.inbound_enabled')` (bool), `config('jotter.grandpasson_resource.introspect_url')` (string|null), `config('jotter.grandpasson_resource.client_id')` (string|null), `config('jotter.grandpasson_resource.client_secret')` (string|null). Consumed by Task 3 (`HttpIntrospectionClient`) and Task 5 (`GrandpaSSOnIdentityProvider`).

This task has no test of its own (it's a config file) — it's verified by the tasks that consume it.

- [ ] **Step 1: Add the config block**

In `config/jotter.php`, immediately after the existing `'sso' => [...]` block (which ends right before the `'attachments' => [` key), add:

```php
    // GrandpaSSOn service-token (client_credentials) inbound auth for
    // systemic REST API integrations — see docs/superpowers/specs/
    // 2026-08-05-grandpasson-service-tokens-design.md. Off by default;
    // when off, GrandpaSSOnIdentityProvider::resolveIdentity() behaves
    // exactly as it did before this feature existed.
    'grandpasson_resource' => [
        'inbound_enabled' => (bool) env('JOTTER_GRANDPASSON_INBOUND_ENABLED', false),
        'introspect_url' => env('JOTTER_GRANDPASSON_INTROSPECT_URL'),
        'client_id' => env('JOTTER_GRANDPASSON_CLIENT_ID'),
        'client_secret' => env('JOTTER_GRANDPASSON_CLIENT_SECRET'),
    ],
```

- [ ] **Step 2: Commit**

```bash
git add config/jotter.php
git commit -m "feat: add grandpasson_resource config block"
```

---

### Task 2: `IntrospectionResult` value object

**Files:**
- Create: `app/Domain/Auth/GrandpaSson/IntrospectionResult.php`
- Test: `tests/Unit/GrandpaSson/IntrospectionResultTest.php`

**Interfaces:**
- Produces:
  ```php
  namespace App\Domain\Auth\GrandpaSson;

  final class IntrospectionResult
  {
      /** @param list<string> $scopes @param list<string> $audiences */
      public function __construct(
          public readonly bool $active,
          public readonly array $scopes = [],
          public readonly array $audiences = [],
          public readonly ?string $clientId = null,
          public readonly ?string $subject = null,
      ) {}

      public function hasScope(string $scope): bool;
      public function audienceIncludesWorkspace(int $workspaceId): bool;
  }
  ```
  Consumed by Task 3 (`HttpIntrospectionClient` constructs it) and Task 5 (`GrandpaSSOnIdentityProvider` reads its fields).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/GrandpaSson/IntrospectionResultTest.php

namespace Tests\Unit\GrandpaSson;

use App\Domain\Auth\GrandpaSson\IntrospectionResult;
use PHPUnit\Framework\TestCase;

final class IntrospectionResultTest extends TestCase
{
    public function test_has_scope_checks_the_scopes_list(): void
    {
        $result = new IntrospectionResult(active: true, scopes: ['kb:read', 'kb:write']);

        $this->assertTrue($result->hasScope('kb:read'));
        $this->assertFalse($result->hasScope('kb:delete'));
    }

    public function test_audience_includes_workspace_matches_the_literal_convention(): void
    {
        $result = new IntrospectionResult(active: true, audiences: ['workspace/7']);

        $this->assertTrue($result->audienceIncludesWorkspace(7));
        $this->assertFalse($result->audienceIncludesWorkspace(8));
    }

    public function test_audience_includes_workspace_is_false_for_empty_audiences(): void
    {
        $result = new IntrospectionResult(active: false);

        $this->assertFalse($result->audienceIncludesWorkspace(7));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh artisan test --filter=IntrospectionResultTest`
Expected: FAIL (class `App\Domain\Auth\GrandpaSson\IntrospectionResult` not found)

- [ ] **Step 3: Write the implementation**

```php
<?php
// app/Domain/Auth/GrandpaSson/IntrospectionResult.php

namespace App\Domain\Auth\GrandpaSson;

final class IntrospectionResult
{
    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $audiences
     */
    public function __construct(
        public readonly bool $active,
        public readonly array $scopes = [],
        public readonly array $audiences = [],
        public readonly ?string $clientId = null,
        public readonly ?string $subject = null,
    ) {}

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function audienceIncludesWorkspace(int $workspaceId): bool
    {
        return in_array("workspace/{$workspaceId}", $this->audiences, true);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh artisan test --filter=IntrospectionResultTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Auth/GrandpaSson/IntrospectionResult.php tests/Unit/GrandpaSson/IntrospectionResultTest.php
git commit -m "feat: add IntrospectionResult value object"
```

---

### Task 3: `IntrospectionClientInterface` + `HttpIntrospectionClient`

**Files:**
- Create: `app/Domain/Auth/GrandpaSson/IntrospectionClientInterface.php`
- Create: `app/Domain/Auth/GrandpaSson/HttpIntrospectionClient.php`
- Test: `tests/Feature/GrandpaSson/HttpIntrospectionClientTest.php`

**Interfaces:**
- Consumes: `IntrospectionResult` (Task 2), `config('jotter.grandpasson_resource.*')` (Task 1).
- Produces:
  ```php
  namespace App\Domain\Auth\GrandpaSson;

  interface IntrospectionClientInterface
  {
      public function introspect(string $token): IntrospectionResult;
  }

  final class HttpIntrospectionClient implements IntrospectionClientInterface
  {
      public function introspect(string $token): IntrospectionResult;
  }
  ```
  Consumed by Task 5 (`GrandpaSSOnIdentityProvider` type-hints `IntrospectionClientInterface`, defaults to `new HttpIntrospectionClient()`).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/GrandpaSson/HttpIntrospectionClientTest.php

namespace Tests\Feature\GrandpaSson;

use App\Domain\Auth\GrandpaSson\HttpIntrospectionClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpIntrospectionClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'jotter.grandpasson_resource.introspect_url' => 'https://sso.example.test/oauth/introspect',
            'jotter.grandpasson_resource.client_id' => 'svc-jotter',
            'jotter.grandpasson_resource.client_secret' => 'secret123',
        ]);
    }

    public function test_returns_an_active_result_for_a_valid_token(): void
    {
        Http::fake([
            'sso.example.test/*' => Http::response([
                'active' => true,
                'scope' => 'kb:read kb:write',
                'aud' => 'workspace/7',
                'client_id' => 'svc-acme-integration',
            ], 200),
        ]);

        $client = new HttpIntrospectionClient();
        $result = $client->introspect('some-bearer-token');

        $this->assertTrue($result->active);
        $this->assertSame(['kb:read', 'kb:write'], $result->scopes);
        $this->assertSame(['workspace/7'], $result->audiences);
        $this->assertSame('svc-acme-integration', $result->clientId);
    }

    public function test_returns_inactive_when_grandpasson_says_the_token_is_inactive(): void
    {
        Http::fake([
            'sso.example.test/*' => Http::response(['active' => false], 200),
        ]);

        $result = (new HttpIntrospectionClient())->introspect('revoked-token');

        $this->assertFalse($result->active);
    }

    public function test_returns_inactive_on_a_non_2xx_response_rather_than_throwing(): void
    {
        Http::fake([
            'sso.example.test/*' => Http::response(['error' => 'server_error'], 500),
        ]);

        $result = (new HttpIntrospectionClient())->introspect('any-token');

        $this->assertFalse($result->active);
    }

    public function test_accepts_a_list_shaped_aud_claim(): void
    {
        Http::fake([
            'sso.example.test/*' => Http::response([
                'active' => true,
                'scope' => 'kb:read',
                'aud' => ['workspace/7'],
                'client_id' => 'svc-acme-integration',
            ], 200),
        ]);

        $result = (new HttpIntrospectionClient())->introspect('some-bearer-token');

        $this->assertSame(['workspace/7'], $result->audiences);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh artisan test --filter=HttpIntrospectionClientTest`
Expected: FAIL (class `App\Domain\Auth\GrandpaSson\HttpIntrospectionClient` not found)

- [ ] **Step 3: Write the implementation**

```php
<?php
// app/Domain/Auth/GrandpaSson/IntrospectionClientInterface.php

namespace App\Domain\Auth\GrandpaSson;

interface IntrospectionClientInterface
{
    public function introspect(string $token): IntrospectionResult;
}
```

```php
<?php
// app/Domain/Auth/GrandpaSson/HttpIntrospectionClient.php

namespace App\Domain\Auth\GrandpaSson;

use Illuminate\Support\Facades\Http;

/**
 * Calls GrandpaSSOn's existing /oauth/introspect endpoint (RFC 7662-style).
 * Ported from TaskConnect's identical integration
 * (taskconnect/app/Application/GrandpaSson/HttpIntrospectionClient.php) — same
 * shared identity broker, same introspection contract.
 */
final class HttpIntrospectionClient implements IntrospectionClientInterface
{
    public function introspect(string $token): IntrospectionResult
    {
        $url = (string) config('jotter.grandpasson_resource.introspect_url');
        $clientId = (string) config('jotter.grandpasson_resource.client_id');
        $clientSecret = (string) config('jotter.grandpasson_resource.client_secret');

        if ($url === '') {
            return new IntrospectionResult(active: false);
        }

        $request = Http::asForm()->timeout(10);
        if ($clientId !== '' && $clientSecret !== '') {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        }

        $response = $request->post($url, ['token' => $token]);

        if (! $response->successful()) {
            return new IntrospectionResult(active: false);
        }

        $active = (bool) $response->json('active', false);
        $scopeRaw = $response->json('scope', '');
        $scopes = is_string($scopeRaw)
            ? array_values(array_filter(explode(' ', $scopeRaw)))
            : (is_array($scopeRaw) ? array_map('strval', $scopeRaw) : []);

        $audRaw = $response->json('aud', []);
        $audiences = match (true) {
            is_string($audRaw) && $audRaw !== '' => [$audRaw],
            is_array($audRaw) => array_map('strval', $audRaw),
            default => [],
        };

        return new IntrospectionResult(
            active: $active,
            scopes: $scopes,
            audiences: $audiences,
            clientId: $response->json('client_id'),
            subject: $response->json('sub'),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh artisan test --filter=HttpIntrospectionClientTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Auth/GrandpaSson/IntrospectionClientInterface.php app/Domain/Auth/GrandpaSson/HttpIntrospectionClient.php tests/Feature/GrandpaSson/HttpIntrospectionClientTest.php
git commit -m "feat: add HttpIntrospectionClient for GrandpaSSOn token introspection"
```

---

### Task 4: A fake introspection client for tests

**Files:**
- Create: `tests/Support/FakeIntrospectionClient.php`

**Interfaces:**
- Consumes: `IntrospectionClientInterface`, `IntrospectionResult` (Tasks 2-3).
- Produces:
  ```php
  namespace Tests\Support;

  final class FakeIntrospectionClient implements \App\Domain\Auth\GrandpaSson\IntrospectionClientInterface
  {
      public function __construct(private readonly \App\Domain\Auth\GrandpaSson\IntrospectionResult $result) {}
      public function introspect(string $token): \App\Domain\Auth\GrandpaSson\IntrospectionResult;
  }
  ```
  Consumed by Task 5 and Task 6's tests (constructing `GrandpaSSOnIdentityProvider` with a canned result instead of making real HTTP calls).

This is a test double, not behavior under test — no TDD cycle, just write it directly and verify it compiles by using it in Task 5's tests.

- [ ] **Step 1: Write the fake**

```php
<?php
// tests/Support/FakeIntrospectionClient.php

namespace Tests\Support;

use App\Domain\Auth\GrandpaSson\IntrospectionClientInterface;
use App\Domain\Auth\GrandpaSson\IntrospectionResult;

final class FakeIntrospectionClient implements IntrospectionClientInterface
{
    public function __construct(private readonly IntrospectionResult $result)
    {
    }

    public function introspect(string $token): IntrospectionResult
    {
        return $this->result;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add tests/Support/FakeIntrospectionClient.php
git commit -m "test: add FakeIntrospectionClient test double"
```

---

### Task 5: `GrandpaSSOnIdentityProvider::resolveIdentity()` — service-token branch

**Files:**
- Modify: `app/Domain/Auth/Providers/GrandpaSSOnIdentityProvider.php`
- Test: `tests/Unit/GrandpaSson/GrandpaSSOnServiceTokenIdentityTest.php`

**Interfaces:**
- Consumes: `IntrospectionClientInterface`/`IntrospectionResult` (Tasks 2-3), `FakeIntrospectionClient` (Task 4), `config('jotter.grandpasson_resource.inbound_enabled')` (Task 1), `AuthenticatedSubject` (existing — `subjectId: string, email: string, name: string, isAdmin: bool = false, user: ?User = null, attributes: array = []`).
- Produces: for an active introspection result, `AuthenticatedSubject` with `attributes['auth_method'] === 'grandpasson_service_token'`, `attributes['scopes']` (list<string>), `attributes['audiences']` (list<string>), `user === null`. Consumed by Task 6 (authorization checks read these same `attributes` keys).

The constructor gets a new parameter with a default value (`new HttpIntrospectionClient()`), matching this repo's existing convention for optional collaborator injection (e.g. `NoteChecklistItemController`'s `AuditRecorder $auditRecorder = new AuditRecorder`) — so the one existing call site (`AppServiceProvider::register()`, `new \App\Domain\Auth\Providers\GrandpaSSOnIdentityProvider`) needs no change.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/GrandpaSson/GrandpaSSOnServiceTokenIdentityTest.php

namespace Tests\Unit\GrandpaSson;

use App\Domain\Auth\GrandpaSson\IntrospectionResult;
use App\Domain\Auth\Providers\GrandpaSSOnIdentityProvider;
use Illuminate\Http\Request;
use Tests\Support\FakeIntrospectionClient;
use Tests\TestCase;

final class GrandpaSSOnServiceTokenIdentityTest extends TestCase
{
    public function test_resolves_a_synthetic_subject_for_an_active_service_token(): void
    {
        config(['jotter.grandpasson_resource.inbound_enabled' => true]);

        $introspection = new FakeIntrospectionClient(new IntrospectionResult(
            active: true,
            scopes: ['kb:read', 'kb:write'],
            audiences: ['workspace/7'],
            clientId: 'svc-acme-integration',
        ));
        $provider = new GrandpaSSOnIdentityProvider($introspection);

        $request = Request::create('/api/workspaces/7/notes', 'GET');
        $request->headers->set('Authorization', 'Bearer some-token');

        $subject = $provider->resolveIdentity($request);

        $this->assertNotNull($subject);
        $this->assertSame('service:svc-acme-integration', $subject->subjectId);
        $this->assertFalse($subject->isAdmin);
        $this->assertNull($subject->user);
        $this->assertSame('grandpasson_service_token', $subject->attributes['auth_method']);
        $this->assertSame(['kb:read', 'kb:write'], $subject->attributes['scopes']);
        $this->assertSame(['workspace/7'], $subject->attributes['audiences']);
    }

    public function test_returns_null_for_an_inactive_token(): void
    {
        config(['jotter.grandpasson_resource.inbound_enabled' => true]);

        $introspection = new FakeIntrospectionClient(new IntrospectionResult(active: false));
        $provider = new GrandpaSSOnIdentityProvider($introspection);

        $request = Request::create('/api/workspaces/7/notes', 'GET');
        $request->headers->set('Authorization', 'Bearer revoked-token');

        $this->assertNull($provider->resolveIdentity($request));
    }

    public function test_bearer_token_is_ignored_entirely_when_inbound_is_disabled(): void
    {
        config(['jotter.grandpasson_resource.inbound_enabled' => false]);

        $introspection = new FakeIntrospectionClient(new IntrospectionResult(
            active: true,
            scopes: ['kb:read'],
            audiences: ['workspace/7'],
            clientId: 'svc-acme-integration',
        ));
        $provider = new GrandpaSSOnIdentityProvider($introspection);

        $request = Request::create('/api/workspaces/7/notes', 'GET');
        $request->headers->set('Authorization', 'Bearer some-token');

        // No cookie, no session either — falls through to null, same as
        // if this feature didn't exist at all.
        $this->assertNull($provider->resolveIdentity($request));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh artisan test --filter=GrandpaSSOnServiceTokenIdentityTest`
Expected: FAIL — constructor doesn't accept an argument (`Too many arguments to function ...::__construct()`)

- [ ] **Step 3: Modify `GrandpaSSOnIdentityProvider`**

In `app/Domain/Auth/Providers/GrandpaSSOnIdentityProvider.php`, add imports:

```php
use App\Domain\Auth\GrandpaSson\HttpIntrospectionClient;
use App\Domain\Auth\GrandpaSson\IntrospectionClientInterface;
```

Change the constructor from:

```php
    private readonly LocalIdentityProvider $localProvider;

    public function __construct()
    {
        $this->localProvider = new LocalIdentityProvider();
    }
```

to:

```php
    private readonly LocalIdentityProvider $localProvider;

    public function __construct(
        private readonly IntrospectionClientInterface $introspection = new HttpIntrospectionClient()
    ) {
        $this->localProvider = new LocalIdentityProvider();
    }
```

Change `resolveIdentity()` from:

```php
    public function resolveIdentity(Request $request): ?AuthenticatedSubject
    {
        // 1. Check GrandpaSSOn AUTHSESSID session cookie
        $authSessId = $request->cookie('AUTHSESSID') ?? ($_COOKIE['AUTHSESSID'] ?? null);

        if ($authSessId) {
            $subject = $this->resolveFromGrandpaSsonSession((string) $authSessId);
            if ($subject !== null) {
                return $subject;
            }
        }

        // 2. Check active web session if authenticated via login endpoint
```

to:

```php
    public function resolveIdentity(Request $request): ?AuthenticatedSubject
    {
        // 1. Check Bearer GrandpaSSOn service token (client_credentials flow) —
        // see docs/superpowers/specs/2026-08-05-grandpasson-service-tokens-design.md
        if (config('jotter.grandpasson_resource.inbound_enabled', false)) {
            $bearerToken = $request->bearerToken();
            if ($bearerToken) {
                $subject = $this->resolveFromServiceToken($bearerToken);
                if ($subject !== null) {
                    return $subject;
                }
            }
        }

        // 2. Check GrandpaSSOn AUTHSESSID session cookie
        $authSessId = $request->cookie('AUTHSESSID') ?? ($_COOKIE['AUTHSESSID'] ?? null);

        if ($authSessId) {
            $subject = $this->resolveFromGrandpaSsonSession((string) $authSessId);
            if ($subject !== null) {
                return $subject;
            }
        }

        // 3. Check active web session if authenticated via login endpoint
```

(only the numbered comments shift — no other line in that section changes).

Add a new private method, right before `resolveFromGrandpaSsonSession`:

```php
    /**
     * A GrandpaSSOn client_credentials service token has no User row — it
     * represents a machine caller scoped to exactly the workspace(s) named
     * in its `aud` claim (see isAuthorizedForWorkspace() below), not a
     * Membership row. attributes['auth_method'] distinguishes this subject
     * type everywhere else in this class and in AuthorizeWorkspaceAccess.
     */
    private function resolveFromServiceToken(string $token): ?AuthenticatedSubject
    {
        $result = $this->introspection->introspect($token);
        if (! $result->active || $result->clientId === null) {
            return null;
        }

        return new AuthenticatedSubject(
            subjectId: "service:{$result->clientId}",
            email: '',
            name: "Service client {$result->clientId}",
            isAdmin: false,
            user: null,
            attributes: [
                'auth_method' => 'grandpasson_service_token',
                'scopes' => $result->scopes,
                'audiences' => $result->audiences,
            ],
        );
    }

```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh artisan test --filter=GrandpaSSOnServiceTokenIdentityTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Run the existing GrandpaSSOn tests to confirm no regression**

Run: `./scripts/jt.sh artisan test --filter=GrandpaSSOnAdminEscalationTest`
Expected: PASS, unchanged (this test exercises the AUTHSESSID cookie path, which now runs as branch 2 instead of branch 1 — behavior must be identical since `inbound_enabled` defaults `false`)

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Auth/Providers/GrandpaSSOnIdentityProvider.php tests/Unit/GrandpaSson/GrandpaSSOnServiceTokenIdentityTest.php
git commit -m "feat: resolve GrandpaSSOn service tokens in GrandpaSSOnIdentityProvider"
```

---

### Task 6: Workspace authorization for service tokens

**Files:**
- Modify: `app/Domain/Auth/Providers/GrandpaSSOnIdentityProvider.php`
- Test: `tests/Unit/GrandpaSson/GrandpaSSOnServiceTokenAuthorizationTest.php`

**Interfaces:**
- Consumes: `attributes['auth_method']`/`attributes['audiences']` on `AuthenticatedSubject` (Task 5's shape).
- Produces: `isAuthorizedForWorkspace()`, `accessibleWorkspaceIds()`, `accessibleTenantIds()` all correctly handle a service-token subject. Consumed by Task 7 (`AuthorizeWorkspaceAccess` middleware calls `isAuthorizedForWorkspace()`, already wired — this task makes it return the right answer for service tokens instead of falling through to the `Membership` lookup, which would always return `false` for a subject with no matching rows).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/GrandpaSson/GrandpaSSOnServiceTokenAuthorizationTest.php

namespace Tests\Unit\GrandpaSson;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Providers\GrandpaSSOnIdentityProvider;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GrandpaSSOnServiceTokenAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function serviceSubject(array $audiences): AuthenticatedSubject
    {
        return new AuthenticatedSubject(
            subjectId: 'service:svc-acme-integration',
            email: '',
            name: 'Service client svc-acme-integration',
            isAdmin: false,
            user: null,
            attributes: [
                'auth_method' => 'grandpasson_service_token',
                'scopes' => ['kb:read', 'kb:write'],
                'audiences' => $audiences,
            ],
        );
    }

    public function test_authorized_for_a_workspace_named_in_the_audience(): void
    {
        $provider = new GrandpaSSOnIdentityProvider();
        $subject = $this->serviceSubject(['workspace/7']);

        $this->assertTrue($provider->isAuthorizedForWorkspace($subject, 7));
    }

    public function test_not_authorized_for_a_workspace_not_named_in_the_audience(): void
    {
        $provider = new GrandpaSSOnIdentityProvider();
        $subject = $this->serviceSubject(['workspace/7']);

        $this->assertFalse($provider->isAuthorizedForWorkspace($subject, 8));
    }

    public function test_accessible_workspace_ids_are_parsed_from_every_audience(): void
    {
        $provider = new GrandpaSSOnIdentityProvider();
        $subject = $this->serviceSubject(['workspace/7', 'workspace/9']);

        $this->assertSame([7, 9], $provider->accessibleWorkspaceIds($subject));
    }

    public function test_accessible_tenant_ids_resolve_via_the_accessible_workspaces(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'main',
            'name' => 'Main',
            'vault_path' => storage_path('app/vaults/svc_test'),
        ]);

        $provider = new GrandpaSSOnIdentityProvider();
        $subject = $this->serviceSubject(["workspace/{$workspace->id}"]);

        $this->assertSame([$tenant->id], $provider->accessibleTenantIds($subject));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh artisan test --filter=GrandpaSSOnServiceTokenAuthorizationTest`
Expected: FAIL — `isAuthorizedForWorkspace` returns `false` for workspace 7 (falls through to `localProvider`, which finds no `Membership` row); `accessibleWorkspaceIds`/`accessibleTenantIds` return `[]`/`null` instead of the parsed lists.

- [ ] **Step 3: Modify the three authorization methods**

In `app/Domain/Auth/Providers/GrandpaSSOnIdentityProvider.php`, add `use App\Models\Workspace;` to the imports, then change:

```php
    public function isAuthorizedForWorkspace(AuthenticatedSubject $subject, int $workspaceId): bool
    {
        if ($subject->isAdmin) {
            return true;
        }

        return $this->localProvider->isAuthorizedForWorkspace($subject, $workspaceId);
    }

    public function accessibleWorkspaceIds(AuthenticatedSubject $subject): ?array
    {
        if ($subject->isAdmin) {
            return null;
        }

        return $this->localProvider->accessibleWorkspaceIds($subject);
    }

    public function accessibleTenantIds(AuthenticatedSubject $subject): ?array
    {
        if ($subject->isAdmin) {
            return null;
        }

        return $this->localProvider->accessibleTenantIds($subject);
    }
```

to:

```php
    public function isAuthorizedForWorkspace(AuthenticatedSubject $subject, int $workspaceId): bool
    {
        if ($this->isServiceToken($subject)) {
            return in_array("workspace/{$workspaceId}", $subject->attributes['audiences'], true);
        }

        if ($subject->isAdmin) {
            return true;
        }

        return $this->localProvider->isAuthorizedForWorkspace($subject, $workspaceId);
    }

    public function accessibleWorkspaceIds(AuthenticatedSubject $subject): ?array
    {
        if ($this->isServiceToken($subject)) {
            return $this->serviceTokenWorkspaceIds($subject);
        }

        if ($subject->isAdmin) {
            return null;
        }

        return $this->localProvider->accessibleWorkspaceIds($subject);
    }

    public function accessibleTenantIds(AuthenticatedSubject $subject): ?array
    {
        if ($this->isServiceToken($subject)) {
            return Workspace::query()
                ->whereIn('id', $this->serviceTokenWorkspaceIds($subject))
                ->pluck('tenant_id')
                ->unique()
                ->values()
                ->all();
        }

        if ($subject->isAdmin) {
            return null;
        }

        return $this->localProvider->accessibleTenantIds($subject);
    }

    private function isServiceToken(AuthenticatedSubject $subject): bool
    {
        return ($subject->attributes['auth_method'] ?? null) === 'grandpasson_service_token';
    }

    /**
     * @return list<int>
     */
    private function serviceTokenWorkspaceIds(AuthenticatedSubject $subject): array
    {
        $ids = [];
        foreach ($subject->attributes['audiences'] ?? [] as $audience) {
            if (preg_match('/^workspace\/(\d+)$/', $audience, $matches)) {
                $ids[] = (int) $matches[1];
            }
        }

        return $ids;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh artisan test --filter=GrandpaSSOnServiceTokenAuthorizationTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Auth/Providers/GrandpaSSOnIdentityProvider.php tests/Unit/GrandpaSson/GrandpaSSOnServiceTokenAuthorizationTest.php
git commit -m "feat: authorize GrandpaSSOn service tokens against their aud claim"
```

---

### Task 7: Scope enforcement in `AuthorizeWorkspaceAccess`

**Files:**
- Modify: `app/Http/Middleware/AuthorizeWorkspaceAccess.php`
- Test: `tests/Feature/GrandpaSson/ServiceTokenWorkspaceAccessTest.php`

**Interfaces:**
- Consumes: everything from Tasks 5-6 — a service-token `AuthenticatedSubject` that passes `isAuthorizedForWorkspace()`.
- Produces: the middleware 403s with `{"message": "Token does not have the required scope."}` when a service token's scopes don't cover the HTTP verb. This is the final integration point — no later task consumes this.

This is a full feature test hitting a real route end-to-end, so it needs the app container to actually resolve `GrandpaSSOnIdentityProvider` with a fake introspection client. Bind the fake in the container for this test via `$this->app->instance(...)`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/GrandpaSson/ServiceTokenWorkspaceAccessTest.php

namespace Tests\Feature\GrandpaSson;

use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\GrandpaSson\IntrospectionResult;
use App\Domain\Auth\Providers\GrandpaSSOnIdentityProvider;
use App\Domain\Vault\VaultStorage;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeIntrospectionClient;
use Tests\TestCase;

final class ServiceTokenWorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkspace(): Workspace
    {
        $tenant = Tenant::create(['slug' => 'svc-test-'.uniqid(), 'name' => 'Service Token Test']);
        $vaultPath = storage_path('app/vaults/svc_workspace_'.uniqid());
        mkdir($vaultPath, 0755, true);

        return Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'svc-'.uniqid(),
            'name' => 'Service Token Workspace',
            'vault_path' => $vaultPath,
        ]);
    }

    private function bindProvider(IntrospectionResult $result): void
    {
        config([
            'jotter.auth_provider' => 'grandpasson',
            'jotter.grandpasson_resource.inbound_enabled' => true,
        ]);

        $this->app->singleton(IdentityProvider::class, fn () => new GrandpaSSOnIdentityProvider(
            new FakeIntrospectionClient($result)
        ));
    }

    public function test_read_scope_allows_a_get_request(): void
    {
        $workspace = $this->makeWorkspace();
        $this->bindProvider(new IntrospectionResult(
            active: true,
            scopes: ['kb:read'],
            audiences: ["workspace/{$workspace->id}"],
            clientId: 'svc-acme',
        ));

        $response = $this->withHeader('Authorization', 'Bearer token')
            ->getJson("/api/workspaces/{$workspace->id}/notes");

        $response->assertOk();
    }

    public function test_read_only_scope_rejects_a_write_request(): void
    {
        $workspace = $this->makeWorkspace();
        $this->bindProvider(new IntrospectionResult(
            active: true,
            scopes: ['kb:read'],
            audiences: ["workspace/{$workspace->id}"],
            clientId: 'svc-acme',
        ));

        $response = $this->withHeader('Authorization', 'Bearer token')
            ->postJson("/api/workspaces/{$workspace->id}/notes", [
                'path' => 'test.md',
                'content' => '# Test',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Token does not have the required scope.');
    }

    public function test_write_scope_allows_a_write_request(): void
    {
        $workspace = $this->makeWorkspace();
        $this->bindProvider(new IntrospectionResult(
            active: true,
            scopes: ['kb:read', 'kb:write'],
            audiences: ["workspace/{$workspace->id}"],
            clientId: 'svc-acme',
        ));

        $response = $this->withHeader('Authorization', 'Bearer token')
            ->postJson("/api/workspaces/{$workspace->id}/notes", [
                'path' => 'test.md',
                'content' => '# Test',
            ]);

        $response->assertCreated();
    }

    public function test_a_token_scoped_to_a_different_workspace_is_forbidden(): void
    {
        $workspace = $this->makeWorkspace();
        $otherWorkspace = $this->makeWorkspace();
        $this->bindProvider(new IntrospectionResult(
            active: true,
            scopes: ['kb:read', 'kb:write'],
            audiences: ["workspace/{$otherWorkspace->id}"],
            clientId: 'svc-acme',
        ));

        $response = $this->withHeader('Authorization', 'Bearer token')
            ->getJson("/api/workspaces/{$workspace->id}/notes");

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden workspace access.');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh artisan test --filter=ServiceTokenWorkspaceAccessTest`
Expected: `test_read_scope_allows_a_get_request` and `test_write_scope_allows_a_write_request` and `test_a_token_scoped_to_a_different_workspace_is_forbidden` PASS already (Tasks 5-6 cover them); `test_read_only_scope_rejects_a_write_request` FAILS — the middleware has no scope check yet, so the POST succeeds (201) instead of 403.

- [ ] **Step 3: Modify `AuthorizeWorkspaceAccess`**

In `app/Http/Middleware/AuthorizeWorkspaceAccess.php`, the current body (in full) is:

```php
    public function handle(Request $request, Closure $next): Response
    {
        if (config('jotter.auth_bypass', false)) {
            return $next($request);
        }

        $subject = $this->identityProvider->resolveIdentity($request);

        if (! $subject) {
            (new \App\Domain\Audit\AuditRecorder)->record(
                \App\Domain\Audit\AuditEvent::AUTH_UNAUTHORIZED,
                null,
                null,
                null,
                [
                    'reason' => 'unauthenticated',
                    'path' => $request->path(),
                    'method' => $request->method(),
                ]
            );

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $workspaceParam = $request->route('workspace');
        if ($workspaceParam !== null) {
            $workspaceId = null;
            $tenantId = null;

            if ($workspaceParam instanceof Workspace) {
                $workspaceId = $workspaceParam->id;
                $tenantId = $workspaceParam->tenant_id;
            } elseif (is_numeric($workspaceParam)) {
                $workspaceId = (int) $workspaceParam;
                $workspace = Workspace::query()->find($workspaceId);
                $tenantId = $workspace?->tenant_id;
            }

            if ($workspaceId && ! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspaceId)) {
                (new \App\Domain\Audit\AuditRecorder)->record(
                    \App\Domain\Audit\AuditEvent::AUTH_FORBIDDEN,
                    $tenantId,
                    $workspaceId,
                    $subject->subjectId,
                    [
                        'reason' => 'unauthorized_workspace',
                        'path' => $request->path(),
                        'method' => $request->method(),
                    ]
                );

                return response()->json(['message' => 'Forbidden workspace access.'], 403);
            }

            if (($subject->attributes['auth_method'] ?? null) === 'grandpasson_service_token') {
                $requiredScope = in_array($request->method(), ['GET', 'HEAD'], true)
                    ? 'kb:read'
                    : 'kb:write';

                if (! in_array($requiredScope, $subject->attributes['scopes'], true)) {
                    (new \App\Domain\Audit\AuditRecorder)->record(
                        \App\Domain\Audit\AuditEvent::AUTH_FORBIDDEN,
                        $tenantId,
                        $workspaceId,
                        $subject->subjectId,
                        [
                            'reason' => 'insufficient_scope',
                            'required_scope' => $requiredScope,
                            'scopes' => $subject->attributes['scopes'],
                            'path' => $request->path(),
                            'method' => $request->method(),
                        ]
                    );

                    return response()->json(['message' => 'Token does not have the required scope.'], 403);
                }
            }
        }

        $request->attributes->set('authenticated_subject', $subject);

        return $next($request);
    }
```

(the new block — the `if (($subject->attributes['auth_method'] ?? null) === ...) { ... }` — is inserted right after the existing `isAuthorizedForWorkspace` 403 block, still inside the `if ($workspaceParam !== null)` block, before its closing brace).

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh artisan test --filter=ServiceTokenWorkspaceAccessTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/AuthorizeWorkspaceAccess.php tests/Feature/GrandpaSson/ServiceTokenWorkspaceAccessTest.php
git commit -m "feat: enforce kb:read/kb:write scope for GrandpaSSOn service tokens"
```

---

### Task 8: `.env.example` documentation + full regression

**Files:**
- Modify: `.env.example` (if this repo has one — check with `ls .env.example` first; if absent, skip the file edit and only do the regression run)

**Interfaces:** None — this task documents config added in Task 1 and proves the whole feature is safe by default.

- [ ] **Step 1: Document the new env vars**

If `.env.example` exists, add (near any existing `JOTTER_SSO_*` vars):

```
JOTTER_GRANDPASSON_INBOUND_ENABLED=false
JOTTER_GRANDPASSON_INTROSPECT_URL=
JOTTER_GRANDPASSON_CLIENT_ID=
JOTTER_GRANDPASSON_CLIENT_SECRET=
```

- [ ] **Step 2: Run the full backend suite**

Run: `./scripts/jt.sh artisan test`
Expected: every existing test still passes, plus all new tests from Tasks 2-7. `inbound_enabled` defaults to `false` in every test that doesn't explicitly set it — this is what proves zero regression to existing cookie/session/`MachineToken`-under-`local`-provider/admin-bypass behavior.

- [ ] **Step 3: Commit (only if `.env.example` was changed)**

```bash
git add .env.example
git commit -m "docs: document JOTTER_GRANDPASSON_* env vars"
```

---

## Self-Review Notes

- **Spec coverage:** Decisions section → Tasks 1 (audience/scope constants used throughout), 5-6 (aud-based authorization), Global Constraints (`inbound_enabled` default). Architecture diagram → Tasks 5 (resolveIdentity branch), 7 (middleware scope check). Components 1-5 → Tasks 2, 3, 5, 6, 7 respectively, one-to-one. Data flow/error handling → covered by Task 3's non-2xx/inactive tests, Task 5's disabled-flag test, Task 7's wrong-audience/wrong-scope tests. Provisioning section is operational only (no GrandpaSSOn code), correctly has no task. Non-goals are respected: no `MachineToken` changes, no new scopes, no caching, no admin UI.
- **No placeholders:** every step has real, complete code — no TBDs.
- **Type consistency:** `IntrospectionResult` constructor/field names (Task 2) match every later usage (Tasks 3, 5, 6, 7) exactly — `active`, `scopes`, `audiences`, `clientId`, `subject`. `attributes['auth_method']`/`['scopes']`/`['audiences']` keys are identical across Tasks 5, 6, 7.
