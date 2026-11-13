# Canais de vault por tenant — pesquisa, opções e custos

- **Data:** 2026-11-13
- **Status:** Pesquisa / proposta. **Não é decisão.** Antes de qualquer código, um registro datado em `docs/decisions.md` precisa aceitar (ou recusar) a arquitetura da §3 — regra de `BACKLOG.md` → *Needs a decision*.
- **Pergunta:** quais canais/integrações opcionais o Jotter pode oferecer como "vault de arquivos", configuráveis por tenant (ex.: GitHub, S3, Cloudflare, GCP)? Quanto custam?

---

## 1. Resumo (para decidir em 2 minutos)

1. **Não troque o disco local como fonte da verdade.** O vault é lido e escrito por `file_get_contents`/`file_put_contents` em `VaultStorage`, `AttachmentStorage`, `NoteTrash`, `VaultExtractor` e `InstanceBackup`; WebDAV, reindex, busca e exportação assumem uma pasta local. Tornar um bucket o vault *primário* quebra o invariante do spec §1 e custa uma reescrita. **Recomendação: canais são réplicas (espelho e backup), não o primário.**
2. **Três adaptadores cobrem quase tudo:**
   - **S3-compatível** → AWS S3, Cloudflare R2, Backblaze B2, Wasabi, Google Cloud Storage (API XML com chaves HMAC), Magalu Cloud, Hetzner, MinIO, DigitalOcean Spaces.
   - **API de Git** → GitHub (depois GitLab/Forgejo).
   - **WebDAV/SFTP de saída** → Nextcloud, Hetzner Storage Box, Koofr, pCloud, qualquer VPS.
3. **Provedor padrão recomendado:**
   - **Cloudflare R2** para o modo gerenciado pelo operador (sem custo de egress, US$ 0,015/GB).
   - **Backblaze B2** como opção mais barata para backup.
   - **Magalu Cloud** para tenants que exigem dados no Brasil (LGPD/setor público): R2 não tem região nem jurisdição na América do Sul.
4. **GitHub é o canal criativo de maior valor:** o vault vira um repositório que Claude Code/Codex clonam, que o Obsidian sincroniza via `obsidian-git`, e que o GitHub Pages publica. Custo zero para o tenant se só Markdown for para o Git.
5. **Custos são baixos:** um espelho de uma equipe de 10 pessoas (10 GB) custa **US$ 0,07–0,40/mês** em B2/R2. O que pesa é **egress** em AWS/GCP (10–50× mais caro no mesmo cenário) e **retenção mínima** em classes frias (90–180 dias), que conflita com a janela de 30 dias de rotação de backup já prometida na política de privacidade.

---

## 2. Ponto de partida no código

| Fato | Onde | Consequência para canais |
|---|---|---|
| Vault = arquivos `.md` em disco; MySQL é projeção reconstruível | spec §1, `docs/architecture.md` | Canal remoto deve ser **réplica**; o disco segue autoritativo. |
| Gravações passam por poucos serviços | `VaultStorage`, `AttachmentStorage`, `NoteTrash`, `VaultExtractor`, `InstanceBackup::restore` | Um *outbox* alimentado nesses 5 pontos captura quase toda mudança. |
| Edições diretas no disco (Obsidian local, cPanel) só aparecem no reindex | `VaultReindexer` + `notes.content_hash` | O reconcile do canal compara `content_hash`, não depende só do outbox. |
| Sem daemons, sem `exec`, só cron | spec §4 | Envio em lotes limitados por cron (`--limit=N`), como `semantic:index` e `pdf:process-exports`. Nada de `git` CLI nem `rclone`. |
| Flysystem 3 já está no lock; `league/flysystem-aws-s3-v3` aparece como sugestão do Laravel | `composer.lock` | Usar adaptadores Flysystem. Para S3, preferir **`async-aws/s3`** (bem menor que `aws/aws-sdk-php`) por causa do tamanho do zip de deploy. |
| Segredos cifrados com `APP_KEY` já existem (`'encrypted'` cast) | `User::two_factor_secret` | Credenciais do canal por tenant usam o mesmo mecanismo; nada vai para `.env` nem para o zip de release. |
| Backups cifrados com sodium + compromisso de rotacionar cópias externas em 30 dias | `BackupCipher`, `docs/hosted-operations.md`, decisão LGPD 4c | Um canal de backup externo **automatiza um dever que hoje é manual**, mas precisa respeitar os 30 dias. |
| `tenant:delete` + log de eliminação | decisão LGPD 4c | Apagar tenant precisa também **apagar o prefixo no canal gerenciado** (ou documentar que, no BYO, o tenant é o controlador da cópia). |

---

## 3. Arquitetura proposta: "Canais de vault"

### 3.1 Quatro papéis (um canal pode ter mais de um)

| Papel | O que faz | Direção | Muda o invariante? | Esforço |
|---|---|---|---|---|
| **Espelho** | Réplica viva de notas (+ anexos opcionais) | Jotter → canal | Não | M |
| **Destino de backup** | Recebe o ZIP cifrado de `jotter:backup` (operador) e/ou `tenant:export` (tenant) | Jotter → canal | Não | S |
| **Offload de anexos** | Anexos ficam no bucket; o Jotter serve por URL pré-assinada | Primário para blobs | **Sim, para anexos** — decisão própria | L |
| **Sync bidirecional** | Mudanças feitas no Git/bucket voltam para o vault | ↔ | Não, mas exige política de conflito | L |

### 3.2 Mecânica (cabe em hospedagem compartilhada)

```
VaultStorage / AttachmentStorage / NoteTrash / VaultExtractor / restore
        │  (path, op: put|delete|move, content_hash)
        ▼
vault_channel_outbox  ──(cron: vault:channels:push --limit=200)──▶  driver do canal
        ▲                                                         (S3 | GitHub | WebDAV | SFTP | Drive)
        │
vault:channels:reconcile (diário) — compara o manifesto remoto com notes.content_hash
```

- **Coalescência:** várias edições do mesmo caminho entre duas execuções viram um único envio (vence a última escrita).
- **Idempotência:** chave do objeto = caminho relativo; metadado `x-jotter-sha256` = `content_hash`. Envio repetido não duplica.
- **Falhas:** backoff exponencial por canal; `last_error`/`last_synced_at` visíveis no admin e no `jotter:doctor`.
- **Exclusões propagam** (inclusive lixeira esvaziada e `tenant:delete`), o que mantém o espelho coerente com a LGPD.

### 3.3 Configuração por tenant

Tabela `tenant_vault_channels`:

- `tenant_id`, `workspace_id` (nulo = todos os workspaces do tenant);
- `driver`, `roles[]`, `settings` (JSON: bucket, prefixo, região, repositório, branch);
- `credentials` (cifrado com `APP_KEY`);
- `ownership`: `managed` (bucket do operador, prefixo `tenants/<slug>/`) ou `byo` (bucket/conta do próprio tenant);
- `status`, `last_synced_at`, `last_error`.

Comandos e telas:

- **Artisan:** `tenant:channel add|test|remove` (no padrão de `tenant:plan`).
- **Admin:** aba *Armazenamento externo*.
- **Plano:** o operador pode tratar como add-on (`plan_name`) sem código de cobrança — mesmo princípio de "hosting is configuration".

### 3.4 Segurança (não negociável)

- **SSRF:** endpoint S3/WebDAV fornecido pelo tenant deve ser HTTPS, sem IP privado/loopback/link-local, com resolução DNS checada no momento da conexão (contra *DNS rebinding*). Só o operador pode liberar endpoints internos.
- **Mínimo privilégio:**
  - token R2 restrito a um bucket;
  - IAM com `s3:PutObject`/`DeleteObject` só no prefixo;
  - **GitHub App instalada em um único repositório** com `contents: write` (nunca PAT pessoal);
  - chave HMAC de *service account* dedicada no GCS.
- **Cifragem do lado do cliente** (opcional no espelho, obrigatória no backup): reaproveitar `BackupCipher` (sodium). O provedor nunca vê o conteúdo.
- **ACLs:** notas com ACL restrita **não** vão para canais BYO por padrão. O espelho seria um vazamento por fora de `NoteAccess` (mesmo raciocínio da decisão de ACLs sobre acesso direto ao vault).
- **Release:** nada de credencial em `.env.example`, fixtures ou zip; as checagens de segredo do `jt release` continuam valendo.

---

## 4. Catálogo de opções

Legenda de esforço: S ≈ 1 PR pequeno, M ≈ 1 PR médio, L ≈ vários PRs.

### 4.1 Armazenamento de objetos (um adaptador S3-compatível)

| Provedor | Preço armazenamento | Egress | Pegadinhas | Melhor uso |
|---|---|---|---|---|
| **Cloudflare R2** | US$ 0,015/GB-mês; IA US$ 0,01 | **Grátis** | Sem região/jurisdição na América do Sul (só `default`, `eu`, `fedramp`); free tier 10 GB + 1 M ops classe A **por conta** | Espelho gerenciado pelo operador; offload de anexos |
| **Backblaze B2** | US$ 6,95/TB-mês (≈ US$ 0,007/GB) desde 2026 | Grátis até 3× o armazenado | 10 GB grátis | Backup externo mais barato; espelho |
| **AWS S3 (sa-east-1)** | ≈ US$ 0,0405/GB-mês; PUT ≈ US$ 0,007/1.000 | ≈ US$ 0,15/GB (100 GB/mês grátis por conta) | Egress domina o custo; Glacier IR/Deep Archive têm mínimo de 90/180 dias | Tenant que já tem AWS (BYO); dados no Brasil |
| **Google Cloud Storage (southamerica-east1)** | ≈ US$ 0,035/GB Standard (conferir; a tabela oficial variou entre idiomas) | ≈ US$ 0,12/GB | Nearline/Coldline/Archive têm mínimo de 30/90/365 dias | Tenant GCP (BYO) via API XML + HMAC; dados no Brasil |
| **Magalu Cloud** | R$ 0,10/GiB-mês Standard; Cold Instant ≈ R$ 0,06 (fonte terceira) | ≈ R$ 0,10/GiB (fonte terceira) | Cobrança em reais, sem IOF/câmbio; custo de requisições a confirmar | **Residência de dados no Brasil** como argumento de venda |
| **Hetzner Object Storage** | € 6,49/mês fixo com 1 TB + 1 TB egress (era € 4,99) | Incluso até 1 TB | Instabilidade reportada em jul/2026; dados na UE | Operador com volume de 0,5–1 TB |
| **Wasabi** | US$ 7,99/TB-mês desde jul/2026, **mínimo 1 TB** | Grátis com "fair use" (egress ≤ armazenado) | Mínimo de 90 dias: apagar em 30 cobra 90 | Só para operador com ≥ 1 TB e retenção longa |

### 4.2 Git (adaptador de API, sem `git` CLI)

| Opção | Como funciona em PHP puro | Custo | Pegadinhas |
|---|---|---|---|
| **GitHub (GitHub App)** | API Git Data: `POST /git/trees` com conteúdo inline → `POST /git/commits` → `PATCH /git/refs`. **Um commit por lote, cerca de 4 chamadas.** | US$ 0 (repositório privado grátis) | Repositório recomendado < 1 GB (forte < 5 GB); arquivo > 100 MiB bloqueado; push ≤ 2 GB; ≈ 6 pushes/min por repositório. Anexos via LFS custam US$ 0,07/GiB-mês + US$ 0,0875/GiB de banda além de 10 GiB grátis. |
| **GitLab** | `POST /projects/:id/repository/commits` com `actions[]` (um commit, uma chamada) | US$ 0 | Limites próprios de tamanho |
| **Forgejo/Gitea (self-hosted)** | API compatível com o estilo do GitHub | Custo do servidor do tenant | Ideal para quem já roda Forgejo |

**Recomendação:** só Markdown (e `_templates`) vai para o Git. Anexos ficam no disco ou em um canal S3, e o repositório recebe um `.gitattributes`/README explicando onde estão. Mensagem do commit: `jotter: <ator> editou <caminho>`, usando o ator que `NoteRevisions` já registra.

### 4.3 WebDAV / SFTP de saída

| Opção | Biblioteca pura PHP | Custo típico | Uso |
|---|---|---|---|
| **WebDAV** (Nextcloud, Hetzner Storage Box, Koofr, pCloud) | `league/flysystem-webdav` (sabre/dav) ou cliente HTTP simples | Storage Box 1 TB ≈ € 3,20/mês, tráfego ilimitado | Tenant que já tem Nextcloud; backup barato do operador |
| **SFTP** (Storage Box, rsync.net, VPS) | `league/flysystem-sftp-v3` (phpseclib, sem `exec`) | Idem | Backup do operador |

### 4.4 Drives de consumidor (BYO, OAuth)

| Opção | Custo para o operador | Uso | Pegadinhas |
|---|---|---|---|
| **Google Drive** | US$ 0 (15 GB grátis na conta do tenant; acima disso, plano do próprio cliente) | Export agendado (ZIP mensal na pasta do admin) | Não serve como espelho arquivo-a-arquivo: cota de API, nomes duplicados permitidos, sem caminhos reais |
| **OneDrive / Dropbox** | US$ 0 | Idem | Revisão de app OAuth em cada provedor |

### 4.5 Serviços de chave (para cifragem por tenant)

| Opção | Custo aproximado | Por que importa |
|---|---|---|
| **GCP Cloud KMS** | ≈ US$ 0,06 por versão de chave ativa/mês + ≈ US$ 0,03 por 10 mil operações | A decisão LGPD 4c (opção D, *crypto-shredding*) diz para reavaliar "quando o operador puder usar um serviço de chave externo cuja exclusão é autoritativa". Uma chave por tenant no KMS **destrói o backup daquele tenant ao apagar a chave**. |
| **AWS KMS** | ≈ US$ 1/chave/mês | Mesmo papel; mais caro por tenant |

---

## 5. Estimativas de custo

**Premissas.** Preços pesquisados em nov/2026, sem impostos, sem free tier (um operador com muitos tenants esgota o free tier). Confira as páginas oficiais antes de vender um plano.

Perfis de tenant (papel **Espelho**, Markdown + anexos):

| Perfil | Armazenado | Gravações/mês | Download/mês |
|---|---|---|---|
| **P — Pessoal** | 1 GB | 5 mil | 2 GB |
| **E — Equipe de 10** | 10 GB | 50 mil | 20 GB |
| **O — Organização de 50** | 100 GB | 300 mil | 150 GB |

### 5.1 Espelho por tenant (US$/mês, exceto Magalu em R$)

| Provedor | P | E | O |
|---|---|---|---|
| Backblaze B2 | 0,01 | 0,07 | 0,70 |
| Cloudflare R2 | 0,04 | 0,38 | 2,85 |
| GCS southamerica-east1 | 0,30 | 3,00 | 23,00 |
| AWS S3 sa-east-1 | 0,38 | 3,76 | 28,65 |
| Magalu Cloud (R$) | R$ 0,30 | R$ 3,00 | R$ 25,00 (+ requisições) |
| GitHub (só Markdown) | 0 | 0 | 0 |
| GitHub com anexos via LFS | 0 | 0 | ≈ 18,55 |
| Wasabi (mínimo 1 TB) | 7,99 | 7,99 | 7,99 |

Leitura: em AWS/GCP **75–80% da conta é egress**. Se o espelho quase nunca é lido (só em restore), AWS/GCP caem para ≈ US$ 5–6 no perfil O. Wasabi só faz sentido agregando tenants.

### 5.2 Operador hospedado (ex.: Cadernia) — 200 tenants

Mix: 150 P + 45 E + 5 O = **1,1 TB**, 4,5 M gravações/mês, 1,95 TB de download/mês.

| Provedor | Custo/mês | Observação |
|---|---|---|
| Backblaze B2 | **≈ US$ 8** | Egress dentro de 3× |
| Hetzner Object Storage | **≈ € 8,30** | Base € 6,49 + 0,1 TB extra + 0,95 TB egress extra |
| Wasabi | ≈ US$ 8,80 | **Viola o fair use**: egress 1,95 TB > 1,1 TB armazenado |
| Cloudflare R2 | ≈ US$ 37 | US$ 16,50 armazenamento + US$ 20 operações; egress zero, então é o mais previsível se o uso de download crescer |
| Magalu Cloud | ≈ R$ 305 + requisições | Prêmio pela residência no Brasil |
| GCS southamerica-east1 | ≈ US$ 295 | Egress |
| AWS S3 sa-east-1 | ≈ US$ 350 | Egress |

**Por tenant, isso dá entre US$ 0,04 (B2) e US$ 1,75 (S3) por mês.** Um add-on "Cópia externa" a R$ 5–10/mês tem margem alta em qualquer provedor S3-compatível barato.

### 5.3 Backup externo do operador (papel *Destino de backup*)

Instância de 100 GB (ZIP cifrado). Para cumprir a promessa de "rotacionar cópias externas em 30 dias", compare duas rotações:

- **30 diárias completas** = 3 TB;
- **GFS 7 diárias + 4 semanais** = 11 cópias = 1,1 TB. Cobre a mesma janela de 30 dias com 63% menos espaço. **Recomendado.**

| Provedor | GFS (1,1 TB) | 30 diárias (3 TB) | Compatível com rotação de 30 dias? |
|---|---|---|---|
| Hetzner Storage Box 1 TB (SFTP/WebDAV) | ≈ € 3,20 se a instância tiver ≤ 90 GB | Próximo degrau (conferir) | Sim |
| Backblaze B2 | ≈ US$ 7,65 | ≈ US$ 20,85 | Sim |
| Cloudflare R2 IA | ≈ US$ 11 (+ US$ 0,01/GB no restore) | ≈ US$ 30 | Sim: mínimo de 30 dias = janela |
| Cloudflare R2 Standard | ≈ US$ 16,50 | ≈ US$ 45 | Sim |
| Wasabi | ≈ US$ 26 efetivo | ≈ US$ 72 efetivo | **Não**: cobra 90 dias de cada cópia |
| S3 Glacier IR / Deep Archive, GCS Coldline/Archive | barato por GB | — | **Não**: mínimo de 90–365 dias, e Object Lock longo impediria apagar dentro da janela da LGPD |

**Ideia de proteção contra ransomware compatível com a LGPD:** Object Lock (S3/B2/R2 bucket lock) com retenção **≤ 30 dias**. Ninguém, nem um invasor com a credencial do servidor, apaga o backup antes do prazo, e a promessa de eliminação continua cumprida.

---

## 6. Ideias criativas (o que diferencia o produto)

1. **"Cérebro clonável" (GitHub).** O vault vira repositório privado:
   - Claude Code/Codex trabalham com `git clone`/`pull`, offline e sem gastar o rate limit do MCP;
   - o `obsidian-git` vira um segundo caminho para o Obsidian, além do WebDAV;
   - `git blame` funciona como auditoria gratuita;
   - uma GitHub Action publica um site estático no GitHub Pages (gerador de site estático de código aberto) sem custo de hospedagem.

   Combina diretamente com o épico *brain multi-agent*.
2. **"Cofre BR" (Magalu Cloud).** Plano com garantia de dados no Brasil, para escritórios de advocacia, saúde e setor público. Cobrança em reais, sem câmbio. A ANPD (Resolução 19/2024) exige cláusulas-padrão para transferência internacional; um canal 100% nacional remove essa conversa.
3. **"Traga seu próprio bucket" (BYO).** O tenant aponta um bucket próprio: custo zero para o operador e um argumento forte contra lock-in ("suas notas estão sempre no seu bucket também"). Coerente com o slogan *your notes stay yours*.
4. **"Anexos na borda" (R2 + URL pré-assinada).** Tira os anexos do disco e dos inodes do cPanel, o recurso mais escasso na hospedagem compartilhada. Download sem custo de egress. Exige decisão própria, porque muda a fonte da verdade dos anexos.
5. **"Export mensal no Drive do cliente".** Cron mensal coloca o `tenant:export` (ZIP portátil) no Google Drive/OneDrive do admin do tenant. Valor percebido alto, custo zero.
6. **"Crypto-shredding por tenant" (Cloud KMS).** Cumpre a condição de reabertura da decisão LGPD 4c, opção D. Cada tenant tem uma chave no KMS; apagar o tenant apaga a chave, e todo backup dele fica ilegível imediatamente. Custo ≈ US$ 0,06/tenant/mês no GCP.
7. **"Imutável por 30 dias".** Object Lock com retenção igual à janela de rotação (§5.3): proteção contra ransomware sem ferir a LGPD.

---

## 7. Recomendação e sequência

| Fase | Entrega | Tamanho | Por quê |
|---|---|---|---|
| **0** | Registro datado em `docs/decisions.md`: canais são réplicas; disco continua fonte da verdade; offload de anexos e sync bidirecional ficam fora | S | Regra do `BACKLOG.md` |
| **1** | Driver **S3-compatível** (`async-aws/s3`): papel *Destino de backup* para `jotter:backup` com rotação GFS + checagem no `jotter:doctor` | S–M | Automatiza um dever do operador que hoje é manual; valida o driver com risco baixo |
| **2** | Papel **Espelho** por tenant: outbox, `vault:channels:push`, `vault:channels:reconcile`, `tenant:channel`, aba no admin, proteção contra SSRF | M | Base de todos os outros canais |
| **3** | **GitHub App** (espelho só de Markdown, mão única) | M | Diferencial do "cérebro clonável" |
| **4** | WebDAV/SFTP de saída + export agendado para Google Drive | M | Cobertura BYO |
| **5** | (Cada um com decisão própria) offload de anexos; sync bidirecional Git; crypto-shredding com KMS | L | Mudam invariantes ou o formato de backup |

**Provedores para o modo gerenciado:**

- R2 como padrão de espelho;
- B2 ou Hetzner Storage Box para backup;
- Magalu como opção "Cofre BR".

**Não recomendados:**

- Wasabi abaixo de 1 TB ou com rotação de 30 dias;
- classes frias (Glacier, Deep Archive, Coldline, Archive) para backups rotacionados;
- GitHub LFS para anexos;
- Google Drive como espelho arquivo-a-arquivo;
- qualquer canal como vault *primário*.

---

## 8. Pendências de verificação

- Preço do GCS em `southamerica-east1`: as páginas oficiais em idiomas diferentes divergem (US$ 0,023 × US$ 0,035/GB). Conferir em `cloud.google.com/storage/pricing`.
- Magalu Cloud: preço oficial do Cold Instant, do egress e das requisições.
- Backblaze B2: confirmar se as taxas de API foram mesmo removidas em maio/2026.
- Hetzner: preço atual do Object Storage (€ 4,99 × € 6,49) e dos degraus do Storage Box.
- Egress do S3 em `sa-east-1` (≈ US$ 0,15/GB usado aqui).
- Tamanho real no zip de release de `async-aws/s3` + `league/flysystem-*` (medir com `jt release`).

## Fontes

- Cloudflare R2: [preços](https://developers.cloudflare.com/r2/pricing/), [localização de dados](https://developers.cloudflare.com/r2/reference/data-location/)
- Backblaze B2: [Upstash, preços 2026](https://upstash.com/blog/backblaze-b2-pricing-explained-2026), [Akave, reajustes de 2026](https://akave.com/blog/wasabi-vs-backblaze-vs-akave-backup-pricing-2026)
- Wasabi: [preços de maio/2026](https://docs.wasabi.com/docs/may-2026-wasabi-pricing)
- AWS S3: [Upstash, preços S3 2026](https://upstash.com/blog/aws-s3-pricing-explained-2026), [CloudBerry](https://www.cloudberrylab.com/resources/guides/amazon-s3-as-backup-storage/amazon-s3-pricing-explained/)
- Google Cloud Storage: [preços](https://cloud.google.com/storage/pricing)
- Magalu Cloud: [lista de preços](https://docs.magalu.cloud/docs/finops/bss/price/price-list), [forma de cobrança](https://docs.magalu.cloud/docs/storage/object-storage/additional-explanations/price), [s3providers](https://s3providers.com/providers/magalu-object-storage)
- Hetzner: [Object Storage](https://www.hetzner.com/storage/object-storage), [Storage Box](https://docs.hetzner.com/storage/storage-box/general), [Sliplane, jul/2026](https://sliplane.io/blog/cheap-object-storage-providers-europe)
- GitHub: [cobrança do Git LFS](https://docs.github.com/en/billing/using-the-new-billing-platform/about-billing-for-git-large-file-storage), [arquivos grandes](https://docs.github.com/en/repositories/working-with-files/managing-large-files/about-large-files-on-github)
