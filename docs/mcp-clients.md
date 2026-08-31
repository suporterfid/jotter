# Connect AI clients to Jotter over MCP

Jotter serves the Model Context Protocol at `POST https://<host>/api/mcp`
(Streamable HTTP, JSON-RPC 2.0, JSON responses). Tools are **read-only**:
`list_workspaces`, `list_notes`, `read_note`, `search_notes`, `get_backlinks`
(see [mcp.md](mcp.md)). Authentication is a **machine token** sent as
`Authorization: Bearer jt_mkt_…`; the token acts as the user it was issued for
and reads exactly that user's workspaces.

Five minutes: create a token, paste one configuration block, ask the assistant
to "list my notes".

Formats below were checked against the clients' current documentation
(Claude Code `claude mcp add` / `.mcp.json`, Cursor `mcp.json`, Claude Desktop
`claude_desktop_config.json` + `mcp-remote`) on 2026-08-31.

## 1. Create a machine token

**In the app (administrator):** Administration → **MCP tokens** → choose the
tenant and the user the token should act as, give it a label, click **Issue
token**. The token is shown once, together with ready-to-copy snippets for the
three clients with your host already filled in.

**Over SSH (hosted operators):**

```sh
php artisan mcp:token ana@acme.example --name="Ana — Claude Code"
```

Only the SHA-256 hash is stored. Revoke from the same screen (or delete the
row); revocation is immediate. A token can read every workspace its user is a
member of — issue tokens for a user with the narrowest membership you need.

Replace `https://acme.example.com` and `jt_mkt_…` below with your host and token.

## 2. Claude Code

One command (scope `local` = this project; add `-s user` for all projects):

```sh
claude mcp add --transport http jotter https://acme.example.com/api/mcp \
  --header "Authorization: Bearer jt_mkt_…"
```

Or commit a project file `.mcp.json` (env expansion keeps the secret out of git):

```json
{
  "mcpServers": {
    "jotter": {
      "type": "http",
      "url": "https://acme.example.com/api/mcp",
      "headers": {
        "Authorization": "Bearer ${JOTTER_MCP_TOKEN}"
      }
    }
  }
}
```

Check: `claude mcp list` shows `jotter … ✔ Connected`; inside a session, `/mcp`
lists the five tools.

**Smoke test** — in a Claude Code session:

> list my notes

Claude calls `list_notes` (or `list_workspaces` first when the token can reach
several workspaces) and answers with paths and titles. Then try
"search my notes for *deploy*" (`search_notes`) and "read `runbooks/deploy.md`"
(`read_note`).

## 3. Cursor

Project file `.cursor/mcp.json` (or `~/.cursor/mcp.json` for every project).
Cursor's remote-server entries use `url` + `headers` (no `type` key):

```json
{
  "mcpServers": {
    "jotter": {
      "url": "https://acme.example.com/api/mcp",
      "headers": {
        "Authorization": "Bearer jt_mkt_…"
      }
    }
  }
}
```

Cursor supports `"${env:JOTTER_MCP_TOKEN}"` interpolation if you prefer not to
store the token in the file. Enable the server in **Settings → MCP**; the tools
appear under "jotter" with a green dot.

**Smoke test** — in the agent chat: "list my notes in Jotter".

## 4. Claude Desktop

`claude_desktop_config.json` only launches **stdio** servers (`command` +
`args`) and has no field for a custom `Authorization` header; remote servers
added through *Settings → Connectors* must speak OAuth, which Jotter does not
implement. Bridge with [`mcp-remote`](https://github.com/geelen/mcp-remote)
(Node.js 18+), which runs locally as a stdio server and forwards to the HTTP
endpoint with your header:

- macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`

```json
{
  "mcpServers": {
    "jotter": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://acme.example.com/api/mcp",
        "--header",
        "Authorization:${JOTTER_AUTH}"
      ],
      "env": {
        "JOTTER_AUTH": "Bearer jt_mkt_…"
      }
    }
  }
}
```

The `Authorization:${JOTTER_AUTH}` form (no space after the colon, value in
`env`) is the layout mcp-remote recommends because Claude Desktop on Windows
mangles spaces inside `args`. For a plain `http://` development server add
`"--allow-http"` to `args`. Quit and reopen Claude Desktop; the server shows
under the "Add files, connectors, and more" menu.

**Smoke test**: "list my notes".

## 5. Verify with curl (any host)

```sh
TOKEN=jt_mkt_…
HOST=https://acme.example.com

curl -s $HOST/api/mcp -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | jq '.result.tools[].name'

curl -s $HOST/api/mcp -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"search_notes","arguments":{"query":"deploy"}}}' | jq '.result'
```

## Common errors

| Symptom | Cause | Fix |
|---|---|---|
| `401 … Unauthorized machine token` | Header missing, not `Bearer`, token revoked, or user deactivated | Re-issue the token; check `Authorization: Bearer jt_mkt_…` |
| `403 … Unauthorized workspace access` | `workspace_id` names a workspace the token's user is not a member of | Call `list_workspaces`; grant membership |
| Tool result "`workspace_id is required: this token can reach N workspaces`" | The user belongs to several workspaces | Ask the assistant to call `list_workspaces` first, or issue the token for a user with one membership |
| `✘ Failed to connect` in `claude mcp list` | Wrong URL (must end in `/api/mcp`), HTTPS certificate problem, or the host blocks `POST` bodies | `curl` the endpoint as above; confirm `https://` |
| Claude Desktop shows nothing | Config file syntax, Node.js/npx missing, or a space in `Authorization: …` on Windows | Check `~/Library/Logs/Claude/mcp*.log`; use the `env` layout above |
| Works in curl, 419/405 in a client | Client sent `GET` (SSE stream) first | Jotter answers `405` to `GET`; clients fall back to POST automatically (mcp-remote: `--transport http-only`) |
| Notes missing from results | ACLs hide them from the token's user | Grant view access to that user |

## Security notes

- Tokens are bearer secrets: keep them out of git (`.mcp.json` env expansion,
  Cursor `${env:…}`, Claude Desktop `env`).
- Every call is audited (`mcp.method_called`); failed auth is audited without
  the token.
- Tools cannot create, edit, or delete notes ([mcp.md](mcp.md) explains why).
