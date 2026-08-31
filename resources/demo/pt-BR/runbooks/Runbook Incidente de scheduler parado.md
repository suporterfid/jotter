---
type: runbook
service: aurora
owner: Caio Ferreira
last_reviewed: 2026-08-18
tags: [runbook, incidente]
---
# Runbook: Incidente de scheduler parado

## Sintomas
`php artisan jotter:doctor` mostra `[FAIL] Scheduler heartbeat`; `/api/health` traz `scheduler_last_run_at` antigo; e-mails de digest e exports PDF não saem.

## Passos
1. Conferir a linha do cron no painel da hospedagem.
2. Rodar `php artisan schedule:run` manualmente e observar erros.
3. Verificar permissões de `storage/` e `bootstrap/cache`.
4. Reativar o cron e confirmar com o doctor.

Contexto: [[adr/ADR-002 Hospedagem compartilhada como alvo]].
