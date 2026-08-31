---
type: adr
status: aceito
date: 2026-04-15
tags: [adr, backup]
---
# ADR-003: Retenção de revisões em 30 dias

## Contexto
Snapshots de revisão crescem sem limite e ocupam o banco em hospedagem compartilhada.

## Decisão
Revisões derivadas expiram após 30 dias por uma tarefa diária. Os arquivos Markdown nunca são afetados ([[adr/ADR-001 Adotar Markdown como fonte da verdade]]).

## Consequências
- Histórico de um mês para restaurar edições.
- Backups semanais cobrem o horizonte maior ([[wiki/Política de backups]]).
