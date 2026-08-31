---
type: runbook
service: mcp
owner: Rafael Lins
last_reviewed: 2026-08-31
tags: [runbook, mcp]
---
# Runbook: Conectar um assistente por MCP

## Pré-requisitos
- Token de máquina emitido por um administrador (aba "MCP" da administração ou `php artisan mcp:token`).
- URL do servidor: `https://<host>/api/mcp`.

## Passos
1. **Claude Code**: `claude mcp add --transport http cadernia https://<host>/api/mcp --header "Authorization: Bearer <token>"`.
2. **Cursor**: adicionar `url` e `headers` em `.cursor/mcp.json`.
3. **Claude Desktop**: usar `mcp-remote` em `claude_desktop_config.json`, pois o arquivo só inicia servidores stdio.
4. Teste de fumaça: pedir "liste minhas notas".

## Erros comuns
- `401 Unauthorized machine token`: token revogado ou header sem `Bearer`.
- `workspace_id is required`: o usuário do token acessa mais de um workspace — pedir para chamar `list_workspaces` primeiro.

Decisão de escopo: [[adr/ADR-004 Ferramentas MCP somente leitura]].
