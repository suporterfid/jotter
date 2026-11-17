# Vault no GitHub — spike de validação (V1–V8)

- **Issue:** #438 (Fase 2 do plano `docs/20261113-jotter-github-vault-implementation-plan.md`, §8)
- **Data:** 2026-11-13
- **Estado:** respondido com documentação pública e com o simulador de testes (`tests/Support/FakeGitHub.php`), e **medido ao vivo em 2026-11-16/17** (§4) com um App real numa hospedagem compartilhada (Hostinger, PHP 8.3). V2–V7 confirmaram o desenho; V8 achou um bug, corrigido no #456; V1 ficou parcial (falta uma segunda conta para comparar instalações).
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
| V8 (novo) | O `GET /repos/{owner}/{repo}` com token de instalação traz `permissions`? | Traz, mas com **todas as flags `false`** (medido, §4). | Corrigido no #456: no modo App, `GitHubProbe` confere a permissão concedida ao token (`contents: write`, da resposta de `access_tokens`); `permissions.push` só vale para PAT. | Feito |

## 2. Evidência com o simulador

`tests/Support/FakeGitHub.php` imita a API (refs com ETag e `304`, `409` em repositório vazio, `422` em ref não fast-forward, cota com `403` e `Retry-After`, JWT RS256 do App, instalação com `repositories`). Os testes em `tests/Feature/GitHubVault/` cobrem:

- **Escrita em lote:** `GitHubFlushTest`: 1 lote = árvore + commit + ref; binários e textos > 1 MiB por blob; repositório vazio; cota esgotada adia; queda no meio do commit é recuperada sem commit duplicado.
- **Leitura:** `GitHubPullTest`: `304` não toca nada; diff de árvore; force push gera aviso; nomes NFD e gêmeos de maiúsculas; arquivos ignorados.
- **Conflitos:** `GitHubPullTest` e `GitHubFlushTest`: cópia `(conflito GitHub …)`, nada se perde.
- **Conexão:** `GitHubConnectTest` e `GitHubWebhookTest`: App, PAT, manifest, assinatura HMAC, entrega repetida, desinstalação.
- **Mudança de armazenamento:** `GitHubMoveTest`.
- **Ciclo de vida:** `GitHubLifecycleTest`.

## 3. Como medir ao vivo

Pré-requisitos: um repositório **privado e vazio** de teste, um App de teste instalado em duas organizações (para V1) e um token de instalação de cada uma.

1. `GH_TOKEN=<token> GH_REPO=org/jotter-spike ./scripts/github-vault-spike.sh all`: anote as linhas `x-ratelimit-*` e os códigos HTTP.
2. **V1:** rode `v1` e um lote (`v4`) com o token da instalação A, depois `v1` com o da instalação B. Se `x-ratelimit-used` de B já refletir o gasto de A, a cota é por App.
3. **V5:** gere o token com `repositories=["jotter-spike"]` numa instalação "selected repositories" (o "Testar conexão" do Jotter já faz isso).
4. **V6:** com o Jotter no servidor alvo, faça um `git push` e meça o tempo até a nota aparecer (log de `vault.pull` no `audit_log`).
5. Registre os números neste documento (§4) e ajuste `JOTTER_GITHUB_WRITES_PER_HOUR`/`_PER_MINUTE` e `GitHubGit::INLINE_MAX_BYTES` se preciso.

## 4. Resultados ao vivo

Medido em 2026-11-16 e 2026-11-17 a partir do servidor da instância (Hostinger, hospedagem compartilhada, PHP-FPM 8.3), com o App registrado pelo painel (manifest), instalado na conta `suporterfid` com "selected repositories": `jotter-spike` (sondas V1–V5, V7, V8) e `jotter-spike-vault` (V6, um workspace real movido para ele).

| # | Resultado | Consequência |
|---|---|---|
| V1 | **Parcial.** Token de instalação com `x-ratelimit-limit: 5000` (cota `core`). Um lote (árvore + commit + ref) gasta 3 chamadas; `GET /rate_limit` não conta. Os contadores `used` vinham intercalados entre requisições próximas, então só a ordem de grandeza é confiável. Sem uma segunda conta, não deu para ver se a cota é por instalação ou por App. | Nada a mudar: `GitHubWriteBudget` (400/h, 60/min por instalação) fica bem abaixo de 5.000/h. Repetir com duas instalações antes de abrir para muitos clientes num só App. |
| V2 | **Confirmado.** `GET /git/ref` com `If-None-Match` devolveu `304` e `x-ratelimit-used` não mudou (ficou em 7). | O pull de segurança a cada 5 min não gasta cota quando nada mudou. |
| V3 | **Mais folgado que o previsto.** `POST /git/trees` aceitou `content` inline de 100 KB, 1 MB, 5 MB e 20 MB (todos `201`). | `GitHubGit::INLINE_MAX_BYTES` (1 MiB) pode subir; fica como está até alguém precisar, já que textos grandes são raros e o caminho por blob funciona. |
| V4 | **Confirmado.** O compare de um commit com 320 arquivos alterados listou exatamente 300. | Nenhuma: o pull não depende do compare para achar mudanças. |
| V5 | **Confirmado.** O token pedido com `repositories=["jotter-spike"]` leu `jotter-spike` (`200`) e não enxergou `jotter-spike-vault` (`404`), embora o App esteja instalado nos dois. | Cada armazenamento só alcança o próprio repositório. |
| V6 | **Confirmado, bem abaixo da meta G5 (≤ 10 s).** Quatro `PUT /contents` direto no GitHub: o webhook chegou em 1,5–2,5 s e a nota apareceu no Jotter em **3,5–4,5 s**, pelo pull logo após a resposta `202` (o `fastcgi_finish_request` funciona nessa hospedagem). | O cron (`vault:pull --pending`) fica só como reserva. |
| V7 | **Confirmado no ramo padrão.** Em repositório vazio, `GET /git/ref` responde `409`; `PUT /contents/.gitkeep` com `branch: main` criou o primeiro commit (`201`). Ramo diferente do padrão não foi testado. | Nenhuma. |
| V8 | **Bug encontrado.** Com token de instalação, `GET /repos/{owner}/{repo}` traz `permissions` com tudo `false`, inclusive `push`. O "Testar conexão" recusaria todo armazenamento ligado pelo App. | Corrigido no #456 (ver §1) e validado em produção: o teste do armazenamento `jotter-spike-vault` passou. |

Achados no caminho, fora das perguntas V1–V8 (corrigidos no #456):

- `POST /app-manifests/{code}/conversions` responde `422 Invalid request` quando recebe o corpo `[]` que o `Http::post($url)` do Laravel manda. O registro do App pelo painel falhava sempre; agora as chamadas sem corpo vão sem corpo.
- Uma instalação nova não tinha organização nem administrador, e o painel ficava inacessível: virou o comando `jotter:install`.

## Fontes

- Limites de taxa da REST API (primário e secundário; `304` não conta): https://docs.github.com/rest/using-the-rest-api/rate-limits-for-the-rest-api
- Git Database API (blobs, trees, commits, refs): https://docs.github.com/rest/git
- Compare commits (limite de 300 arquivos): https://docs.github.com/rest/commits/commits#compare-two-commits
- Token de instalação com `repositories` e `permissions`: https://docs.github.com/rest/apps/apps#create-an-installation-access-token-for-an-app
- Webhooks e assinatura `X-Hub-Signature-256`: https://docs.github.com/webhooks/using-webhooks/validating-webhook-deliveries
