---
tags: [wiki, operacao, backup]
---
# Política de backups

- Export ZIP completo do tenant toda semana (vault + `backup.json` + manifesto).
- Retenção de 90 dias em armazenamento externo.
- Teste de restauração trimestral seguindo o [[runbooks/Runbook Restauração de backup]].
- Revisões de notas são mantidas por 30 dias no índice; os arquivos Markdown nunca são apagados por retenção.

Relacionado: [[adr/ADR-003 Retenção de revisões em 30 dias]], [[wiki/Arquitetura da plataforma]].
