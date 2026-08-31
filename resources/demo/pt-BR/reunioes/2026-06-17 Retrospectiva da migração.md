---
type: meeting
date: 2026-06-17
attendees: [Marina Duarte, Leila Nogueira, Rafael Lins]
tags: [reuniao, retrospectiva, migracao]
---
# Retrospectiva da migração — 2026-06-17

Saímos do Notion para arquivos Markdown ([[adr/ADR-001 Adotar Markdown como fonte da verdade]]).

## O que funcionou
- Export Markdown do Notion preservou o texto e as tabelas simples.

## O que doeu
- Nomes de arquivo com ids de 32 caracteres e links URL-encoded — motivou o modo de import "Notion" descrito em [[projetos/Guias de migração]].
- Bancos de dados exportados como CSV precisaram virar tabelas Markdown à mão.

## Ações
- [x] Rafael — escrever o guia de migração — 2026-07-01
