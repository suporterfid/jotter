---
type: adr
status: aceito
date: 2026-02-10
tags: [adr]
---
# ADR-001: Adotar Markdown como fonte da verdade

## Contexto
Precisávamos de uma base de conhecimento que sobrevivesse à ferramenta. Bancos proprietários prendem os dados; arquivos abertos não.

## Decisão
Cada nota é um arquivo `.md` com front matter YAML. O banco de dados guarda apenas projeções reconstruíveis (busca, links, propriedades). Ver [[wiki/Arquitetura da plataforma]].

## Consequências
- Obsidian e qualquer editor de texto funcionam sobre os mesmos arquivos.
- Reindexar é sempre possível; corrupção do índice não perde conteúdo.
- Operações em massa precisam ser cuidadosas com o sistema de arquivos ([[wiki/Política de backups]]).

## Alternativas consideradas
- Banco relacional como fonte: rejeitado por aprisionamento.
- Notion/Confluence: rejeitados por custo e exportação incompleta — ver [[reunioes/2026-06-17 Retrospectiva da migração]].
