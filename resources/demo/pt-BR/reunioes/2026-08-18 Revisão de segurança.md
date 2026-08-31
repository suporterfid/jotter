---
type: meeting
date: 2026-08-18
attendees: [Caio Ferreira, Rafael Lins]
tags: [reuniao, seguranca]
---
# Revisão de segurança — 2026-08-18

## Notas
- Tokens de máquina são armazenados apenas como hash SHA-256; revogação é imediata.
- Import de ZIP protege contra zip-slip, symlinks e extensões perigosas — validar com exports reais ([[projetos/Guias de migração]]).
- Vault deve ficar fora do document root; o `jotter:doctor` falha quando não fica.

## Decisões
- Manter [[adr/ADR-004 Ferramentas MCP somente leitura]] até haver escopos finos.

## Ações
- [ ] Caio — incluir verificação de token revogado no [[runbooks/Runbook Conectar um assistente por MCP]] — 2026-08-25
