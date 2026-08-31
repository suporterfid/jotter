---
tags: [wiki, ia, mcp]
---
# Integração com assistentes de IA

Assistentes como Claude Code, Cursor e Claude Desktop leem este vault pelo **MCP** (ver [[wiki/Glossário]]). O servidor expõe ferramentas somente leitura: listar, ler, buscar e obter backlinks.

## Por que somente leitura

Decidido em [[adr/ADR-004 Ferramentas MCP somente leitura]]: escrita por agentes exige escopos finos de token e trava por workspace, ainda não implementados.

## Como configurar

1. Um administrador emite um token de máquina.
2. O cliente recebe a URL `https://<host>/api/mcp` e o header `Authorization: Bearer <token>`.
3. Teste de fumaça: "liste minhas notas".

Detalhes operacionais no [[runbooks/Runbook Conectar um assistente por MCP]].
