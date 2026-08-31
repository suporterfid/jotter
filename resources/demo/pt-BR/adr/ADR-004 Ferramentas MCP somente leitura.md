---
type: adr
status: aceito
date: 2026-07-20
tags: [adr, mcp, seguranca]
---
# ADR-004: Ferramentas MCP somente leitura

## Contexto
Assistentes de IA precisam consultar o vault ([[wiki/Integração com assistentes de IA]]), mas escrita remota por agentes traz risco de alteração em massa.

## Decisão
O servidor MCP expõe apenas `list_workspaces`, `list_notes`, `read_note`, `search_notes` e `get_backlinks`. Tokens de máquina herdam exatamente as permissões do usuário que os emitiu.

## Consequências
- Nenhum agente altera arquivos; humanos editam pela interface, WebDAV ou Obsidian.
- Escrita será revisitada quando existirem escopos `mcp:write` e trava por workspace.
