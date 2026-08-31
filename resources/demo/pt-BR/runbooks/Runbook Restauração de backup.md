---
type: runbook
service: aurora
owner: Rafael Lins
last_reviewed: 2026-07-30
tags: [runbook, backup]
---
# Runbook: Restauração de backup

## Objetivo
Recuperar um workspace a partir do export ZIP semanal ([[wiki/Política de backups]]).

## Passos
1. Extrair `workspaces/<slug>/vault/` para o diretório do vault.
2. Rodar `php artisan vault:reindex --workspace=<id>` para reconstruir o índice.
3. Conferir contagem de notas com `backup.json`.

## Verificação
Busca full-text encontra uma nota conhecida; backlinks aparecem no painel.
