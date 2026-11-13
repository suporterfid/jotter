# Vault no GitHub — spike de validação (V1–V8)

- **Issue:** #438 (Fase 2 do plano `docs/20261113-jotter-github-vault-implementation-plan.md`, §8)
- **Data:** 2026-11-13
- **Estado:** respondido com documentação pública e com o simulador de testes (`tests/Support/FakeGitHub.php`). **Falta a medição ao vivo** com um App e um repositório reais (ver §3). O código foi escrito para funcionar com qualquer uma das respostas possíveis; o que a medição pode mudar são números de configuração, não o desenho.
- **Script de referência:** `scripts/github-vault-spike.sh` (sem credenciais; lê `GH_TOKEN` e `GH_REPO` do ambiente; usar só num repositório privado de teste).

## 1. Resumo

| # | Pergunta | Resposta provisória | Como o código lida com as duas respostas | Medir ao vivo? |
|---|---|---|---|---|
| V1 | A cota de escrita é por instalação ou por App? | A documentação trata a cota de tokens de instalação **por instalação** (mínimo de 5.000 req/h, escala com usuários e repositórios). Os limites secundários de criação de conteúdo (80/min, 500/h) não dizem a quem se aplicam. | `GitHubWriteBudget` guarda um balde **por ator** (`actorKey`: instalação ou PAT) com 400/h e 60/min, abaixo do limite secundário. Um 403/429 de cota bloqueia o balde pelo `Retry-After` e o flush é só adiado (60 s, 300 s, 900 s), nunca perde dados. Se a cota for por App, basta baixar `JOTTER_GITHUB_WRITES_PER_HOUR` ou registrar mais de um App. | **Sim** |
| V2 | `GET /git/ref` com `If-None-Match` devolve `304` sem gastar cota? | A documentação diz que respostas `304` não contam na cota primária quando a requisição é autenticada. | `vault_sync_states.ref_etag` guarda o ETag; um `304` encerra o pull sem nenhuma outra chamada. Se gastar cota, o pull de segurança (5 min) custa 12 req/h por workspace, ainda folgado. | Sim (barato) |
| V3 | Tamanho máximo de `content` inline em `POST /git/trees` | Não documentado. | Texto UTF-8 até **1 MiB** vai inline; acima disso, ou binário, vai por `POST /git/blobs` (`GitHubGit::INLINE_MAX_BYTES`). Custa uma escrita a mais só para textos grandes, que são raros. | Sim, para subir o teto |
| V4 | Compare com mais de 300 arquivos e com histórico reescrito | A documentação limita `files` a 300 no compare. | O pull **não usa o compare** para achar mudanças: diff da árvore recursiva contra o manifesto (`vault_remote_files`). O compare só detecta force push (`diverged`/`behind`, ou `404` do head antigo). Árvore truncada (> 100 mil entradas ou 7 MB) falha com mensagem clara. | Não |
| V5 | Token de instalação restrito com `repositories=[repo]` numa instalação "selected repositories" | Documentado como suportado no `POST /app/installations/{id}/access_tokens`. | `GitHubAppAuth::installationToken()` sempre pede `repositories=[repo]` e `permissions={contents: write, metadata: read}`; o token fica em cache cifrado até 5 min antes de expirar. Um `422` aqui vira erro permanente do armazenamento, visível no "Testar conexão". | **Sim** |
| V6 | Latência do webhook `push` e `afterResponse` no PHP-FPM | GitHub não promete latência; na prática são segundos. | O webhook responde `202` e faz o pull depois da resposta (`app()->terminating`, que no FPM roda após `fastcgi_finish_request`), limitado a 20 s / 200 arquivos. Se a hospedagem não liberar a resposta antes, `vault:pull --pending` (cron a cada minuto) termina o trabalho; o pior caso é ~1 min. | **Sim** |
| V7 (novo) | `PUT /contents` num repositório vazio com ramo diferente do padrão | A Git Database API responde `409` em repositório vazio; o `PUT /contents` cria o primeiro commit. Com `branch` diferente do padrão, a documentação não é clara. | O flush cria o primeiro arquivo pelo `PUT /contents` com `branch` e depois faz o lote normal (2 commits no total). Se falhar, o erro aparece no "Testar conexão" e o administrador escolhe o ramo padrão. | Sim |
| V8 (novo) | O `GET /repos/{owner}/{repo}` com token de instalação traz `permissions`? | Nem sempre. | `GitHubProbe` só recusa por falta de `push` quando o campo vem; sem ele, confia na permissão pedida no token. | Sim (barato) |

## 2. Evidência com o simulador

`tests/Support/FakeGitHub.php` imita a API (refs com ETag e `304`, `409` em repositório vazio, `422` em ref não fast-forward, cota com `403` e `Retry-After`, JWT RS256 do App, instalação com `repositories`). Os testes em `tests/Feature/GitHubVault/` cobrem:

- **Escrita em lote:** `GitHubFlushTest`: 1 lote = árvore + commit + ref; binários e textos > 1 MiB por blob; repositório vazio; cota esgotada adia; queda no meio do commit é recuperada sem commit duplicado.
- **Leitura:** `GitHubPullTest`: `304` não toca nada; diff de árvore; force push gera aviso; nomes NFD e gêmeos de maiúsculas; arquivos ignorados.
- **Conflitos:** `GitHubPullTest` e `GitHubFlushTest`: cópia `(conflito GitHub …)`, nada se perde.
- **Conexão:** `GitHubConnectTest` e `GitHubWebhookTest`: App, PAT, manifest, assinatura HMAC, entrega repetida, desinstalação.
- **Mudança de armazenamento:** `GitHubMoveTest`.
- **Ciclo de vida:** `GitHubLifecycleTest`.

## 3. Como medir ao vivo (pendente)

Pré-requisitos: um repositório **privado e vazio** de teste, um App de teste instalado em duas organizações (para V1) e um token de instalação de cada uma.

1. `GH_TOKEN=<token> GH_REPO=org/jotter-spike ./scripts/github-vault-spike.sh all`: anote as linhas `x-ratelimit-*` e os códigos HTTP.
2. **V1:** rode `v1` e um lote (`v4`) com o token da instalação A, depois `v1` com o da instalação B. Se `x-ratelimit-used` de B já refletir o gasto de A, a cota é por App.
3. **V5:** gere o token com `repositories=["jotter-spike"]` numa instalação "selected repositories" (o "Testar conexão" do Jotter já faz isso).
4. **V6:** com o Jotter no servidor alvo, faça um `git push` e meça o tempo até a nota aparecer (log de `vault.pull` no `audit_log`).
5. Registre os números neste documento (§4) e ajuste `JOTTER_GITHUB_WRITES_PER_HOUR`/`_PER_MINUTE` e `GitHubGit::INLINE_MAX_BYTES` se preciso.

## 4. Resultados ao vivo

_A preencher depois da medição._

## Fontes

- Limites de taxa da REST API (primário e secundário; `304` não conta): https://docs.github.com/rest/using-the-rest-api/rate-limits-for-the-rest-api
- Git Database API (blobs, trees, commits, refs): https://docs.github.com/rest/git
- Compare commits (limite de 300 arquivos): https://docs.github.com/rest/commits/commits#compare-two-commits
- Token de instalação com `repositories` e `permissions`: https://docs.github.com/rest/apps/apps#create-an-installation-access-token-for-an-app
- Webhooks e assinatura `X-Hub-Signature-256`: https://docs.github.com/webhooks/using-webhooks/validating-webhook-deliveries
