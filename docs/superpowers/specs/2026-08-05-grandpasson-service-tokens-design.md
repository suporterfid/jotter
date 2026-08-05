# GrandpaSSOn Service Tokens for Jotter Workspace Access — Design

Date: 2026-08-05
Repos: `/home/ubuntu/projects/web/iroh/jotter` (this repo, changes live here),
`/home/ubuntu/projects/web/iroh/grandpasson` (identity broker, no code
changes — operational/provisioning only).
Source: user request — "where should we manage API keys for systemic
REST API integrations with Jotter, on Jotter itself or on GrandpaSSOn?"
(answer: GrandpaSSOn), followed by "wire GrandpaSSOn tokens to
workspace access in Jotter."

## Problem

Jotter has two existing, disconnected authentication mechanisms:

1. **`MachineToken`** (`app/Models/MachineToken.php`,
   `LocalIdentityProvider::resolveIdentity()` lines 20-40): a locally
   issued bearer token whose `subject_id` points at a real `User` row.
   It inherits that user's `Membership` rows, so workspace
   authorization "just works" via the existing
   `isAuthorizedForWorkspace()` path. But production runs
   `JOTTER_AUTH_PROVIDER=grandpasson`, and
   `GrandpaSSOnIdentityProvider::resolveIdentity()`
   (`app/Domain/Auth/Providers/GrandpaSSOnIdentityProvider.php:22-45`)
   never delegates to `LocalIdentityProvider::resolveIdentity()` — it
   only checks the AUTHSESSID cookie and the web session. **The
   `MachineToken` bearer path is currently unreachable in production.**

2. **GrandpaSSOn's `client_credentials` OAuth flow**
   (`grandpasson/app/Http/Controllers/OAuthTokenController.php`,
   `ServiceClient`/`AccessToken` domain objects) already issues
   scoped, audience-bound tokens for machine callers, and already has
   an introspection endpoint
   (`grandpasson/app/Http/Controllers/OAuthIntrospectController.php`).
   TaskConnect (sibling app on the same host) already consumes this
   end-to-end (`taskconnect/app/Application/GrandpaSson/HttpIntrospectionClient.php`,
   `taskconnect/app/Http/Middleware/EnforceGrandpaSsonWorkspaceAud.php`).
   **Jotter has zero code that talks to this flow.**

A GrandpaSSOn service token's caller is a machine, not a human — it
has no `User` row and no `Membership` row. `isAuthorizedForWorkspace()`
(`LocalIdentityProvider.php:167-190`) resolves access purely by
looking up `Membership` rows for the subject's ids, so a pure machine
caller fails that check today regardless of where its token was
issued. This is the actual blocker: wiring requires new authorization
logic in Jotter, not just new token-parsing code.

## Decisions (from brainstorming)

- **One `ServiceClient` per workspace-integration.** GrandpaSSOn's
  `client_credentials` flow issues a single, fixed audience per client
  (set at registration — `OAuthTokenController::clientCredentials()`
  lines ~108-122 explicitly rejects a caller-chosen audience that
  differs from the client's `defaultAudience`). So the natural mapping
  is: every integration that needs access to workspace `N` gets its
  own `client_id`/`client_secret` pair, registered with
  `defaultAudience = "workspace/{N}"` — GrandpaSSOn's own existing
  audience convention (`app/Domain/ScopeVocabulary.php`'s doc comment:
  "Workspace narrowing uses `aud` (e.g. workspace/<id>), not
  per-workspace scopes"; TaskConnect already uses `workspace/<uuid>`
  the same way). Jotter workspaces don't have a UUID, only a numeric
  `id`, so the audience is `workspace/{numeric id}`.
- **Scopes: `kb:read` and `kb:write` — already exist.**
  `grandpasson/app/Domain/ScopeVocabulary.php` already defines
  `KB_READ`/`KB_WRITE` ("KB" = knowledge base, i.e. Jotter) as part of
  `machineScopes()`. No new scope names needed; reuse these instead of
  inventing `jotter:read`/`jotter:write`.
- **GrandpaSSOn repo gets zero code changes**, confirmed with real
  scope/audience conventions above. `client:create-service`
  (`grandpasson/app/Infrastructure/Admin/AdminCommandRunner.php`,
  exercised by `tests/Integration/AdminCommandRunnerTest.php:83-176`)
  already creates `ServiceClient` rows with a name, `--scopes`,
  `--aud`, and optional `--client-id`. Both Jotter's own
  resource-server credential and every per-integration client are
  provisioned with this existing command, using scopes/audience it
  already recognizes — no new GrandpaSSOn feature required.
- **Out of scope, explicitly:** fixing `MachineToken`'s dead bearer
  path under the `grandpasson` provider. Related, but a separate
  concern — bundling it in would blur what this change is actually
  responsible for.

## Architecture

```
Integration client                Jotter                         GrandpaSSOn
-------------------                ------                         -----------
POST /workspaces/N/notes
Authorization: Bearer <token>  --> AuthorizeWorkspaceAccess mw
                                    -> GrandpaSSOnIdentityProvider
                                       ::resolveIdentity()
                                       (Bearer branch)
                                    -> IntrospectionClient::introspect()
                                       (Basic auth: Jotter's own
                                        ServiceClient credentials) --> POST /oauth/introspect
                                                                        (OAuthIntrospectController)
                                    <-- {active, scope, aud, client_id} --
                                    -> build synthetic AuthenticatedSubject
                                    -> isAuthorizedForWorkspace(subject, N)
                                       (checks aud contains
                                        "workspace/N")
                                    -> middleware: method GET => require
                                       kb:read; else kb:write
                                    -> route handler runs unchanged
```

Two identities never mix in one request: a request either carries an
AUTHSESSID cookie / web session (human) or a `Bearer` token (machine).
The new branch is additive to `GrandpaSSOnIdentityProvider`, not a
replacement for the existing cookie/session branches.

## Components

### 1. `IntrospectionResult` (new, `app/Domain/Auth/GrandpaSson/IntrospectionResult.php`)

Direct port of TaskConnect's
`app/Application/GrandpaSson/IntrospectionResult.php`. Read-only value
object:

```php
final readonly class IntrospectionResult
{
    /**
     * @param list<string> $scopes
     * @param list<string> $audiences
     */
    public function __construct(
        public bool $active,
        public array $scopes = [],
        public array $audiences = [],
        public ?string $clientId = null,
        public ?string $subject = null,
    ) {}

    public function hasScope(string $scope): bool { ... }
    public function audienceIncludesWorkspace(int $workspaceId): bool
    {
        // checks for "workspace/{$workspaceId}" in $this->audiences
    }
}
```

Jotter reuses GrandpaSSOn's existing `workspace/<id>` audience
convention (see Decisions above) with its own numeric workspace id in
place of TaskConnect's UUID, so `audienceIncludesWorkspace(int
$workspaceId)` checks for the literal string `"workspace/{$workspaceId}"`
— structurally the same check as TaskConnect's
`audienceIncludes(string $workspacePublicId)`, just typed to an `int`
since Jotter workspace ids are always numeric.

### 2. `IntrospectionClientInterface` + `HttpIntrospectionClient` (new, `app/Domain/Auth/GrandpaSson/`)

Direct port of TaskConnect's
`HttpIntrospectionClient`/`IntrospectionClientInterface`. Calls
`config('jotter.grandpasson_resource.introspect_url')` with HTTP Basic
auth using Jotter's own resource-server `client_id`/`client_secret`
(`config('jotter.grandpasson_resource.client_id'/'client_secret')`),
posts `token=<bearer token>`, parses the JSON response
(`active`/`scope`/`aud`/`client_id`) into an `IntrospectionResult`. On
any non-2xx response or network failure, returns
`IntrospectionResult(active: false)` — introspection failure never
throws, it just means "not authenticated."

Bound in `AppServiceProvider::register()` as a singleton, same pattern
already used there for `IdentityProvider` and `JobDispatcher`.

### 3. `GrandpaSSOnIdentityProvider` changes (`app/Domain/Auth/Providers/GrandpaSSOnIdentityProvider.php`)

Constructor gains `IntrospectionClientInterface $introspection`
(resolved from the container, not `new`'d directly — the class already
`new`'s its own `LocalIdentityProvider` today, but the introspection
client needs to be swappable in tests, so it's injected).

`resolveIdentity()` gains a new first branch, checked before the
AUTHSESSID cookie:

```php
public function resolveIdentity(Request $request): ?AuthenticatedSubject
{
    if (config('jotter.grandpasson_resource.inbound_enabled', false)) {
        $bearer = $request->bearerToken();
        if ($bearer) {
            $subject = $this->resolveFromServiceToken($bearer);
            if ($subject !== null) {
                return $subject;
            }
        }
    }

    // ... existing AUTHSESSID / web-session branches, unchanged
}

private function resolveFromServiceToken(string $token): ?AuthenticatedSubject
{
    $result = $this->introspection->introspect($token);
    if (! $result->active) {
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

`isAuthorizedForWorkspace()`, `accessibleWorkspaceIds()`,
`accessibleTenantIds()` each gain a branch for
`$subject->attributes['auth_method'] === 'grandpasson_service_token'`,
checked *before* falling through to the existing admin/local-provider
logic:

- `isAuthorizedForWorkspace`: true iff
  `IntrospectionResult`-derived audiences (re-checked via the same
  `audienceIncludesWorkspace` logic, applied to
  `$subject->attributes['audiences']`) contain
  `"workspace/{$workspaceId}"`.
- `accessibleWorkspaceIds`: parse workspace ids out of every
  `workspace/{id}` audience string; return that list (never `null` —
  a service token is never unrestricted).
- `accessibleTenantIds`: resolve each accessible workspace's
  `tenant_id` via `Workspace::query()->whereIn('id', ...)->pluck('tenant_id')`,
  deduplicated.

### 4. `AuthorizeWorkspaceAccess` middleware change (`app/Http/Middleware/AuthorizeWorkspaceAccess.php`)

After the existing `isAuthorizedForWorkspace` check passes (i.e.,
right before `$request->attributes->set('authenticated_subject', ...)`),
add scope enforcement for service-token subjects only:

```php
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
```

This is the single chokepoint for every workspace-scoped route (the
whole `Route::middleware('workspace.authorization')->group(...)` block
in `routes/api.php`), so no controller needs to know service tokens
exist at all.

### 5. Config (`config/jotter.php`, `.env`)

New `grandpasson_resource` block, parallel to the existing `sso` block:

```php
'grandpasson_resource' => [
    'inbound_enabled' => env('JOTTER_GRANDPASSON_INBOUND_ENABLED', false),
    'introspect_url' => env('JOTTER_GRANDPASSON_INTROSPECT_URL'),
    'client_id' => env('JOTTER_GRANDPASSON_CLIENT_ID'),
    'client_secret' => env('JOTTER_GRANDPASSON_CLIENT_SECRET'),
],
```

`inbound_enabled` defaults `false` so this is inert until explicitly
turned on in an environment's `.env` — mirrors TaskConnect's
`grandpasson.inbound_enabled` flag.

## Data flow / error handling

- **No bearer token, no cookie, no session** → existing 401
  "Unauthenticated." (unchanged).
- **Bearer token present, introspection returns `active: false`**
  (expired, revoked, garbage token, or GrandpaSSOn unreachable) →
  `resolveFromServiceToken` returns `null` → falls through to the
  cookie/session branches (which will also fail for a pure API call
  with no cookie) → 401, same as today.
- **Bearer token active, but its audience doesn't cover the requested
  workspace** → `isAuthorizedForWorkspace` returns `false` → existing
  403 "Forbidden workspace access." path (already audit-logged today).
- **Bearer token active and audience matches, but scope insufficient
  for the HTTP method** → new 403 "Token does not have the required
  scope." path, audit-logged with `reason: insufficient_scope`.
- **`inbound_enabled = false`** → the whole new branch is skipped;
  behavior is 100% unchanged from today (safe default, safe rollback:
  flip the flag off).

## Provisioning (operational, no code)

Two commands run against GrandpaSSOn's existing `client:create-service`
admin CLI (`AdminCommandRunner::run('client:create-service', [$name], $flags)`,
verified signature per `grandpasson/tests/Integration/AdminCommandRunnerTest.php:83-176`:
positional arg is the display name; flags are `scopes` — comma-separated,
each must be in `ScopeVocabulary::all()` or the command throws
`InvalidArgumentException('Unknown scope(s): ...')` —, `aud`, and
optional `client-id`). No code needed in either repo:

1. Once, Jotter's own resource-server credential (used only to call
   `/oauth/introspect`; introspection itself isn't scope-gated —
   `OAuthIntrospectController::introspect()` only checks the caller
   authenticates as *some* valid `ServiceClient`, so any known scope
   satisfies the CLI's "scopes required" validation):
   ```
   client:create-service "Jotter Resource Server" --scopes=kb:read --client-id=svc-jotter
   ```

2. Per integration, one client scoped to exactly one workspace:
   ```
   client:create-service "<integration name>" --scopes=kb:read,kb:write --aud=workspace/<id> --client-id=svc-<integration>
   ```

Both commands return `client_secret` in their result (visible once).
The resulting `client_id`/`client_secret` are handed to the
integration; the integration calls GrandpaSSOn's
`/oauth/token` (`grant_type=client_credentials`) to get a bearer token,
then calls Jotter's REST API with `Authorization: Bearer <token>`.

## Testing

- `IntrospectionResult`: unit tests for `hasScope`/`audienceIncludesWorkspace`
  (empty audiences, non-matching, matching, malformed).
- `HttpIntrospectionClient`: feature test using `Http::fake()` —
  successful active response, inactive response, non-2xx response,
  network exception → all resolve to a value, never throw.
- `GrandpaSSOnIdentityProvider::resolveFromServiceToken` (via
  `resolveIdentity`): inject a fake `IntrospectionClientInterface`,
  assert the synthetic `AuthenticatedSubject` shape for an active
  token, assert `null` for an inactive one, assert the AUTHSESSID
  branch still works completely unchanged (regression).
- `isAuthorizedForWorkspace`/`accessibleWorkspaceIds`/`accessibleTenantIds`
  for a service-token subject: matching audience → true / contains id;
  non-matching → false / excludes id.
- `AuthorizeWorkspaceAccess` middleware, full feature test hitting a
  real route (e.g. `GET /workspaces/{id}/notes`) with a faked
  introspection response: read scope + GET → 200; write scope + GET →
  200 (read implied not required to also be write); read-only scope +
  POST → 403 insufficient_scope; wrong audience → 403 unauthorized
  workspace (existing path, just confirming the new subject type hits
  it correctly).
- Regression: full existing suite (cookie/session auth, `MachineToken`
  path under `auth_provider=local`, admin bypass) must stay green with
  `inbound_enabled=false` (default) and unaffected with it `true`.

## Non-goals

- Fixing `MachineToken`'s dead bearer path under `auth_provider=grandpasson`.
- Per-resource scopes (`kb:notes:write`, etc.) — two scopes only.
- Caching introspection results — TaskConnect's reference
  implementation doesn't cache either; each authenticated request
  makes one introspection call. Revisit only if this becomes a
  measured performance problem.
- A Jotter-side admin UI for managing `ServiceClient`s — that data
  lives entirely in GrandpaSSOn and is managed there.
- Any GrandpaSSOn repo code change.
