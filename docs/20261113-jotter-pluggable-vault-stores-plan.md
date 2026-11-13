# Vault plugável por organização — plano da feature

- **Data:** 2026-11-13
- **Status:** Plano proposto. A **PR A** grava a decisão em `docs/decisions.md`; nenhum código antes disso (regra de `BACKLOG.md`).
- **Pedido:** a pasta local, lida e escrita direto pelo PHP, continua sendo o padrão. A organização (tenant) pode **mover** o vault para:
  - um repositório **privado no GitHub**; ou
  - um **storage compatível com S3**: AWS S3, Cloudflare R2, MinIO, Google Cloud Storage.
- **Relação com a pesquisa anterior:** `docs/20261113-jotter-tenant-vault-channels-research.md` recomendava canais só como *réplicas*. Este plano **substitui** aquela recomendação, por decisão do dono do produto: o armazenamento remoto passa a ser o **primário** do workspace. As partes de custo, segurança e provedores daquela pesquisa continuam válidas.

---

## 1. Decisões de produto (com padrão recomendado)

| # | Pergunta | Padrão recomendado | Por quê |
|---|---|---|---|
| D1 | Um repositório por org ou vários? | **Vários por org, 1 workspace = 1 repositório (e 1 branch)**. Repositório único com subpastas por workspace fica para depois, só se houver demanda. | Ver §2. |
| D2 | Onde fica a configuração? | **"Armazenamentos" (stores) registrados na org**; cada workspace aponta para um store. Workspace sem store = pasta local, como hoje. | Uma org pode ter um bucket R2 e três repositórios ao mesmo tempo. |
| D3 | GitHub: salvar é imediato? | **Não.** O Jotter grava na hora num *journal* local e **envia ao GitHub em lotes**, um commit a cada 1–5 min. A interface mostra "pendente / sincronizado". | O GitHub permite no máximo **80 escritas/min e 500/h** por ator. O ator é a **instalação do GitHub App** na org (ou o PAT, no self-hosted), não o servidor Jotter. Se a cota vale por instalação ou pelo App inteiro ainda está em aberto (V1 em `docs/20261113-jotter-github-vault-implementation-plan.md`). Um commit custa 3 escritas, então são no máximo ≈ 160 commits/h por ator. Um commit por salvamento é inviável; em lote, um commit leva muitos salvamentos. |
| D4 | S3: salvar é imediato? | **Sim**, escrita direta e síncrona, com `If-Match` onde o provedor suportar. | S3, R2, MinIO e GCS têm consistência forte; um PUT leva ~50–150 ms. |
| D5 | Anexos no modo GitHub | **No próprio repositório**, até um limite configurável (padrão 10 MB/arquivo; o GitHub bloqueia > 100 MiB). Acima disso, recusa com mensagem clara. Git LFS fica para depois. | Quem clona o repositório (Obsidian, Claude Code) precisa ver as imagens. |
| D6 | Quem cria o repositório/bucket? | **A org cria** (repositório privado vazio, bucket vazio) e conecta no Jotter. O Jotter não cria repositórios. | Evita pedir permissão de administração no GitHub/IAM. |
| D7 | Autenticação no GitHub | **GitHub App** (hospedado: o operador registra uma vez e a org instala só no repositório escolhido). Para self-hosted, **fine-grained PAT** de um repositório. | Token curto (1 h), escopo `contents: write` num único repositório. |
| D8 | Conflito com edição feita fora do Jotter (push direto, Obsidian Git) | **Nada se perde.** A versão do Jotter fica no caminho original; a versão remota vira `Nome (conflito GitHub 2026-11-13 1420).md`, mais revisão e notificação ao autor. | Mesmo modelo do Dropbox: as duas versões ficam visíveis. |
| D9 | Durante a mudança de armazenamento | **Workspace só-leitura** (HTTP 423, com mensagem) até a cópia e a verificação terminarem. A origem fica guardada por 7 dias para rollback. | Mais simples e seguro que replicar escritas durante a cópia. |
| D10 | Quais drivers ficam ligados | Env `JOTTER_VAULT_DRIVERS=local` (padrão). O operador liga `s3` e/ou `github`. | Self-hosted sem configuração continua **idêntico** ao de hoje (princípio "hosting is configuration"). |

---

## 2. Repositório único × vários por org (D1)

| Critério | **1 repositório por org** (subpasta por workspace) | **1 repositório por workspace** (recomendado) |
|---|---|---|
| Permissões | O GitHub dá acesso ao repositório inteiro: quem acessa o repositório vê **todos** os workspaces, inclusive os que o Jotter restringe | Acesso no GitHub = acesso ao workspace; casa com as memberships do Jotter |
| Obsidian / `obsidian-git` / Claude Code | Clona tudo; o usuário precisa abrir uma subpasta como vault | 1 repositório = 1 vault, direto |
| Limite de escrita (500/h) | Todos os workspaces disputam o mesmo branch: mais conflitos de *fast-forward* | Cada repositório tem seu branch; a cota continua sendo por ator do GitHub (instalação do App ou PAT; ver D3), mas sem disputa de ref |
| Tamanho (recomendado < 1 GB, forte < 5 GB) | Estoura mais cedo | Distribuído |
| Excluir workspace / LGPD | Exige reescrever histórico ou apagar subpasta (que continua no histórico) | Arquivar/apagar o repositório resolve |
| Gestão | 1 repositório só | N repositórios: a org instala o App nos repositórios escolhidos |

**Conclusão:** permita **vários repositórios por org**, com **1 workspace por repositório** como regra na primeira versão (`unique(vault_store_id)` quando o driver é `github`). O modelo de dados já guarda `vault_prefix`, então o modo "monorepo" pode vir depois sem migração. No S3 vale o contrário: **1 bucket por org com prefixo por workspace** é o padrão natural (o IAM restringe por prefixo), e bucket por workspace também é permitido.

---

## 3. Impacto no código (inventário real)

**36 arquivos** usam `vault_path`/`VaultPathGuard` ou funções de arquivo diretas (`file_get_contents`, `RecursiveDirectoryIterator`, `realpath`, `response()->file()`…):

| Grupo | Arquivos | O que muda |
|---|---|---|
| Núcleo do vault | `VaultStorage`, `AttachmentStorage`, `NoteTrash`, `VaultReindexer`, `VaultPathGuard`, `VaultRootGuard`, `WorkspaceQuota` | Passam a usar `VaultStore`. O reindex lista pelo store; a quota usa o delta (já existe) e a listagem do store. |
| Protocolos | `WebDavController` (`is_dir`, `file_get_contents`, ETag do disco), `McpNoteWriter` (`diskHash`), `AttachmentController` (`response()->file`), `PublicShareAssetController`, `SharedAssetResolver`, `SharedNoteRenderer` | Leitura e ETag pelo store; anexos por *stream* (ou URL pré-assinada no S3). |
| Exportar/importar | `WorkspaceExportController`, `WorkspaceImportController`, `VaultExtractor`, `TenantExporter`, `WorkspaceJsonBackup`, `PdfAssetResolver`/`PdfDocumentRenderer`/`PdfExportRunner`, `WorkspacePublishController`, `BrainPack`, `TemplatePack`, `WorkspaceTemplateController` | Leem e gravam pelo store. O PDF baixa os assets para um diretório temporário. |
| Ciclo de vida | `TenantProvisioner`/`TenantProvisionCommand`, `TenantDeletion`/`TenantDeleteCommand`, `AdminWorkspaceController`, `WorkspaceController`, `InstanceBackup`/`JotterRestoreCommand`, `InstanceDoctor`, `DemoSeedCommand`, `Workspace` (model) | Provisionar/apagar no store; backup decide o que incluir (§6.4); doctor checa stores. |

O que **não** muda, porque a arquitetura já ajuda: busca, backlinks, tags, propriedades, árvore e listagens leem o **índice MySQL**, nunca o disco. Só abrir uma nota, exportar, WebDAV `GET`, PDF, publicação e reindex leem o arquivo. Por isso a latência de um store remoto aparece em poucos lugares.

---

## 4. Arquitetura

### 4.1 Interface `VaultStore`

```php
interface VaultStore
{
    public function read(string $path): string;                    // conteúdo
    public function readStream(string $path);                      // anexos grandes
    public function stat(string $path): ?StoreEntry;               // size, version (etag|git sha), mtime
    public function write(string $path, string $contents, ?string $expectedVersion = null): StoreEntry;
    public function delete(string $path, ?string $expectedVersion = null): void;
    public function move(string $from, string $to): void;          // S3: copy+delete; Git: um commit
    public function list(string $prefix, ?string $cursor, int $limit): StorePage; // paginado, para cron
    public function makeDirectory(string $path): void;             // S3: marcador "pasta/"; Git: .gitkeep
    public function capabilities(): StoreCapabilities;             // conditionalWrite, emptyDirs, maxFileBytes, writeBehind, presignedUrls
}
```

- **`VaultPathNormalizer`** (lógico, vale para todos): rejeita `..`, caminho absoluto, NUL, `\`, `.git/` e chave > 1024 bytes, e normaliza Unicode para **NFC** (o Obsidian no macOS grava NFD).
- **`LocalVaultStore`**: o comportamento atual, inclusive as checagens de `realpath`/symlink do `VaultPathGuard`.
- **`S3VaultStore`**: `async-aws/s3`, bem menor que `aws/aws-sdk-php`, o que importa para o zip de deploy. Endpoint, região, *path-style* (MinIO), bucket e prefixo. Grava `x-amz-meta-sha256` em todo objeto.
- **`GitHubVaultStore`**: API Git Data via `Http` do Laravel (Guzzle já está no lock). JWT do App assinado com `phpseclib`, que já está no lock, então PHP puro. Sem `git` CLI.
- **`VaultStoreResolver::for(Workspace)`** devolve o store certo; nenhum código fora do resolver conhece o driver.

### 4.2 Modelo de dados

| Tabela/coluna | Conteúdo |
|---|---|
| `vault_stores` | `tenant_id`, `name`, `driver` (`s3`, `github`), `settings` JSON (bucket, endpoint, região, prefixo base, repositório, branch), `credentials` (cast `encrypted:array`, nunca devolvido pela API), `status`, `last_checked_at`, `last_error` |
| `workspaces.vault_store_id` | nulo = local (`vault_path`, como hoje) |
| `workspaces.vault_prefix` | subcaminho dentro do store (S3: `<slug>/`; GitHub: `''` na v1) |
| `workspaces.storage_state` | `active`, `moving`, `degraded` |
| `notes.remote_version`, `attachments.remote_version` | ETag (S3) ou blob SHA (Git), para detectar mudança externa |
| `vault_journal` (GitHub) | `workspace_id`, `path`, `op` (`put`/`delete`), `content` (LONGBLOB) ou arquivo em spool para anexos, `sha256`, `base_version`, `actor_id`, `created_at`, `flushed_at`, `attempts`, `last_error` |
| `vault_blob_cache` (GitHub) | `blob_sha` → conteúdo, com TTL. É cache no MySQL em vez de arquivos, para não gastar inodes. Fica fora do backup e é apagado com o workspace. |
| `vault_sync_cursors` | por workspace: `remote_head`, `etag_of_ref`, `last_pull_at` |
| `vault_moves` | `workspace_id`, origem, destino, `cursor`, arquivos/bytes copiados, `status`, `error`, `retain_until` |

### 4.3 Fluxo S3 (síncrono)

1. **Gravar:**
   - `PUT` com `If-Match: <etag>` em AWS, R2 e MinIO;
   - no GCS (a confirmar), lock por nota no MySQL, que já existe, mais `HEAD` de comparação;
   - em seguida atualiza a projeção, como hoje.
2. **Ler:** `GET` (~50–150 ms), com memo por requisição.
3. **Mover pasta:** `CopyObject` + `DeleteObject` por objeto. Acima de N objetos vira job limitado por cron. Não usar `DeleteObjects` em lote, para manter compatibilidade com o GCS.
4. **Mudança externa** (alguém gravou direto no bucket): o `vault:reindex` compara o ETag da listagem com `remote_version` e só baixa o que mudou.
5. **Anexos:** *stream* pelo PHP, preservando ACL e compartilhamento. Para arquivos grandes, redirect para **URL pré-assinada de 60 s**, com `response-content-type` e `response-content-disposition` forçados.

### 4.4 Fluxo GitHub (write-behind)

```
salvar ──▶ vault_journal (MySQL, síncrono, rápido) ──▶ projeção/índice atualizados na hora
                    │
   cron vault:flush (a cada minuto, com orçamento)
                    ▼
   GET ref (1 leitura) → [compare se o head mudou] → POST trees (base_tree + conteúdo inline)
   → POST commits → PATCH refs (force=false)  ==  3 escritas por lote, +1 por anexo binário
```

- **Ler:** journal pendente → `vault_blob_cache` (pelo SHA, imutável) → `GET /git/blobs/{sha}` → grava no cache.
- **Orçamento:** um *token bucket* por ator do GitHub (instalação do App ou PAT, ver D3) de ≈ 400 escritas/h (folga sob 500). Se apertar, o intervalo do flush cresce sozinho (1 → 5 → 15 min) e a interface avisa.
- **Coalescência:** dez salvamentos da mesma nota entre dois flushes viram uma única entrada da árvore.
- **Mensagem do commit:** `jotter: 3 notas por Ana (via Jotter)`. O autor real fica como *co-author trailer* quando há e-mail.
- **Puxar mudanças externas** (`vault:pull`, a cada 5 min): `GET` do ref com `If-None-Match`; a resposta `304` não gasta cota. Se o head mudou, `compare` → baixa os blobs alterados → reprojeta → revisão com ator `github:<login>`. Um webhook `push` opcional antecipa o pull.
- **Reindex completo:** `GET /git/trees/{head}?recursive=1` (uma chamada, até 100 mil entradas) e só baixa os blobs cujo SHA difere de `remote_version`.
- **Limites a validar no preflight:**
  - arquivo ≤ limite configurado (≤ 100 MiB);
  - aviso no doctor quando o repositório passar de ~800 MB;
  - pastas vazias viram `.gitkeep`.

### 4.5 Mudar o armazenamento (`vault:move`)

Vale para local → S3, local → GitHub, S3 → GitHub e **qualquer remoto → local** (a saída anti-lock-in).

1. **Preflight:**
   - testa credencial com sonda de gravar/ler/apagar;
   - confere que o destino está vazio;
   - estima tamanho e número de arquivos;
   - checa os limites do driver e a quota.
2. **Workspace em `moving`:** escritas respondem 423 com mensagem; leituras continuam da origem.
3. **Cópia em lotes pelo cron** (`vault:process-moves --limit=200`), retomável pelo `cursor`. No GitHub, ~100 arquivos por commit.
4. **Verificação:** SHA-256 de cada arquivo no S3 (metadado); SHA de blob Git calculado localmente no GitHub.
5. **Troca atômica** de `vault_store_id` + reindex que só grava `remote_version`.
6. **Origem guardada** até `retain_until` (`JOTTER_VAULT_MOVE_RETAIN_DAYS=7`). `vault:move --rollback` volta; `vault:finalize-moves` apaga a origem com auditoria.

Estimativa em hospedagem compartilhada, para 2.000 notas + 300 anexos: S3 ≈ 10–15 min; GitHub ≈ 20–30 min (≈ 25 commits mais os blobs dos anexos, dentro da cota).

---

## 5. Segurança

- **Credenciais:** cifradas com `APP_KEY`, só escrita na API, nunca em log nem no zip de release. As checagens de segredo do `jt release` continuam.
- **Rotação de `APP_KEY`:** exige reconectar os stores. O `jotter:doctor` avisa.
- **SSRF:** endpoint S3 informado pela org precisa ser HTTPS público. IPs privados, loopback e link-local são bloqueados, com a resolução DNS checada na hora da conexão. **MinIO interno** só entra por allowlist do operador (`JOTTER_VAULT_ALLOWED_PRIVATE_HOSTS`).
- **Permissões:** só `owner`/`admin` do tenant criam stores e movem workspaces.
- **Auditoria:** `vault_store.created|tested|updated|deleted`, `vault.move_started|completed|failed|rolled_back`, `vault.conflict`, `vault.flush_failed`.
- **ACLs de nota:** continuam valendo nas superfícies do Jotter. **Quem tem acesso direto ao repositório/bucket vê tudo do workspace**, como já acontece com acesso direto à pasta (decisão de ACLs). A tela de conexão diz isso com todas as letras.
- **GitHub App:** permissões `Contents: RW` + `Metadata: R`. Chave privada do App fora de `public/`, por caminho no `.env`.

---

## 6. LGPD e operação

### 6.1 Histórico do Git

Apagar uma nota no GitHub cria um commit de remoção, mas **o conteúdo continua no histórico**. O Jotter não reescreve histórico (sem `git` CLI e sem *force-push*). Política proposta:

- ao conectar um repositório, a org confirma que é a **controladora** desse histórico;
- o `tenant:delete` apaga os arquivos num commit final e orienta a arquivar ou apagar o repositório;
- a política de privacidade explica isso.

### 6.2 Versionamento do bucket

Se o bucket tiver versionamento, o doctor recomenda uma regra de expiração das versões antigas em **≤ 30 dias**, a mesma janela já prometida para backups.

### 6.3 Eliminação replayed no restore

O `jotter:restore`, ao repetir *tombstones*, também apaga no store remoto (S3) ou faz commit de remoção (GitHub).

### 6.4 Backup

- **Workspaces no GitHub não entram no ZIP por padrão:** o histórico Git já guarda cada versão. `--with-remote-vaults` inclui.
- **Workspaces no S3 só ficam fora do ZIP quando o bucket tem versionamento ligado.** Sem versionamento, um apagão por engano (pelo Jotter, por outra ferramenta ou por credencial vazada) não teria de onde voltar, então o backup os inclui. O preflight do `vault:move` e o `jotter:doctor` checam o versionamento (`GetBucketVersioning`) e avisam quando estiver desligado; `--without-remote-vaults` deixa o operador excluir mesmo assim, por conta própria.
- **Depois de um restore**, esses workspaces recebem reindex automático: o store remoto é a verdade.

### 6.5 Falhas

| Situação | Comportamento |
|---|---|
| S3 fora do ar | Leitura/escrita respondem 503 "armazenamento externo indisponível"; workspace fica `degraded` |
| GitHub fora do ar | **Escrita continua** (vai para o journal); leitura sai do cache; o flush tenta de novo depois |
| Credencial revogada | Store `failed`, aviso no doctor + notificação aos admins, workspace só-leitura |

---

## 7. Sequência de PRs

Cada PR é mesclada com `jt ci` verde antes da próxima.

| PR | Entrega | Tamanho | Estimativa* |
|---|---|---|---|
| **A** | Decisão datada em `docs/decisions.md`, alterando o spec §1/§2 e a seção *Source of truth* de `docs/architecture.md`: o vault é Markdown em **disco local (padrão), bucket S3-compatível ou repositório Git**, sempre o mesmo layout de arquivos portáteis; registra D1–D10 | S | 0,5 dia |
| **B** | Interface `VaultStore` + `LocalVaultStore` + refatoração dos 36 arquivos, **sem mudar comportamento**; suíte de contrato `VaultStoreContractTest` | L | 4–6 dias |
| **C** | `vault_stores`: CRUD admin, credenciais cifradas, "testar conexão", guarda SSRF, auditoria, `JOTTER_VAULT_DRIVERS`, checagens no doctor | M | 2–3 dias |
| **D** | Driver **S3** + serviço **MinIO** no `compose.ci.yaml` para a suíte de contrato; anexos por stream/URL pré-assinada; reindex por listagem | M–L | 3–4 dias |
| **E** | Motor `vault:move` (preflight, cópia em lotes, verificação, troca, 423, rollback, retenção) + tela de progresso. **Aqui o S3 vira entregável (MVP).** | M–L | 3–4 dias |
| **F** | Driver **GitHub**, leitura: App + PAT, árvore recursiva, cache de blobs, `vault:pull` com ETag/webhook | M | 3 dias |
| **G** | Driver **GitHub**, escrita: journal, `vault:flush` com orçamento, conflitos (D8), selo "sincronizado/pendente" no SPA, suporte no `vault:move` | L | 5–7 dias |
| **H** | Docs: `deployment.md`, `hosted-operations.md`, rascunho da política de privacidade (histórico Git), guia "conectar GitHub/S3", CHANGELOG | S | 1 dia |

\* Estimativa grosseira de dias de desenvolvimento concentrado, com testes; serve para comparar as partes, não como prazo.

**MVP = A → E (≈ 2–3 semanas):** o S3 primário funcionando de ponta a ponta. O GitHub (F, G) vem depois porque o write-behind é a parte mais arriscada, e começar pelo S3 valida a interface e o motor de mudança com o caso simples.

**Depois (cada um com decisão própria):**
- GitLab/Forgejo;
- monorepo (vários workspaces num repositório);
- Git LFS para anexos grandes;
- driver nativo do GCS (API JSON);
- espelho secundário (réplica) a partir da pesquisa anterior.

### Riscos principais

1. **A PR B é grande e toca quase todo o domínio.** Mitigação: comportamento idêntico, suíte de contrato, e Local passando nela antes de qualquer driver novo.
2. **Cota de escrita do GitHub**, que pode mudar sem aviso. Mitigação: orçamento adaptativo + journal; a escrita nunca falha por cota, só atrasa.
3. **Compatibilidade "S3" do GCS** (`If-Match`, `DeleteObjects`). Mitigação: flags em `capabilities()` + teste de contrato contra o GCS real antes de anunciar suporte.
4. **Latência em hospedagem compartilhada.** Mitigação: só abrir nota lê o arquivo; timeouts de 3 s para conectar e 10 s no total; cache no GitHub.

---

## 8. Critérios de aceite da feature

- [ ] Sem configurar nada, uma instalação self-hosted se comporta exatamente como hoje (toda a suíte atual passa sem mudanças).
- [ ] Uma org move um workspace local para R2/MinIO/S3 e de volta, sem perder arquivo (verificação por SHA-256), e a busca/backlinks continuam iguais.
- [ ] Uma org conecta um repositório privado, move o workspace, edita no Jotter e vê commits em ≤ 5 min; um push feito fora aparece no Jotter em ≤ 5 min; uma edição simultânea gera cópia de conflito, nunca perda.
- [ ] WebDAV (Obsidian), MCP (com `expected_sha256`), exportação ZIP/PDF, publicação e compartilhamento funcionam com os três drivers.
- [ ] Credenciais nunca aparecem em API, log, backup em claro ou zip de release.
- [ ] `jotter:doctor` mostra o estado de cada store, a fila de flush pendente e os avisos de LGPD (§6).

## Fontes

- GitHub: [limites da REST API (80/min, 500/h de escrita)](https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api), [arquivos grandes](https://docs.github.com/en/repositories/working-with-files/managing-large-files/about-large-files-on-github)
- S3: [escrita condicional (AWS)](https://docs.aws.amazon.com/AmazonS3/latest/userguide/conditional-writes.md), [anúncio do If-Match, nov/2024](https://aws.amazon.com/about-aws/whats-new/2024/11/amazon-s3-functionality-conditional-writes), [MinIO, escrita condicional](https://www.min.io/blog/leading-the-way-minios-conditional-write-feature-for-modern-data-workloads)
- GCS: [interoperabilidade com S3 / chaves HMAC](https://docs.cloud.google.com/storage/docs/interoperability)
