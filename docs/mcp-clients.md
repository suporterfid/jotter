# Connect AI clients to Jotter over MCP

Jotter serves the Model Context Protocol at `POST https://<host>/api/mcp`
(Streamable HTTP, JSON-RPC 2.0, JSON responses). One deployment can be the shared
**brain** of several assistants at once: Claude Code, Codex, Hermes, Claude
web/Desktop/mobile, and ChatGPT read the same notes and write into their own
`inbox/`. Protocol details and every tool are in [mcp.md](mcp.md).

There are two ways in, with the same rules on the server:

| Client | How it authenticates | Who decides the scope |
| --- | --- | --- |
| Claude Code, Codex CLI/IDE, Hermes Agent, Cursor | **Manual token** in a header (`Authorization: Bearer jt_mkt_…`) | The administrator who issues the token |
| Claude web/Desktop/mobile, ChatGPT | **OAuth 2.1** by URL (consent screen) | The person, on the consent screen |

Terminal clients use manual tokens so the restricted workspace (for example
`brain-restrito`) never travels through a browser grant.

## 0. Prepare the brain (once)

```sh
php artisan brain:init brain --locale=pt-BR
```

This writes `_brain/README.md`, `_brain/instructions.md`, `projetos/`, `decisoes/`,
`diario/`, `inbox/`, and `pessoas/` and never overwrites existing files. Edit
`_brain/instructions.md` to your rules: agents receive it in `initialize` and in
`get_brain_context`, and they can never write to `_brain/`.

For the web clients, turn OAuth on (see [deployment.md](deployment.md#oauth-for-mcp-web-clients-claude-web-chatgpt)):

```dotenv
JOTTER_OAUTH_ENABLED=true
```

## 1. Issue a manual token (terminal clients)

A token carries scopes, a workspace list, write paths, a client slug, and an expiry.
It never inherits administrator access, never reaches `/api/admin`, and note ACLs
apply to it.

| Scope | Allows |
| --- | --- |
| `mcp:read` | MCP read tools (default) |
| `mcp:write` | MCP write tools, only inside the token's write paths |
| `api:read` / `api:write` | REST API and WebDAV (for scripts or Obsidian sync, not needed for MCP) |

**Over SSH:**

```sh
php artisan mcp:token joe@example.com --name="Hermes VPS" \
  --workspace=brain --scope=mcp:read --scope=mcp:write \
  --write-path="inbox/{client}/**:create,append,update" --write-path="diario/**:append" \
  --client=hermes --expires=180d
```

**In the app:** Administration → **MCP tokens** → tenant, user, label, workspaces,
scopes (write paths are pre-filled when you tick `mcp:write`), client slug, expiry →
**Issue token**. The token is shown once with ready-to-copy snippets. Tokens owned by
an administrator must list their workspaces explicitly.

Write paths are globs relative to the vault: `**` crosses folders, `*` and `?` stay in
one folder, `{client}` is the client slug. `_brain/`, `.trash/`, `_resources/`,
`_templates/`, and `.obsidian/` are never writable.

Replace `https://brain.example.com` and `jt_mkt_…` below with your host and token.

## 2. Claude Code CLI

Example token: workspaces `brain` and `brain-restrito`, write paths
`inbox/claude-code/**` and `diario/**`, `--client=claude-code`.

```sh
claude mcp add -s user --transport http brain https://brain.example.com/api/mcp \
  --header "Authorization: Bearer ${JOTTER_MCP_TOKEN}"
```

Or a project `.mcp.json` (env expansion keeps the secret out of git):

```json
{
  "mcpServers": {
    "brain": {
      "type": "http",
      "url": "https://brain.example.com/api/mcp",
      "headers": { "Authorization": "Bearer ${JOTTER_MCP_TOKEN}" }
    }
  }
}
```

Check: `claude mcp list` shows `brain … ✔ Connected`; `/mcp` lists the tools.

## 3. Codex CLI / IDE

`~/.codex/config.toml`:

```toml
[mcp_servers.brain]
url = "https://brain.example.com/api/mcp"
bearer_token_env_var = "JOTTER_MCP_TOKEN"
tool_timeout_sec = 60.0
```

Export `JOTTER_MCP_TOKEN` in the shell that starts Codex. Use `--client=codex` and
`inbox/{client}/**` when issuing the token.

## 4. Hermes Agent

`~/.hermes/config.yaml`, with a token issued as in step 1 (`--client=hermes`):

```yaml
mcp_servers:
  brain:
    url: "https://brain.example.com/api/mcp"
    headers:
      Authorization: "Bearer jt_mkt_…"
    tools:
      include: [get_brain_context, search_notes, read_note, list_recent_changes, append_to_note, create_note]
```

In Hermes's end-of-task skill, call `append_to_note` on `diario/AAAA-MM-DD.md` with
`heading: "Hermes"`. Hermes keeps its own memory (`MEMORY.md`, `USER.md`) local; what
other agents must see goes into the brain.

## 5. Claude web, Desktop, and mobile (OAuth)

1. Settings → **Connectors** → **Add custom connector**.
2. URL `https://brain.example.com/api/mcp` → **Connect**.
3. Sign in to Jotter if asked; on the consent screen tick **only `brain`**, decide
   whether to allow writing (paths pre-filled with `inbox/{client}/**` and
   `diario/**`), keep the client slug (`claude`), choose a validity → **Allow**.

Claude's callback `https://claude.ai/api/mcp/auth_callback` is in the default
redirect allowlist. Revoke under the sidebar's link icon → **Connected apps**;
administrators can revoke anyone's connection under Administration → **Connected apps**.

## 6. ChatGPT (Developer Mode)

1. Turn on **Developer Mode**, then Apps & Connectors → **Create**.
2. URL `https://brain.example.com/api/mcp`, authentication **OAuth**.
3. Copy the callback URL ChatGPT shows and append it to
   `JOTTER_OAUTH_REDIRECT_ALLOWLIST` (comma-separated), then reload the server
   configuration.
4. Connect, sign in, consent to **only `brain`**.

Every read tool is marked `readOnlyHint`, so ChatGPT does not ask for confirmation
on reads; writes still ask. Deep Research uses the `search` and `fetch` tools.

## 7. Other clients

**Cursor** (`.cursor/mcp.json` or `~/.cursor/mcp.json`):

```json
{
  "mcpServers": {
    "brain": {
      "url": "https://brain.example.com/api/mcp",
      "headers": { "Authorization": "Bearer ${env:JOTTER_MCP_TOKEN}" }
    }
  }
}
```

**Claude Desktop with a manual token** (when OAuth is off): bridge with
[`mcp-remote`](https://github.com/geelen/mcp-remote) (Node.js 18+) in
`claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "brain": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://brain.example.com/api/mcp", "--header", "Authorization:${JOTTER_AUTH}"],
      "env": { "JOTTER_AUTH": "Bearer jt_mkt_…" }
    }
  }
}
```

The `Authorization:${JOTTER_AUTH}` form (no space after the colon, value in `env`)
avoids Claude Desktop mangling spaces in `args` on Windows.

## 8. Smoke test (every client)

Ask, in this order:

1. "Leia o contexto do brain e me diga o próximo passo do projeto X." → the client
   calls `get_brain_context` and answers from `projetos/x/estado.md`.
2. "Anote no inbox que testei a conexão." → the client calls `create_note` in
   `inbox/<client>/…`; the note appears in Jotter with a revision and an
   `mcp.note_written` audit entry naming the client.
3. For Claude web and ChatGPT: "liste meus workspaces" must **not** show
   `brain-restrito`.

With curl:

```sh
TOKEN=jt_mkt_…
HOST=https://brain.example.com

curl -s $HOST/api/mcp -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | jq '.result.tools[].name'

curl -s $HOST/api/mcp -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"get_brain_context","arguments":{}}}' | jq '.result.structuredContent.projects'

# OAuth discovery (only when JOTTER_OAUTH_ENABLED=true)
curl -si $HOST/api/mcp -X POST -H 'Content-Type: application/json' -d '{}' | grep -i www-authenticate
curl -s $HOST/.well-known/oauth-authorization-server | jq .issuer
```

`php artisan jotter:doctor` also checks the OAuth setup (metadata reachable, redirect
allowlist, HTTPS `APP_URL`).

## Common errors

| Symptom | Cause | Fix |
| --- | --- | --- |
| `401 … Unauthorized machine token` | Header missing, token revoked or expired, user deactivated | Re-issue; check `Authorization: Bearer jt_mkt_…` |
| `403` with `WWW-Authenticate: … insufficient_scope, scope="mcp:read"` | The token has only `api:*` scopes | Re-issue with `--scope=mcp:read` |
| `403 … insufficient_scope, scope="api:write"` on REST or WebDAV | Tokens are MCP-only unless they carry `api:*` (tokens issued before 2026-11-03 became `mcp:read`) | Re-issue with `--scope=api:read --scope=api:write` |
| Write tool returns "Operation … is not allowed on …" | Path or operation outside the token's write paths | Write inside `inbox/<client>/`, or re-issue with the right `--write-path` |
| `update_note` returns "The note changed since you read it" | Someone edited the note after the agent's `read_note` | Read again and retry with the new `sha256` |
| `429` with `Retry-After` | 120 calls/min, or 30 writes/min / 500 writes/day per token | Wait the given seconds; tune `JOTTER_MCP_*` if needed |
| Tool result "`workspace_id is required: this token can reach N workspaces`" | The token reaches several workspaces | Call `list_workspaces` first, or pass `workspace_id` |
| Claude web / ChatGPT: "couldn't connect" | OAuth off, `APP_URL` not the public HTTPS origin, callback not in the allowlist, or a WAF blocking `/.well-known` / `/oauth` | Run `jotter:doctor`; see [deployment.md](deployment.md#oauth-for-mcp-web-clients-claude-web-chatgpt) |
| Consent screen says the redirect is not registered | The client's callback is not in `JOTTER_OAUTH_REDIRECT_ALLOWLIST` | Append the exact callback URL |
| `403 … Origin not allowed` | A browser-based client sent a foreign `Origin` | Add it to `JOTTER_MCP_ALLOWED_ORIGINS` |
| Notes missing from results | ACLs hide them from the token's user | Grant view access to that user |

## Security notes

- Tokens are bearer secrets: keep them out of git (`.mcp.json` env expansion, Codex
  `bearer_token_env_var`, Cursor `${env:…}`, Claude Desktop `env`).
- Isolation between the general brain and client material is by **workspace**, not by
  folder: never grant `brain-restrito` to Claude web or ChatGPT.
- Agents write only inside their write paths; `_brain/` is human-only, which limits
  persistent prompt injection through `inbox/`. Note content is data, not instructions.
- Every call is audited (`mcp.method_called`, `mcp.note_written`, `oauth.*`) with the
  token id and client slug; `audit:prune` keeps 90 days.
