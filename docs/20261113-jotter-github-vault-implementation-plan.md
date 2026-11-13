# Vault da organização num repositório privado do GitHub — plano de implementação em fases

- **Data:** 2026-11-13
- **Status:** Plano proposto. Detalha o driver GitHub do plano geral. Nenhum código antes da Fase 0 (regra de `BACKLOG.md`).
- **Pedido:** uma organização (tenant) pode mover o **vault padrão** dela da pasta local, lida e escrita pelo PHP, para um **repositório privado no GitHub**. O vault padrão é o workspace que `TenantProvisioner` cria junto com o tenant.
- **Base (Fase 0):** a PR #426 (`docs/20261113-jotter-pluggable-vault-stores-plan.md`, decisões D1–D10, PRs A–H). Este documento **não reabre** nenhuma dessas decisões. Ele detalha as PRs **F** e **G** daquele plano: o que cada fase entrega, como testar e o que ainda precisa ser confirmado. Onde este plano propõe algo novo, a proposta está marcada como **G1, G2…** e entra no registro de decisão da PR A.
- **Custos e provedores:** `docs/20261113-jotter-tenant-vault-channels-research.md`.

---

## 1. Resumo (para decidir em 2 minutos)

1. **Um repositório por workspace** (D1). O vault padrão da org vira **um** repositório privado. Um repositório compartilhado por várias orgs fica descartado: o GitHub dá acesso ao repositório inteiro, então seria vazamento entre clientes.
2. **GitHub App** no modo hospedado, **fine-grained PAT** como alternativa no self-hosted (D7). O token do App dura 1 hora, vale só para os repositórios que a org escolheu e precisa só de `Contents: read/write`.
3. **Salvar continua instantâneo.** A edição vai para um *journal* no MySQL e sobe para o GitHub em **commits em lote** a cada 1–5 min (D3). O motivo é o limite do GitHub: **80 escritas/min e 500/h**.
4. **Nada se perde em conflito** (D8). Se alguém editou o mesmo arquivo fora do Jotter, as duas versões ficam visíveis: a do Jotter no caminho original, a do GitHub numa cópia de conflito.
5. **Custo no GitHub: zero.** Repositório privado, GitHub App e API são grátis, inclusive no plano Free. O custo real é **desenvolvimento: ≈ 13–19 dias** depois dos pré-requisitos da PR #426 (§7).

---

## 2. O que a Fase 0 (PR #426) já fixou

| Decisão | O que vale aqui |
|---|---|
| D1 | 1 workspace = 1 repositório = 1 branch. `unique(vault_store_id)` para o driver `github`. |
| D2 | O repositório é um *store* registrado na org (`vault_stores`); o workspace aponta para ele. |
| D3 | Escrita *write-behind*: journal local + flush em lote pelo cron. |
| D5 | Anexos vão no próprio repositório, até 10 MB por arquivo (configurável, nunca acima de 100 MiB). Sem Git LFS na v1. |
| D6 | A org cria o repositório privado vazio. O Jotter não cria repositórios. |
| D7 | GitHub App (hospedado) ou fine-grained PAT de um repositório (self-hosted). |
| D8 | Conflito gera cópia `Nome (conflito GitHub AAAA-MM-DD HHMM).md`, revisão e notificação. |
| D9 | Durante a mudança, workspace só-leitura (HTTP 423). Origem guardada 7 dias para rollback. |
| D10 | `JOTTER_VAULT_DRIVERS=local` é o padrão. Sem configuração, nada muda. |

E as PRs de base: **A** (decisão), **B** (`VaultStore` + `LocalVaultStore`), **C** (`vault_stores`, credenciais, SSRF, doctor), **E** (motor `vault:move`). A **D** (driver S3) não é pré-requisito do GitHub (ver §7, pergunta P1).

---

## 3. Repositório por tenant × compartilhado

| Opção | Permissões | LGPD / exclusão | Limites do GitHub | Veredito |
|---|---|---|---|---|
| **Um repositório do operador para todas as orgs** (pasta por tenant) | Quem acessa o repositório vê **todos os clientes** | Excluir um cliente exige reescrever histórico | Um só branch: 500 escritas/h para todo mundo; estoura 1–5 GB rápido | **Descartado.** Vazamento entre clientes. |
| **Um repositório por org** (pasta por workspace) | Quem acessa vê **todos os workspaces** da org, inclusive os restritos no Jotter | Excluir um workspace deixa o conteúdo no histórico | Workspaces disputam o mesmo ref | Fica para depois ("monorepo", só com demanda). O modelo já guarda `vault_prefix`, então não exige migração. |
| **Um repositório por workspace** (D1) | Acesso no GitHub = acesso ao workspace | Arquivar/apagar o repositório resolve | Cada workspace tem seu ref | **Escolhido.** |

**Quem é dono do repositório.** O repositório fica na conta ou na org GitHub **do cliente** (D6). Isso dá ao cliente a saída anti-lock-in ("suas notas estão no seu GitHub") e tira do operador a responsabilidade pelo histórico. Um modo "repositório gerenciado pelo operador" exigiria permissão de administração no GitHub e fica fora.

---

## 4. Autenticação

### 4.1 Comparação

| Método | Escopo | Validade | Cota de escrita | Uso |
|---|---|---|---|---|
| **GitHub App** (instalação) | Só os repositórios escolhidos na instalação; `Contents: RW` + `Metadata: R` | Token de 1 h, renovado pelo Jotter com JWT de 10 min | Por instalação (ver §8, V1) | **Hospedado** e self-hosted com App próprio |
| **Fine-grained PAT** | Um repositório; `Contents: RW` | Até 1 ano (política da org pode encurtar) | Conta na cota **da pessoa** dona do token | **Self-hosted** simples |
| PAT clássico | Todos os repositórios da pessoa | Pode não expirar | Da pessoa | **Recusado:** escopo amplo demais |
| OAuth App | Todos os repositórios que a pessoa acessa | Longa | Da pessoa | **Recusado:** escopo amplo; commits aparecem como a pessoa |
| Deploy key (SSH) | Um repositório | Não expira | — | **Recusado:** exige `git` CLI ou SSH, proibido pela §4 do spec |

### 4.2 GitHub App — fluxo de instalação (hospedado)

1. O **operador** registra um App uma vez (ex.: `cadernia-vault`). Configuração no `.env`:
   - `JOTTER_GITHUB_APP_ID`, `JOTTER_GITHUB_APP_SLUG`;
   - `JOTTER_GITHUB_APP_PRIVATE_KEY_PATH` (fora de `public/`, nunca no zip de release);
   - `JOTTER_GITHUB_WEBHOOK_SECRET` (opcional, Fase 7).
2. No admin do tenant, **Armazenamento → Conectar GitHub** redireciona para `https://github.com/apps/<slug>/installations/new?state=<nonce>`.
3. O cliente escolhe **"Only select repositories"** e marca o repositório vazio. O GitHub volta para o *Setup URL* com `installation_id` e `state`.
4. O Jotter confere o `state` (nonce de uso único, ligado ao tenant e ao admin), lista os repositórios da instalação e o admin escolhe o repositório e o branch.
5. **Sonda de conexão:** não cria commit de teste. Ela só lê (`GET /repos/{r}`, `GET /git/ref/heads/{branch}`) e confere `permissions.push`. O primeiro commit real é o do `vault:move`, para não gastar cota nem sujar o histórico.
6. Grava `vault_stores` com `settings = {owner, repo, branch, installation_id}`; não há segredo por tenant no modo App.

**Tokens.** JWT RS256 assinado com `phpseclib` (já no `composer.lock`) → `POST /app/installations/{id}/access_tokens` com `repositories=[repo]` e `permissions={contents: write}`, para o token valer só para aquele repositório. Cache do token cifrado (cast `encrypted`) até 5 min antes de expirar.

**Self-hosted com App próprio (G1).** Oferecer o *manifest flow* do GitHub: um botão no admin do operador cria o App com as permissões certas em um clique e devolve ID e chave. Evita erro de configuração manual.

### 4.3 Fine-grained PAT (self-hosted)

- O admin cola o token; ele vai cifrado para `vault_stores.credentials` e nunca volta pela API.
- O doctor avisa **14 dias antes** da expiração (`github-authentication-token-expiration` no header da resposta) e o workspace vira `degraded` se o token expirar.
- A tela explica que o token conta na cota pessoal de quem o criou.

### 4.4 Identidade dos commits (G2)

- **Autor:** o App (`<slug>[bot]`) ou o dono do PAT.
- **Mensagem:** `jotter: 3 notas por Ana (via Jotter)`, só com **nome de exibição**.
- **Sem e-mail no trailer `Co-authored-by` por padrão.** O plano geral sugere o trailer "quando há e-mail", mas o e-mail fica para sempre no histórico, legível por quem acessa o repositório. Proposta: trailer só se a pessoa ligar a opção no perfil, e usando o e-mail `noreply` do GitHub dela quando houver login GitHub vinculado. Menos dado pessoal no histórico, que o Jotter não consegue apagar (§6.1 do plano geral).

---

## 5. Sincronização

### 5.1 Peças

| Peça | Onde | Papel |
|---|---|---|
| `GitHubClient` | `app/Domain/Vault/Stores/GitHub/` | HTTP (`Http` do Laravel), tokens, ETag, leitura dos headers de cota, timeouts de 3 s/10 s |
| `GitHubVaultStore` | idem | Implementa `VaultStore` (PR B). `capabilities()`: `writeBehind=true`, `emptyDirs=false`, `maxFileBytes` |
| `vault_journal` | MySQL | Escritas pendentes (tabela definida no plano geral §4.2) |
| `vault_blob_cache` | MySQL | Conteúdo por SHA de blob, imutável; teto por workspace (padrão 50 MB, LRU) |
| `vault_sync_cursors` | MySQL | `remote_head`, `etag_of_ref`, `last_pull_at`, `last_flush_at` |
| `github_write_budget` | MySQL | Token bucket por ator (instalação ou PAT) |
| `vault:flush` | cron, todo minuto, `withoutOverlapping` | Envia o journal |
| `vault:pull` | cron, a cada 5 min | Traz mudanças externas |

Tudo roda por `schedule:run`, como `pdf:process-exports` e `semantic:index` hoje. Sem daemon, sem `git` CLI.

### 5.2 Gravar (caminho síncrono)

1. `VaultStorage::write` chama `GitHubVaultStore::write($path, $contents, $expectedVersion)`.
2. O store grava uma linha no `vault_journal` com `sha256`, `base_version` (o SHA de blob que a edição tinha como base) e `actor_id`, e atualiza `notes.content_hash` e a projeção, como hoje.
3. Se já existe linha pendente para o mesmo caminho, ela é **substituída** (coalescência). O `base_version` da primeira linha é mantido, porque é ele que detecta conflito.
4. A resposta volta em milissegundos. O SPA mostra **"pendente"** até o flush.

O hash que MCP (`expected_sha256`) e WebDAV (ETag) usam continua sendo o **SHA-256 do conteúdo**, não o SHA do Git. Clientes não mudam.

### 5.3 Ler

`journal pendente → vault_blob_cache (pelo SHA) → GET /git/blobs/{sha}` (grava no cache). Só abrir nota, exportar, PDF, publicar e WebDAV `GET` passam por aqui; busca, árvore e backlinks leem o MySQL.

### 5.4 Flush (um lote por workspace)

```
1. pega até N linhas pendentes do workspace (N=100) e marca flush_id
2. GET /git/ref/heads/{branch}   (If-None-Match; 304 não gasta cota)
3. se o head mudou desde remote_head → roda o pull (§5.5) antes e volta ao passo 1
4. POST /git/blobs para cada binário (anexo)                      ← 1 escrita por anexo
5. POST /git/trees  base_tree=head, entradas:
     texto  → content inline
     binário → sha do passo 4
     delete → sha: null
     move   → delete + add
6. POST /git/commits parents=[head]
7. grava commit_sha no flush_id (antes do passo 8: recuperação de falha)
8. PATCH /git/refs/heads/{branch} force=false
     200 → linhas viram flushed, remote_version = SHA dos blobs, remote_head = commit
     422 (não é fast-forward) → alguém fez push no meio: pull + refaz do passo 2
```

- **Custo:** 3 escritas por lote + 1 por anexo binário.
- **Falha entre 6 e 8:** na próxima rodada, se `commit_sha` existe e o head ainda é o pai dele, só repete o passo 8. Um commit órfão no GitHub é inofensivo.
- **Ordem entre workspaces:** rodízio pelo `last_flush_at` mais antigo, para uma org grande não travar as outras.
- **Retentativa:** backoff exponencial por workspace (1, 2, 4… até 30 min). Depois de 24 h sem sucesso, notificação aos admins e aviso no doctor.

### 5.5 Pull (mudanças feitas fora do Jotter)

1. `GET` do ref com `If-None-Match`. `304` → nada mudou, sem custo.
2. Head mudou → `GET /compare/{remote_head}...{novo}`.
   - Se o compare devolver mais de 300 arquivos ou `status=diverged` (alguém reescreveu o histórico com *force-push*), cai para o modo completo: `GET /git/trees/{novo}?recursive=1` e compara cada SHA com `remote_version`.
3. Para cada arquivo alterado: baixa o blob, aplica as regras de §5.6, reprojeta e grava revisão com ator `github:<login>` (ou `github` quando o autor não é um usuário GitHub conhecido).
4. Atualiza `remote_head` e o ETag.

O webhook `push` (Fase 7) só antecipa este pull. Sem webhook, o atraso máximo é o intervalo do cron.

### 5.6 Conflitos e casos de borda

| Situação | Comportamento |
|---|---|
| Só o Jotter mudou o arquivo | Flush normal (fast-forward) |
| Só o GitHub mudou | Pull atualiza a nota |
| Os dois mudaram **arquivos diferentes** | Flush refeito sobre o novo head (`base_tree` = árvore nova). Sem conflito. |
| Os dois mudaram **o mesmo arquivo** (`base_version` ≠ SHA remoto e conteúdos diferentes) | D8: Jotter fica no caminho original; versão do GitHub vira `Nome (conflito GitHub …).md`; revisão + notificação ao último editor no Jotter |
| Os dois chegaram ao **mesmo conteúdo** | Sem conflito; só atualiza `remote_version` |
| Jotter editou, GitHub apagou | Edição vence; o arquivo volta no próximo commit; notificação |
| Jotter apagou, GitHub editou | Edição remota vence; a nota sai da lixeira do Jotter com aviso |
| Renomeado no GitHub e editado no Jotter | Tratado como apagar + criar: cai na linha anterior, sem perda |
| Dois caminhos que só diferem em maiúsculas (`Nota.md`/`nota.md`) | O segundo é importado como `nota (2).md` e o doctor avisa (WebDAV e Windows não suportam os dois) |
| Caminho em NFD (macOS) | Normalizado para NFC pelo `VaultPathNormalizer` (PR B) |
| `.git*`, submódulos, symlinks, arquivos acima do limite | Ignorados e listados no doctor; nunca apagados pelo Jotter |
| Branch apagado, repositório renomeado/transferido, App desinstalado, PAT revogado | Store `failed`, workspace só-leitura com o conteúdo do cache, aviso aos admins. O journal **não** é descartado. |

Merge de texto linha a linha (3-way) fica **fora da v1**. Cópia de conflito é previsível e não perde nada; merge automático pode vir depois com decisão própria.

### 5.7 Orçamento de escrita

- Bucket por ator: **400 escritas/h e 60/min** (folga sob 500 e 80). Os valores ficam em `config/jotter.php`.
- Os headers `x-ratelimit-remaining` e um `403/429` com `retry-after` têm prioridade sobre o cálculo local.
- Com o bucket baixo, o intervalo do flush cresce (1 → 5 → 15 min) e o SPA mostra "sincronização atrasada".
- **A escrita do usuário nunca falha por cota.** Só atrasa.
- **Capacidade estimada:** com lotes de até 100 arquivos, 400 escritas/h ≈ 130 commits/h ≈ 13 mil arquivos alterados por hora por ator. Folga grande para equipes; o que aperta é o modo hospedado se a cota for **por App** e não por instalação (§8, V1).

---

## 6. Custos

### 6.1 GitHub (para a org)

| Item | Custo |
|---|---|
| Repositório privado (Free, Team ou Enterprise) | US$ 0 |
| GitHub App e chamadas de API | US$ 0 |
| Armazenamento no repositório | US$ 0 até o limite recomendado de 1 GB (forte em 5 GB) |
| Git LFS (fora da v1) | US$ 0,07/GiB-mês acima de 10 GiB grátis |

O "custo" real do GitHub são os **limites**: 100 MiB por arquivo, ~1 GB por repositório, 500 escritas/h. O doctor avisa a partir de 800 MB.

### 6.2 Operador (por workspace no GitHub)

| Perfil (da pesquisa §5) | Journal no MySQL | Cache de blobs | Chamadas/dia (estimativa) |
|---|---|---|---|
| P — pessoal (1 GB, 5 mil gravações/mês) | < 1 MB (linhas apagadas após o flush; retenção de 7 dias) | ≤ 50 MB | ≈ 300 pulls com 304 + ≈ 50 commits |
| E — equipe de 10 | < 10 MB | ≤ 50 MB | ≈ 300 pulls + ≈ 300 commits |
| O — org de 50 | < 50 MB | ≤ 200 MB (teto configurável) | ≈ 300 pulls + ≈ 1.000 commits |

Em hospedagem compartilhada o que importa é **CPU do cron** e **espaço no MySQL**, não dinheiro. O cache fica no MySQL (não em arquivos) para não gastar inodes. Comparado ao S3 (US$ 0,04–1,75 por tenant/mês, pesquisa §5.2), o GitHub é mais barato em infraestrutura e mais caro em código.

### 6.3 Desenvolvimento

| Bloco | Dias* |
|---|---|
| Pré-requisitos da PR #426 (A, B, C, E) | 10–14 |
| Driver GitHub (Fases 2–8 abaixo) | 13–19 |
| **Total até o GitHub em produção** | **≈ 23–33 dias** (≈ 5–7 semanas) |

\* Dias de desenvolvimento concentrado, com testes. Servem para comparar, não como prazo.

---

## 7. Fases

Cada fase é uma PR, mesclada com `jt ci` verde antes da próxima (AGENTS.md). As fases 2–8 substituem as PRs **F** e **G** do plano geral, que ficaram grandes demais para uma PR cada (G: 5–7 dias).

### Fase 0 — Plano e decisão *(PR #426 + PR A)*

- **Entrega:** PR #426 mesclada; PR A grava em `docs/decisions.md` o registro datado que altera o spec §1/§2 e aceita D1–D10, mais as propostas deste documento (G1 manifest flow, G2 identidade dos commits, G3 divisão de F/G em fases).
- **Aceite:** registro mesclado. Nenhum código.

### Fase 1 — Base comum *(PRs B, C, E da #426)*

- **Entrega:** `VaultStore` + `LocalVaultStore` (sem mudança de comportamento), `vault_stores` com credenciais cifradas e doctor, motor `vault:move`.
- **Aceite:** os da #426. A suíte atual passa sem mudanças.

### Fase 2 — Spike de validação *(pode correr em paralelo com a Fase 1)* — 0,5–1 dia

- **Entrega:** script descartável contra um repositório de teste, e uma nota em `docs/` com os números medidos para V1–V5 (§8).
- **Aceite:** cada item de §8 respondido com evidência (headers, códigos de resposta). Se V1 mostrar que a cota é por App, revisar §5.7 antes da Fase 5.

### Fase 3 — Conexão e autenticação *(PR F1)* — 2–3 dias

- **Entrega:**
  - `GitHubClient` (tokens do App e PAT, ETag, leitura de cota, timeouts);
  - fluxo de instalação (`state`, Setup URL, escolha de repositório e branch);
  - formulário de PAT para self-hosted;
  - sonda de conexão só-leitura, auditoria `vault_store.*`, checagens no doctor (chave do App, expiração do PAT, permissão `push`);
  - `JOTTER_VAULT_DRIVERS` aceita `github`.
- **Testes:** `Http::fake` para o GitHub; JWT conferido contra uma chave de teste gerada no próprio teste (nada de chave no repositório).
- **Aceite:** um admin conecta um repositório e vê "conexão ok"; sem `github` em `JOTTER_VAULT_DRIVERS` a tela nem aparece.

### Fase 4 — Driver de leitura *(PR F2)* — 2–3 dias

- **Entrega:** `GitHubVaultStore::read/readStream/stat/list`, `vault_blob_cache`, reindex por árvore recursiva, `vault:pull` só com o caminho "só o GitHub mudou".
- **Testes:** um **GitHub falso em memória** (refs, árvores, blobs, commits, compare, ETag, 422) usado por toda a suíte; `VaultStoreContractTest` da PR B rodando no driver.
- **Aceite:** um repositório com notas existentes aparece no Jotter, buscável, com backlinks iguais aos da pasta local.

### Fase 5 — Escrita em lote *(PR G1)* — 3–4 dias

- **Entrega:** `vault_journal`, `vault:flush` (§5.4) com recuperação de falha, `github_write_budget` (§5.7), selo **pendente / sincronizado / atrasado** no SPA, eventos de auditoria `vault.flush_failed`.
- **Aceite:** editar no Jotter gera commit em ≤ 5 min; dez edições da mesma nota viram um commit; matar o processo entre o commit e o `PATCH` não duplica nem perde nada; com a cota esgotada, a edição salva e só atrasa.

### Fase 6 — Conflitos e mudanças externas *(PR G2)* — 2–3 dias

- **Entrega:** todas as linhas de §5.6, cópias de conflito (D8), notificação via `NoteNotificationService`, revisão com ator `github:<login>`, detecção de *force-push*.
- **Aceite:** um teste por linha de §5.6; edição simultânea nunca perde conteúdo.

### Fase 7 — Mudança de armazenamento e webhook *(PR G3)* — 2–3 dias

- **Entrega:**
  - `vault:move` local → GitHub e GitHub → local, ~100 arquivos por commit, verificação pelo SHA de blob calculado localmente, rollback;
  - endpoint opcional `POST /webhooks/github` (assinatura `X-Hub-Signature-256`, eventos `push`, `installation`, `installation_repositories`), que só agenda um `vault:pull` ou marca o store como `failed`.
- **Aceite:** o vault padrão de uma org vai para o GitHub e volta sem perder arquivo (SHA conferido); sem webhook tudo funciona, só com até 5 min de atraso.

### Fase 8 — Lançamento *(PR H, parte GitHub)* — 1–2 dias

- **Entrega:** guia "Conectar o GitHub" (com o aviso de que quem acessa o repositório vê o workspace inteiro), `deployment.md` (registro do App, manifest flow), `hosted-operations.md` (cota, doctor), rascunho da política de privacidade (histórico Git), CHANGELOG, `STATUS.md` e `BACKLOG.md`.
- **Lançamento gradual:** liberar por tenant (beta) antes de ligar para todos.
- **Aceite:** critérios do plano geral §8 que tocam o GitHub, conferidos num repositório real.

---

## 8. Pendências de verificação (Fase 2)

| # | Pergunta | Por que importa |
|---|---|---|
| V1 | O limite de 80/min e 500/h de escrita vale **por instalação** ou **por App** inteiro? | No hospedado, um App atende todas as orgs. Se for por App, 200 tenants dividem 500 escritas/h e o intervalo do flush precisa crescer ou o operador precisa de mais de um App. |
| V2 | `GET /git/ref` com `If-None-Match` devolve `304` sem gastar a cota primária com token de instalação? | Base do pull a cada 5 min sem custo. |
| V3 | Tamanho máximo de `content` inline em `POST /git/trees` | Define quando um texto grande vira `POST /git/blobs` à parte. |
| V4 | Comportamento do compare com mais de 300 arquivos e com histórico reescrito | Define quando cair para a árvore recursiva. |
| V5 | Restrição do token de instalação a um repositório com `repositories=[repo]` funciona com a instalação "selected repositories" | Mínimo privilégio por workspace. |

## 9. Perguntas para o dono do produto

- **P1 — GitHub antes do S3?** O plano geral entrega o S3 primeiro (MVP após A–E). Se o GitHub for a prioridade, dá para pular a PR D e começar as Fases 3–8 logo depois de B, C e E. Isso economiza ≈ 3–4 dias até o GitHub, mas o motor `vault:move` passa a ser validado primeiro com o driver mais difícil.
- **P2 — Webhook na v1?** Sem ele, mudanças feitas fora aparecem em até 5 min. Com ele, em segundos, mas o operador precisa de URL pública e segredo.

## 10. Riscos

1. **Cota do GitHub por App (V1).** Mitigação: medir na Fase 2; orçamento adaptativo; opção de vários Apps por operador.
2. **Divergência entre journal e GitHub após falha.** Mitigação: `commit_sha` gravado antes do `PATCH`, testes de recuperação na Fase 5, reconcile completo no `vault:reindex`.
3. **Histórico do Git guarda dado apagado.** Mitigação: a org confirma ser controladora ao conectar; mínimo de dado pessoal nos commits (G2); política de privacidade.
4. **Tamanho do repositório.** Mitigação: limite de 10 MB por anexo, aviso aos 800 MB, LFS como evolução com decisão própria.

## Fontes

- GitHub: [limites da REST API](https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api), [boas práticas (requisições condicionais)](https://docs.github.com/en/rest/using-the-rest-api/best-practices-for-using-the-rest-api), [Git Database API (blobs, trees, commits, refs)](https://docs.github.com/en/rest/git), [autenticar como instalação de GitHub App](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/authenticating-as-a-github-app-installation), [manifest flow](https://docs.github.com/en/apps/sharing-github-apps/registering-a-github-app-from-a-manifest), [fine-grained PATs](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens), [validar webhooks](https://docs.github.com/en/webhooks/using-webhooks/validating-webhook-deliveries), [arquivos grandes](https://docs.github.com/en/repositories/working-with-files/managing-large-files/about-large-files-on-github)
