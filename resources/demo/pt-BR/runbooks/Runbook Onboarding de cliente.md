---
type: runbook
service: hosted
owner: Leila Nogueira
last_reviewed: 2026-08-29
tags: [runbook, onboarding]
---
# Runbook: Onboarding de cliente

## Passos
1. `php artisan tenant:provision --tenant-slug=<slug> ... --locale=pt-BR` — cria tenant, workspace, admin, trial e templates.
2. Enviar a senha por canal seguro (ela aparece uma única vez no terminal).
3. Agendar a chamada de boas-vindas e apontar para o [[runbooks/Runbook Conectar um assistente por MCP]].
4. Registrar o cliente no [[projetos/Roadmap 2026]] como piloto, se aplicável.

Relacionado: [[adr/ADR-005 Branding por configuração]].
