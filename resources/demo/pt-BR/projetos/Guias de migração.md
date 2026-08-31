---
tags: [projeto, migracao]
---
# Guias de migração

## Obsidian
O export é a própria pasta do vault compactada. O modo de import "Obsidian" remove a pasta raiz e ignora `.obsidian/` e `.trash/`; wikilinks continuam funcionando.

## Notion
O export Markdown traz ids de 32 caracteres nos nomes e links URL-encoded. O modo "Notion" remove os ids, decodifica os links e converte referências a páginas em wikilinks. Bancos de dados chegam como CSV e precisam de conversão manual — lição da [[reunioes/2026-06-17 Retrospectiva da migração]].

## Checklist
- [ ] Exportar
- [ ] Importar com a origem correta
- [ ] Conferir backlinks e anexos
- [ ] Conectar o assistente ([[runbooks/Runbook Conectar um assistente por MCP]])
