# Spec — Jotter como Brain multiagente

Nov 3, 2026 · @Alexandre Vieira dos Santos

> Cópia versionada da spec recebida em 2026-11-03. Plano de implementação: `docs/superpowers/plans/2026-11-03-jotter-brain-multiagent.md`. Onde o plano corrige esta spec (seção "Verificação contra o código"), o plano vale.

## Contexto e objetivo

Um único deployment do Jotter vira o brain compartilhado de quatro clientes: Hermes Agent, Claude Code CLI, Claude web e ChatGPT/Codex. Para isso faltam seis ajustes: OAuth 2.1 no MCP, escopo real de tokens, escrita controlada, polimento de protocolo, ferramentas de brain e um pacote de convenções de vault.

- **Fonte da verdade:** continua sendo Markdown em disco; o MySQL segue como índice reconstruível (invariante §1 do spec).
- **Restrição de hospedagem:** tudo precisa rodar em PHP 8.2 + MySQL 8 compartilhado, sem daemons e sem websockets (§4).
- **Critério de pronto:** os quatro clientes leem, buscam e gravam no `inbox/` do brain, e nenhum deles enxerga o workspace restrito sem permissão explícita.
- **Base analisada:** `main` do repositório em 2026-11-03 (v0.10.0, `McpController`, `MachineTokenIssuer`, `LocalIdentityProvider`, `docs/mcp.md`, `docs/mcp-clients.md`).

Este spec reabre a decisão "write tools deferred" de `docs/mcp.md`. Pela regra do `BACKLOG.md`, o primeiro PR registra a decisão datada em `docs/decisions.md`.

## Estado atual do Jotter

O MCP já funciona para clientes que aceitam header Bearer, mas é só leitura e o token não tem escopo próprio. Pontos verificados no código:

| Item | Hoje | Onde |
| --- | --- | --- |
| Endpoint | `POST /api/mcp`, JSON-RPC 2.0, respostas JSON sem SSE, `GET` → 405 | `McpController::handle` |
| Versões de protocolo | `2025-06-18`, `2025-03-26`, `2024-11-05` | `McpController::PROTOCOL_VERSIONS` |
| Ferramentas | `list_workspaces`, `list_notes`, `read_note`, `search_notes`, `get_backlinks`, sem `annotations` | `McpController::toolDefinitions` |
| Autenticação | Token `jt_mkt_…`, só hash SHA-256 em `machine_tokens` (`tenant_id`, `subject_id`, `name`, `revoked_at`) | `MachineTokenIssuer` |
| 401 | Erro JSON-RPC `-32001`, sem header `WWW-Authenticate` | `McpController::handle` |
| OAuth | Inexistente; `docs/mcp-clients.md` já registra que Claude Desktop exige OAuth e usa `mcp-remote` como ponte | `docs/mcp-clients.md` |
| Autorização | Token age como o usuário: memberships + ACL por nota (`NoteAccess`) | `IdentityProvider`, `NoteAccess` |
| Escrita | Adiada até existir escopo `mcp:write`, rate limit e lock por workspace | `docs/mcp.md` |
| Escrita via REST | `PUT /notes/{id}` sobrescreve sem checar versão base | `WorkspaceNoteController::update` |
| Rate limit | Nenhum no `/api/mcp` | `routes/api.php` |

**Dois achados de segurança que este spec corrige:**

1. **O token "somente leitura" escreve.** `LocalIdentityProvider::resolveIdentity` aceita o Bearer em qualquer rota da API. Um token MCP grava via REST (`PUT /notes`), WebDAV e, se o usuário for admin, acessa `/admin/*`.
2. **Token só funciona no modo local.** Apenas o `LocalIdentityProvider` resolve `machine_tokens`; com `JOTTER_AUTH_PROVIDER=oidc` ou GrandpaSSOn, o MCP para de autenticar.

## Matriz de compatibilidade por cliente

Claude Code, Codex CLI e Hermes já conectam com o token atual; Claude web e ChatGPT só conectam depois do OAuth 2.1 (R1).

| Cliente | Autenticação aceita | Funciona hoje? | Bloqueio | Requisitos que destravam |
| --- | --- | --- | --- | --- |
| Claude Code CLI | Header (`--header`) ou OAuth com redirect loopback | Sim, leitura | Escrita | R2, R3 |
| Codex CLI/IDE | `bearer_token_env_var`, `http_headers` ou OAuth (`codex mcp login`) | Sim, leitura | Escrita | R2, R3 |
| Hermes Agent | `headers` ou `auth: oauth` em `mcp_servers` | Sim, leitura | Escrita; Hermes grava memória só local | R2, R3, R5 |
| Claude web/Desktop/mobile | OAuth (DCR ou CIMD) ou sem auth; header estático é beta restrito | Não | Sem OAuth, sem 401 com `WWW-Authenticate` | R1, R4 |
| ChatGPT (Developer Mode) | OAuth (estático, DCR ou CIMD), sem auth ou misto | Não | Sem OAuth; sem `readOnlyHint`, toda chamada vira confirmação de escrita | R1, R4 |

> Diagrama (no original): arquitetura do brain — 5 clientes, 2 portas de autenticação (token manual e OAuth), 2 workspaces.

As duas portas chegam ao mesmo endpoint e às mesmas regras; o que muda é quem define o escopo: o admin, no token manual, ou o próprio usuário, na tela de consentimento.

**Requisitos dos clientes web que moldam o R1:**

- **Claude:** exige `401` com `WWW-Authenticate: Bearer resource_metadata=…` para iniciar o login; usa só o primeiro item de `authorization_servers`; exige PKCE S256; espera 10 s nos endpoints OAuth e 30 s no refresh. Callback fixo `https://claude.ai/api/mcp/auth_callback`; Claude Code usa loopback em porta variável. Refresh token deve rodar a cada uso em clientes públicos. [Claude — Authentication for connectors](https://claude.com/docs/connectors/building/authentication.md)
- **ChatGPT:** aceita SSE e streamable HTTP; trata como leitura só ferramentas com `readOnlyHint`; pede confirmação nas demais. [OpenAI — ChatGPT Developer mode](https://developers.openai.com/api/docs/guides/developer-mode.md)
- **Codex:** chaves `url`, `bearer_token_env_var`, `enabled_tools`; OAuth por `codex mcp login`. [Codex CLI MCP config](https://codex.danielvaughan.com/2026/05/19/codex-cli-mcp-server-management-cli-commands-oauth-streamable-http-production-patterns/)
- **Hermes:** `headers` ou `auth: oauth`; filtro `tools.include`; tokens OAuth salvos em `~/.hermes/mcp-tokens/`. [Hermes — MCP config reference](https://download.hermesedi.duckdns.org/.hermes/hermes-agent/website/docs/reference/mcp-config-reference.md)

## Requisitos

Seis requisitos; R2 e R4 vêm primeiro porque corrigem falhas de segurança e são pré-requisito de todo o resto.

| ID | Requisito | Prioridade | Resultado esperado |
| --- | --- | --- | --- |
| R2 | Escopo real de credenciais: escopos, lista de workspaces, caminhos de escrita, expiração e audiência `mcp` | P1 | Token MCP não acessa REST, WebDAV nem admin; funciona em qualquer `IdentityProvider` |
| R4 | Polimento de protocolo: `WWW-Authenticate`, versão `2025-11-25`, `annotations`, instruções por workspace, rate limit | P1 | ChatGPT não pede confirmação em leitura; clientes descobrem o OAuth sozinhos |
| R1 | Servidor de autorização OAuth 2.1 embutido: RFC 9728, RFC 8414, DCR (RFC 7591), PKCE S256, refresh rotativo, tela de consentimento | P1 | Claude web e ChatGPT conectam por URL, sem `mcp-remote` |
| R3 | Ferramentas de escrita sob `mcp:write`, restritas a caminhos permitidos, com concorrência otimista, lock e auditoria | P1 | Agentes gravam no `inbox/` e no diário sem sobrescrever edições humanas |
| R5 | Ferramentas de brain: contexto inicial, mudanças recentes, `search`/`fetch` no formato OpenAI | P2 | Um agente novo se orienta com uma chamada |
| R6 | Pacote de convenções de vault `brain` (pastas, front matter, instruções) | P2 | `tenant:provision` ou import cria um brain pronto |

**Não-funcionais, válidos para todos:** sem daemon novo (só o cron existente), endpoints OAuth respondendo em menos de 2 s, toda chamada auditada com cliente e token, testes PHPUnit por requisito e cobertura no CI obrigatório.

## Design técnico

Tokens continuam opacos e guardados só como hash SHA-256, como os `machine_tokens` de hoje; nada de JWT nem chave RSA para gerenciar na hospedagem compartilhada.

### R2 — Escopo real de credenciais

**Migração `extend_machine_tokens`** (colunas novas):

| Coluna | Tipo | Uso |
| --- | --- | --- |
| `kind` | enum `manual`, `oauth` | Token emitido no admin ou pelo OAuth |
| `scopes` | json | `mcp:read`, `mcp:write`, `api:read`, `api:write` |
| `workspace_ids` | json, nulo = todos os memberships | Restringe além das memberships do usuário |
| `write_paths` | json | Lista de `{glob, ops}`, ex. `{"glob":"inbox/{client}/**","ops":["create","append","update"]}` |
| `client_id` | fk `oauth_clients`, nulo | Cliente OAuth dono do token |
| `client_slug` | string | Valor de `{client}` nos globs (`hermes`, `chatgpt`, `claude-web`) |
| `expires_at` | timestamp, nulo | Obrigatório para `oauth` |
| `last_used_at` | timestamp | Gravado no máximo 1 vez por minuto |

**Resolução:** sai do `LocalIdentityProvider` e vira o middleware `ResolveMachineToken`, que roda antes de qualquer provider. Ele preenche um `CredentialContext` (id, kind, scopes, workspaces, write\_paths, client) dentro do `AuthenticatedSubject`. Assim o MCP funciona também com OIDC e GrandpaSSOn.

**Regras de acesso:**

1. Workspaces efetivos = memberships do usuário ∩ `workspace_ids`.
2. Bearer em rota fora de `/api/mcp` exige `api:read` (GET) ou `api:write` (mutações e WebDAV de escrita); sem o escopo → 403.
3. `/api/admin/*` nunca aceita token, qualquer que seja o escopo.
4. Tokens existentes migram para `["mcp:read"]`. É quebra de compatibilidade para quem usa o token na REST; vai no CHANGELOG com o comando para reemitir.

**Emissão:** a aba MCP tokens ganha seletor de workspaces, escopos, caminhos de escrita e validade. CLI: `php artisan mcp:token joe@… --name="Hermes VPS" --workspace=brain --scope=mcp:read --scope=mcp:write --write-path="inbox/hermes/**:create,append,update" --client=hermes --expires=180d`.

### R4 — Protocolo e limites

- **401 descobrível:** `WWW-Authenticate: Bearer resource_metadata="https://<host>/.well-known/oauth-protected-resource/api/mcp", scope="mcp:read"`.
- **Escopo insuficiente:** 403 com `WWW-Authenticate: Bearer error="insufficient_scope", scope="mcp:write"`.
- **Versões:** acrescentar `2025-11-25` no topo de `PROTOCOL_VERSIONS`.
- **`annotations` em toda ferramenta:** leitura com `readOnlyHint: true`, `openWorldHint: false`; `append_to_note` e `create_note` com `destructiveHint: false`; `update_note` com `destructiveHint: true`.
- **`tools/list` filtrado por escopo:** ferramentas de escrita só aparecem com `mcp:write`.
- **`instructions` dinâmico:** texto fixo do servidor + conteúdo de `_brain/instructions.md` do workspace padrão, cortado em 4 KB.
- **`structuredContent`:** `read_note` passa a devolver `{path, sha256, updated_at}` junto do texto, para o agente poder chamar `update_note`.
- **Rate limit** (`RateLimiter` com cache `database`, configurável em `jotter.mcp.rate_limits`): 120 chamadas/min e 30 escritas/min por token; 500 escritas/dia por token. Excedeu → HTTP 429 com `Retry-After`.

### R1 — Servidor de autorização OAuth 2.1

Implementação própria e enxuta, não Laravel Passport: Passport não traz DCR nem os metadados RFC 9728, e exige chaves. Cinco controllers, tokens no mesmo formato opaco.

| Endpoint | Função |
| --- | --- |
| `GET /.well-known/oauth-protected-resource[/api/mcp]` | RFC 9728: `resource` = URL exata do MCP, `authorization_servers` = \[issuer\], `scopes_supported`, `bearer_methods_supported: ["header"]` |
| `GET /.well-known/oauth-authorization-server` | RFC 8414: endpoints abaixo, `response_types: ["code"]`, `grant_types: ["authorization_code","refresh_token"]`, `code_challenge_methods_supported: ["S256"]`, `token_endpoint_auth_methods_supported: ["none","client_secret_post","client_secret_basic"]`, `scopes_supported` com `offline_access` |
| `POST /oauth/register` | DCR (RFC 7591), JSON; `redirect_uris` validados contra `JOTTER_OAUTH_REDIRECT_ALLOWLIST`; 10 registros/hora por IP; clientes sem uso por 30 dias apagados pelo cron |
| `GET /oauth/authorize` | Exige sessão (local, OIDC ou GrandpaSSOn, com retorno pós-login); PKCE S256 obrigatório; `redirect_uri` exato, loopback sem checar porta; `resource`, se vier, igual à URL do MCP; mostra consentimento |
| `POST /oauth/token` | `application/x-www-form-urlencoded`; troca código (uso único, 60 s) e refresh; erros RFC 6749 (`invalid_grant`) |
| `POST /oauth/revoke` | RFC 7009 |

**Tela de consentimento** (Blade, marca do tenant, pt-BR/en): nome do cliente e host do redirect em destaque, com aviso extra quando o redirect é loopback; workspaces marcáveis (só memberships, nenhum marcado por padrão); escopo de leitura marcado e escrita opcional; caminhos de escrita pré-preenchidos com `inbox/{client}/**` e `diario/**` (só `append`); validade da concessão.

**Tokens:** acesso `jt_oat_…` com 1 h, gravado em `machine_tokens` (`kind=oauth`); refresh `jt_ort_…` com 30 dias, rotativo, em `oauth_refresh_tokens`. Reuso de refresh já trocado revoga a concessão inteira.

**Tabelas novas:** `oauth_clients` (client\_id, nome, redirect\_uris, auth\_method, secret\_hash nulo, last\_used\_at); `oauth_auth_codes` (hash, client, subject, challenge, redirect\_uri, grant, expires\_at, used\_at); `oauth_grants` (tenant, subject, client, workspace\_ids, scopes, write\_paths, revoked\_at); `oauth_refresh_tokens` (hash, grant\_id, expires\_at, used\_at).

**Allowlist de redirect padrão:** `https://claude.ai/api/mcp/auth_callback`, `http://localhost:*/*` e `http://127.0.0.1:*/*` (Claude Code, Codex, Hermes) e o callback do ChatGPT, lido da tela de criação do conector.

**Gestão:** "Apps conectados" no perfil do usuário e no admin, com lista de concessões, último uso e botão de revogar (revoga refresh e tokens de acesso juntos).

**CIMD:** fora da primeira versão; Claude e ChatGPT caem para DCR quando o servidor não anuncia CIMD.

### R3 — Ferramentas de escrita

Três ferramentas, todas sob `mcp:write` e todas passando pelo `VaultStorage::write` existente, que já gera revisão, projeções, links e eventos.

| Ferramenta | Entrada | Comportamento | Operação exigida |
| --- | --- | --- | --- |
| `create_note` | `workspace_id?`, `path`, `content`, `if_exists: fail\|suffix` | Cria nota; com `suffix`, grava `nome-2.md` em vez de falhar | `create` |
| `append_to_note` | `workspace_id?`, `path`, `content`, `heading?`, `idempotency_key?` | Acrescenta no fim ou ao fim da seção `heading`; cria a nota se não existir; repete a mesma chave em 24 h → no-op | `append` |
| `update_note` | `workspace_id?`, `path`, `content`, `expected_sha256` | Substitui o conteúdo só se o hash atual bater; senão erro com o hash atual e nada gravado | `update` |

**Sequência de cada escrita:**

1. Normalizar o caminho com `VaultPathGuard`; exigir `.md`; recusar `.trash/`, `_resources/` e `_templates/`.
2. Conferir escopo `mcp:write`, `canWriteWorkspace` e `NoteAccess::assertEdit` (notas existentes).
3. Casar o caminho com `write_paths` (glob com `FNM_PATHNAME`, `{client}` = `client_slug`) e a operação com `ops`.
4. Conferir o `TenantPlan` (402 vira erro da ferramenta) e o limite de 256 KB por chamada.
5. Tomar `Cache::lock("note:{workspace}:{path}", 10)`; conferir `expected_sha256` dentro do lock.
6. Gravar com `VaultStorage::write(..., actorId: subject)`; emitir `emitNoteEdited`.
7. Auditar `mcp.note_written` com `tool`, `token_id`, `client_slug`, `path`, `bytes`, `sha256_before`, `sha256_after`.

Apagar e mover notas ficam fora. O front matter não é carimbado pelo servidor; a origem da escrita fica no audit log e no histórico de revisões.

**Ajuste derivado na REST:** `PUT /notes/{id}` aceita `base_sha256` opcional e responde 409 se divergir. Protege edições feitas pelo SPA ou pelo Obsidian via WebDAV enquanto um agente escreve.

### R5 — Ferramentas de brain

| Ferramenta | Retorno | Limite |
| --- | --- | --- |
| `get_brain_context` | `_brain/README.md`, `_brain/instructions.md`, front matter de todo `projetos/*/estado.md` (status, próximo passo, atualizado) e as 10 mudanças mais recentes | 24 KB |
| `list_recent_changes` | `path`, `updated_at`, ator e cliente, a partir de `note_revisions` + audit | `since?`, `limit` ≤ 100 |
| `get_outgoing_links` | Links de saída de uma nota (a REST já tem) | ACL aplicada |
| `search` | Formato OpenAI: `{results: [{id, title, url}]}`, `id` = `workspace:path` | 20 resultados |
| `fetch` | Formato OpenAI: `{id, title, text, url, metadata}` | 1 nota |

`search` e `fetch` deixam o brain usável também no Deep Research do ChatGPT, que só chama essas duas. Busca semântica fica fora: o FULLTEXT do MySQL atende, e embeddings exigiriam worker.

### R6 — Pacote de vault `brain`

Comando `php artisan brain:init {workspace}` e pacote em `templates:pack`, criando:

```text
_brain/README.md            roteador: o que existe e onde procurar
_brain/instructions.md      regras para agentes (vai no initialize do MCP)
projetos/<slug>/estado.md   status, última decisão, próximo passo
decisoes/AAAA-MM-DD-<slug>.md
diario/AAAA-MM-DD.md        agentes só acrescentam
inbox/<cliente>/            única área de escrita livre dos agentes
pessoas/<nome>.md
```

Front matter de `estado.md`: `status` (ativo, pausado, concluído), `proximo_passo`, `atualizado`, `dono`. As instruções padrão dizem ao agente: ler `get_brain_context` primeiro, nunca escrever fora do `inbox/` próprio, terminar toda sessão com `append_to_note` no diário. A consolidação do `inbox/` para as pastas oficiais é humana, ou feita pelo Claude Code com um token manual de caminhos mais amplos.

## Segurança e não-objetivos

O isolamento entre o brain geral e dados de cliente é feito por workspace, não por convenção de pasta: o ChatGPT nunca recebe concessão para o workspace restrito.

**Topologia recomendada para o seu deployment:**

| Workspace | Conteúdo | Clientes com acesso |
| --- | --- | --- |
| `brain` | Projetos próprios (Gestrato, BVS, SaaS suporterfid), decisões, diário | Todos os quatro |
| `brain-restrito` | Material de clientes e do trabalho profissional | Claude Code e Hermes, com token manual; nunca Claude web nem ChatGPT |

**Controles:**

- Escrita de agente confinada a `write_paths`; diário só com `append`; nada de apagar ou mover.
- Concorrência otimista (`expected_sha256`) + lock por nota; reuso de refresh revoga a concessão.
- Audit de toda chamada, com cliente e token; `audit:prune` mantém a retenção atual de 90 dias.
- Conteúdo lido do brain é dado, não instrução: `_brain/instructions.md` só é editável por humanos (fora de qualquer `write_paths`), o que limita injeção de prompt persistente via `inbox/`.
- WAF ou bloqueio por IP no Hostinger deve liberar a faixa de saída da Anthropic `160.79.104.0/21` e a da OpenAI, inclusive nos endpoints `/.well-known` e `/oauth/*`.

**Não-objetivos:**

- Busca semântica ou embeddings (exigiria worker; candidata a TaskConnect).
- Apagar ou mover notas via MCP.
- Grant `client_credentials` sem usuário (Claude não suporta).
- CIMD na primeira versão.
- SSE/streaming de respostas; o `POST` com JSON atende os quatro clientes.
- Sincronizar a memória interna do Hermes (`MEMORY.md`, `USER.md`) com o brain; o agente é instruído a gravar no brain o que precisa ser compartilhado.

## Configuração de cada cliente

Claude web e ChatGPT usam OAuth pela URL; os três clientes de terminal usam token manual por header, o que mantém o acesso ao workspace restrito fora do navegador. Estes blocos entram em `docs/mcp-clients.md`.

**Claude Code CLI** (token manual, `brain` + `brain-restrito`, escrita em `inbox/claude-code/**`):

```sh
claude mcp add -s user --transport http brain https://brain.<seu-dominio>/api/mcp \
  --header "Authorization: Bearer ${JOTTER_MCP_TOKEN}"
```

**Codex CLI** (`~/.codex/config.toml`):

```toml
[mcp_servers.brain]
url = "https://brain.<seu-dominio>/api/mcp"
bearer_token_env_var = "JOTTER_MCP_TOKEN"
tool_timeout_sec = 60.0
```

**Hermes Agent** (`~/.hermes/config.yaml`, token manual com `--client=hermes`):

```yaml
mcp_servers:
  brain:
    url: "https://brain.<seu-dominio>/api/mcp"
    headers:
      Authorization: "Bearer jt_mkt_…"
    tools:
      include: [get_brain_context, search_notes, read_note, list_recent_changes, append_to_note, create_note]
```

No cron do Hermes, a skill de fim de tarefa chama `append_to_note` em `diario/AAAA-MM-DD.md`.

**Claude web/Desktop/mobile:** Configurações → Conectores → Adicionar conector personalizado → URL `https://brain.<seu-dominio>/api/mcp` → Conectar → tela de consentimento do Jotter → marcar só `brain`.

**ChatGPT:** ativar Developer Mode → Apps e Conectores → Criar → mesma URL, autenticação OAuth → copiar o callback mostrado para `JOTTER_OAUTH_REDIRECT_ALLOWLIST` → consentir só `brain`.

**Teste de fumaça igual nos cinco:** "leia o contexto do brain e me diga o próximo passo do projeto X" (`get_brain_context`) e "anote no inbox que testei a conexão" (`create_note`).

## Plano de entrega

Seis PRs em sequência, cada um com CI verde antes do próximo; o brain já é útil depois do PR 4, sem esperar o resto.

| PR | Escopo | Depende de | Critério de aceite | Testes |
| --- | --- | --- | --- | --- |
| 1 | Registro de decisão em `docs/decisions.md` + R2 (escopos, workspaces, audiência, middleware, CLI e UI de emissão) | — | Token só `mcp:read` recebe 403 em `PUT /notes` e WebDAV `PUT`; MCP funciona com `JOTTER_AUTH_PROVIDER=oidc`; token restrito a `brain` não lista `brain-restrito` | `McpTokenScopeTest`, `TokenAudienceTest`, `TokenWorkspaceAllowlistTest` |
| 2 | R4 (401 com `WWW-Authenticate`, `2025-11-25`, `annotations`, `tools/list` por escopo, `instructions`, rate limit) | 1 | 121ª chamada no minuto recebe 429; `tools/list` sem `mcp:write` não mostra escrita | `McpProtocolTest`, `McpRateLimitTest` |
| 3 | R1 (metadados, DCR, authorize + consentimento, token, revoke, apps conectados, prune) | 1, 2 | Claude web e ChatGPT conectam por URL; refresh rotaciona; reuso revoga a concessão; endpoints < 2 s | `OAuthMetadataTest`, `OAuthPkceFlowTest`, `OAuthRefreshRotationTest`, `OAuthRedirectAllowlistTest`, E2E Playwright do consentimento |
| 4 | R3 (`create_note`, `append_to_note`, `update_note`, `structuredContent` no `read_note`, `base_sha256` na REST) | 1, 2 | Escrita fora de `write_paths` falha sem tocar o disco; hash divergente não grava; cada escrita gera revisão e `mcp.note_written` | `McpWriteToolsTest`, `WritePathPolicyTest`, `NoteOptimisticConcurrencyTest` |
| 5 | R5 + R6 (`get_brain_context`, `list_recent_changes`, `search`, `fetch`, `brain:init`, pacote de templates) | 4 | `get_brain_context` responde em uma chamada com ≤ 24 KB; `brain:init` é idempotente | `BrainContextToolTest`, `OpenAiSearchFetchTest`, `BrainInitCommandTest` |
| 6 | `docs/mcp-clients.md` com os cinco clientes + `jotter:doctor` checando `/.well-known` e allowlist | 3, 4, 5 | Teste de fumaça da seção anterior passa nos cinco clientes contra o deployment real | Checklist manual registrado no PR |

Depois do PR 6: subir o deployment `brain`, rodar `brain:init`, emitir os tokens manuais e conectar Claude web e ChatGPT.

## Questões em aberto

- [ ] **Onde hospedar o deployment `brain`:** Hostinger compartilhado (alvo nativo do Jotter) ou Dokploy no VPS (já há `compose.yaml`)? No compartilhado, confirmar que o `.htaccess` deixa `/.well-known/oauth-*` chegar ao Laravel sem conflitar com o AutoSSL.
- [ ] **Callback exato do ChatGPT:** ler na tela de criação do conector e fixar no allowlist padrão.
- [ ] **Quebra de compatibilidade do R2:** aceitar que tokens existentes percam acesso à REST, ou migrá-los com `api:read`/`api:write` e avisar?
- [ ] **Hermes com OAuth ou token manual:** OAuth dá revogação pelo painel, mas o VPS sem navegador complica o primeiro login.
- [ ] **Cadernia:** o OAuth entra no produto hospedado desde o início ou só depois de validado no brain?
- [ ] **Limites de rate:** 120 chamadas/min e 500 escritas/dia são palpites; revisar após duas semanas de audit log.
