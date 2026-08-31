---
type: adr
status: aceito
date: 2026-03-02
tags: [adr, operacao]
---
# ADR-002: Hospedagem compartilhada como alvo

## Contexto
Nossos clientes já pagam por hospedagem PHP compartilhada e não querem administrar servidores.

## Decisão
O produto roda em PHP 8.2 + MySQL sem daemons, workers ou websockets. Todo trabalho periódico passa por um único `schedule:run` no cron.

## Consequências
- Processos longos viram comandos Artisan limitados e idempotentes.
- E-mails saem de forma síncrona ou pelo scheduler.
- O procedimento de implantação está no [[runbooks/Runbook Deploy em hospedagem compartilhada]].

## Alternativas consideradas
- Containers gerenciados: rejeitado por custo para o público-alvo.
- VPS próprio: rejeitado por exigir operação dedicada.
