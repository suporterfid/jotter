---
type: runbook
service: aurora
owner: Caio Ferreira
last_reviewed: 2026-08-04
tags: [runbook, deploy]
---
# Runbook: Deploy em hospedagem compartilhada

## Objetivo
Instalar ou atualizar uma instância conforme [[adr/ADR-002 Hospedagem compartilhada como alvo]].

## Passos
1. Verificar o checksum do ZIP de release.
2. Extrair em `domains/<dominio>/public_html/<slug>/` com docroot em `public/`.
3. Criar `.env` com `VAULT_BASE_PATH` fora do docroot.
4. `php artisan migrate --force`.
5. Adicionar o cron `* * * * * php artisan schedule:run`.
6. `php artisan jotter:doctor` até ficar sem `[FAIL]`.

## Verificação
`GET /api/health` responde `status: ok` e `scheduler_last_run_at` recente.

## Reversão
Restaurar o ZIP anterior e rodar `migrate:rollback` apenas se a migração for reversível; em dúvida, seguir [[runbooks/Runbook Restauração de backup]].
