---
tags: [wiki, arquitetura]
---
# Arquitetura da plataforma

O [[projetos/Projeto Aurora]] é um monólito Laravel com uma SPA Vue. A fonte da verdade são arquivos Markdown em disco (ver [[adr/ADR-001 Adotar Markdown como fonte da verdade]]); o MySQL é um índice reconstruível.

## Componentes

1. **Vault** — arquivos `.md` e anexos, um diretório por workspace.
2. **Índice** — tabelas de notas, links, propriedades e busca full-text.
3. **API** — REST para a SPA, WebDAV para o Obsidian e MCP para assistentes ([[wiki/Integração com assistentes de IA]]).
4. **Scheduler** — um único cron por instalação, conforme [[adr/ADR-002 Hospedagem compartilhada como alvo]].

## Restrições

Sem daemons, sem websockets, sem `exec`. Tudo cabe em PHP compartilhado — o motivo está em [[adr/ADR-002 Hospedagem compartilhada como alvo]] e o procedimento em [[runbooks/Runbook Deploy em hospedagem compartilhada]].
