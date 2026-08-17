# Jotter vs. Confluence — Auditoria de Paridade

Data: 2026-08-17
Status: **Diagnóstico apenas.** Nada aqui está aprovado, planejado ou
implementado. Nenhuma issue foi criada por este documento.
Base: `main` @ `dcda766` (2026-08-10).
Trigger: revisão de um comparativo anterior ("Estado atual do Jotter vs.
Confluence"), que foi auditado contra o código e corrigido — as
divergências encontradas estão listadas no §5.

Método: leitura de `app/`, `frontend/src/`, `database/migrations/` e
`routes/api.php` no tip de `main`, cruzada com `STATUS.md`,
`BACKLOG.md`, `docs/decisions.md` e
`docs/jotter-initial-spec-and-build-plan.md`. Cada lacuna abaixo cita o
arquivo (e linha, quando pontual) que a sustenta, para que possa ser
reverificada sem refazer a análise.

---

## 1. Estado atual

O Jotter está bem mais maduro do que o `README.md` sugere. A versão
declarada é **v0.9.0** e a base já entrega praticamente todos os
recursos "essenciais" de um Confluence:

- Espaços/workspaces multi-tenant com membership (`AdminWorkspaceController`,
  `AdminMembershipController`, `TenantController`)
- Editor WYSIWYG inline sobre Markdown (Milkdown, épico WY.1–WY.5) com
  slash-menu, toolbar flutuante de seleção e nós nativos para
  `[[wikilink]]`, `![[embed]]`, callouts e toggles
  (`frontend/src/services/wysiwygNodes/`)
- Comentários com `@mentions` e ancoragem em trecho de texto
  (`WorkspaceCommentController`, `getSelectionAnchorLine()`)
- Notificações in-app, histórico de versões com restore
  (`note_revisions`), templates e daily notes
- Propriedades tipadas (`note_properties`) e visões de coleção —
  tabela, kanban com drag-and-drop/swimlanes/checklists, calendário
  (`CollectionsBoardView.vue`, `BoardController`)
- Busca full-text com filtros de título/tags/data (`SearchCriteria`)
- Backlinks, unlinked mentions, outgoing links, relatório de links
  quebrados/órfãos
- Anexos, exportação/importação (ZIP e JSON), publicação de site
  estático, WebDAV, servidor MCP
- Painel administrativo (workspaces/membros/usuários), audit log com
  redação e retenção
- Paridade de UI com Obsidian: outline, hover preview, transclusão,
  grafo local, abas

**Entregue depois da última atualização de `STATUS.md` e ausente do
comparativo original** — 65 commits entre `cfa0667` e `dcda766`:

- **i18n completo** com locales `en` e `pt-BR`, preferência de idioma
  persistida por usuário e propagada por toda a SPA e pelas mensagens de
  resposta dos controllers (`2026_08_06_000000_add_locale_to_users_table.php`,
  `UserLocaleController`, `SetLocaleFromSubject`, `frontend/src/i18n/`)
- **Suporte a RTL** com layout lógico em editor, painéis, árvore de
  notas, navegação e coleções, coberto por testes de pseudo-locale
- **Identidade visual canônica** com contrato de tokens semânticos e
  preferência `light`/`dark`/`system`, aplicada à SPA e a todas as
  páginas públicas geradas
  (`docs/superpowers/specs/2026-08-10-jotter-canonical-identity-design.md`)
- Fontes empacotadas no output estático com manifesto e fallback
  (`WorkspacePublishController`)
- Verificação de segurança do artefato de release — scan de segredos e
  chaves privadas no ZIP antes do upload (`jt release:verify`,
  `scripts/jt.sh:118`)
- Isolamento de testes por worktree e limpeza dos warnings da suíte
  frontend

> Nota de processo (**corrigida neste mesmo PR**): `STATUS.md` se declarava
> "authoritative current state" mas estava 65 commits atrás do `main` — seu
> cabeçalho "Last Updated" dizia 2026-08-05 e o último commit que tocou o
> arquivo é de 2026-08-06 (`cfa0667`). `AGENTS.md` ainda dizia "Do not
> begin v1 work" enquanto `README.md` e `STATUS.md` diziam que v1 está em
> andamento. `README.md` e `docs/architecture.md` também apontavam cinco
> links de documentação para `file:///home/ubuntu/...`, caminhos da
> máquina de quem escreveu, quebrados para qualquer outro leitor. Esse
> descompasso é a razão pela qual o comparativo original subestimou o
> produto.

---

## 2. Lacunas para "pronto pra usar" como Confluence

Ordenadas por prioridade recomendada (§4), não por tema.

| # | Lacuna | Situação atual (evidência) | O que precisa ser feito | Esforço |
| :-- | :-- | :-- | :-- | :-- |
| 1 | **Papéis de membership não são aplicados** | `AdminMembershipController::ALLOWED_ROLES` (`:16`) define `owner`/`admin`/`editor`/`viewer`, mas `LocalIdentityProvider::isAuthorizedForWorkspace()` (`:170`) só verifica *se existe* membership. Em todo o `app/`, a string `role` aparece exatamente em dois lugares: o `$fillable` de `Membership.php:14` e o próprio `AdminMembershipController` — ou seja, o papel é gravado e editado, e nunca lido para decidir uma autorização. Na prática um `viewer` escreve e apaga como um `owner`. | Aplicar o papel nos endpoints mutantes: ou uma verificação de capacidade no `AuthorizeWorkspaceAccess`, ou estender o contrato `IdentityProvider` com algo como `canWriteWorkspace()`. Sem isso, "somente leitura" não existe. | M |
| 2 | **SSO corporativo padrão** | Só há `LocalIdentityProvider` e `GrandpaSSOnIdentityProvider` (adaptador específico que consulta as tabelas do GrandpaSSOn por PDO cru). Não há SAML2 nem OIDC. | Implementar um `IdentityProvider` OIDC genérico (Google Workspace, Entra ID, Okta). A abstração já existe e é boa; falta o adaptador. OIDC antes de SAML: fluxo redirect-based cabe em hosting compartilhado sem daemon. | L |
| 3 | **Permissões por página** | A autorização é por workspace (`AuthorizeWorkspaceAccess`); não há equivalente ao "Restrict" do Confluence. O próprio contrato `IdentityProvider` só expõe granularidade de workspace/tenant. | ACL por nota (leitura/edição restrita a usuários ou grupos). Depende de #1 — restringir uma página não significa nada enquanto todo membro tem acesso total. | L |
| 4 | **Sem lixeira / restauração de exclusão** | Nenhuma tabela usa `deleted_at`; não há `SoftDeletes` em `app/Models/`. A exclusão de nota é imediata e definitiva. `note_revisions` guarda versões, não notas apagadas. | Soft delete com lixeira por workspace e expurgo programado (o comando `audit:prune` já é um precedente de retenção rodável por cron). | S–M |
| 5 | **Notificações: só menções, só in-app** | `WorkspaceEventEmitter.php:26-29` cria notificação exclusivamente com `'type' => 'mention'`. Não existe conceito de "seguir página" nem canal de e-mail — `config/mail.php` é o default do Laravel, sem nenhum `Mailable` em `app/`. | Duas coisas distintas: (a) ampliar o vocabulário de eventos (resposta a comentário, edição de página seguida) e adicionar assinatura de página; (b) canal de e-mail e digest via `JobDispatcher` + cron. (a) precede (b) — um canal novo sem eventos novos entrega apenas menções por e-mail. | M |
| 6 | **Macros / embeds externos** | `BlockRegistry.php` já é um registro declarativo com allowlist de tags e atributos por bloco, e já tem um bloco `embed` — mas de transclusão interna (`![[nota]]`), não de conteúdo externo. Não há iframe nem marketplace de plugins. | Registrar blocos de embed externo no `BlockRegistry` existente, com iframe sandbox e allowlist de domínios. O ponto de extensão e o modelo de sanitização já estão prontos; falta o tipo de bloco. Plugins de terceiros são não-goal por spec §3 N3. | M |
| 7 | **Sem exportação PDF/Word** | `WorkspaceExportController` exporta ZIP/JSON de Markdown. Não há dependência de renderização em `composer.json`. | Exportação PDF por página e por espaço. Requisito comum de compliance em Confluence. Atenção a spec §3 N2 (sem processamento pesado in-process) — o caminho compatível é delegar via `JobDispatcher`/TaskConnect. | M |
| 8 | **Compartilhamento público só por workspace inteiro** | `WorkspacePublishController` publica o site estático do workspace inteiro. Não há link por página, nem link não listado ou com expiração. | Link de compartilhamento por nota, com escopo e expiração — sem exigir conta, como o "share" público do Confluence. | M |
| 9 | **Sem PWA / app mobile** | Não existe manifest nem service worker em `public/` ou `frontend/`. A responsividade mobile-web foi corrigida (#284). | Manifest PWA (ícone, "add to home screen", offline básico) atende times pequenos sem app nativo. Spec §3 N3 exclui PWA **do v0** — não é proibição permanente, ao contrário de N1. | S |
| 10 | **Sem analytics de uso** | Só existe `audit_log` bruto, exposto pelo `AuditLogViewer.vue` como tabela. Não há agregação de "páginas mais vistas", atividade por usuário ou espaços mais ativos. | Camada de agregação sobre `audit_log` (a coluna `note_id` já foi adicionada em `2026_08_04_000004`, o que torna a agregação por página viável). Cuidado: `audit_log` é append-only com expurgo em 90 dias — métricas de longo prazo precisam de tabela de rollup própria. | M |
| 11 | **Split-screen real (múltiplos painéis)** | Abas com painel único ativo entregues (G.4 escopo A, #290/#297). O escopo B está bloqueado por buscas de DOM globais ao documento — `NoteEditor.vue:845` usa `document.getElementById(heading.id)` para o scroll do outline, o que resolve para o painel errado quando dois painéis exibem notas diferentes. | Refatorar essas buscas para serem escopadas por painel (via `ref` do Vue, não `document`), depois entregar o escopo B. | L |
| 12 | **Edição colaborativa em tempo real** | Decisão **resolvida**, não pendente: `docs/decisions.md` C1 registra "strictly async-first per §4". Spec §3 N1 e §4 proíbem websockets e processos longos; `AGENTS.md` repete a proibição. | Se o mercado exigir, o caminho é uma **entrada de decisão que supersede C1** (o formato do arquivo exige isso explicitamente), não uma implementação incremental. Tecnicamente, só polling cabe na restrição — presença "quem está editando agora" é viável; cursores ao vivo e OT/CRDT não são. | XL |
| 13 | **Sem aprovação / revisão de conteúdo** | Não há workflow de aprovação, estado de rascunho/publicado por página, nem revisor designado. | Baixa prioridade para times pequenos/médios; relevante apenas em cenários regulados. | L |
| 14 | **Proveniência do roadmap (decisão em aberto)** | Único item aberto em `BACKLOG.md` → "Needs a decision". Spec §14.5 já documenta a evidência (o baseline do roadmap descreve um produto offline-first/Material You/realtime — provavelmente outro produto homônimo). | Registrar a confirmação em `docs/decisions.md` e fechar o roadmap original. É trabalho de documentação, não de produto. | XS |

---

## 3. O que **não** é lacuna

Vale registrar para evitar re-planejamento (mesmo erro que `BACKLOG.md`
documenta sobre as prioridades 1–5 do roadmap):

- **Canvas / whiteboard visual** — não-goal explícito (spec §3 N3,
  decisão C4). O próprio roadmap lista paridade de whiteboard como
  não-goal.
- **Mobile/offline-first** — decisão C5: a história offline/mobile é
  WebDAV + Obsidian, não um app próprio.
- **Sistema de plugins de terceiros** — spec §3 N3. Extensibilidade se
  dá pelo `BlockRegistry`, não por marketplace.
- **Hierarquia de páginas** — o Confluence tem árvore pai-filho; o
  Jotter tem árvore de pastas com ordenação persistida
  (`NoteTreeNode.vue`, `folder_positions`, `sort_position`). Modelo
  diferente, necessidade coberta.
- **Labels** — cobertas por tags + propriedades tipadas.

---

## 4. Prioridade recomendada

O comparativo original abria com SSO. A auditoria inverte isso: **o
item mais urgente é o #1 (papéis não aplicados)**, porque é uma lacuna
de segurança, não de recurso. Um workspace com membros `viewer` hoje
não é somente-leitura — qualquer membro edita e apaga qualquer nota.
Isso também é pré-requisito lógico do #3: restringir uma página não
significa nada enquanto o papel base não é aplicado.

**Sequência sugerida:**

1. **#1 Aplicação de papéis** (M) — segurança; destrava #3.
2. **#2 SSO OIDC genérico** (L) — bloqueador de adoção corporativa.
3. **#4 Lixeira** (S–M) — barato, e a exclusão definitiva é o tipo de
   surpresa que custa confiança na migração de um Confluence.
4. **#3 ACL por página** (L) — muito usado em Confluence para
   documentos sensíveis.
5. **#5 Eventos + e-mail** (M) — engajamento diário; fazer (a) antes
   de (b).
6. **#14 Decisão de proveniência** (XS) — encaixa em qualquer sprint;
   fecha o último item formal do backlog.

Os demais (#6–#13) aumentam paridade percebida, mas não bloqueiam uso
em produção. O #12 (tempo real) permanece fora de escopo por decisão
registrada — reabri-lo é uma decisão de produto documentada, não uma
tarefa de engenharia.

Dado o volume já entregue (#144–#325 e além), o produto está bem além
de um MVP e mais perto de uma ferramenta "enterprise-lite" do que o
`README.md` sugere — com a ressalva de que "enterprise" pressupõe os
itens #1 e #2.

---

## 5. Correções em relação ao comparativo original

Registradas para rastreabilidade — o documento anterior circulou e
pode ter informado decisões.

| Afirmação original | Correção |
| :-- | :-- |
| "Colaboração em tempo real — explicitamente descartada [...] Avaliar se vale relaxar essa restrição (ex.: usar polling/**WebSocket leve**)" | WebSocket não é uma opção "leve" a avaliar: spec §4 e `AGENTS.md` o proíbem categoricamente ("no daemons, websockets"). Só polling cabe. Além disso, não é apenas um item de backlog descartado — é a decisão **C1, resolvida** em `docs/decisions.md`, cujo cabeçalho exige uma entrada superseding para ser revista. |
| "Completar o G.4 escopo B quando as issues **G.1/G.5 forem resolvidas**" | G.1 (outline) e G.5 (transclusão) já foram entregues — #286/#292 e #288/#294. O bloqueio não é a resolução delas: é que a implementação entregue de G.1 usa `document.getElementById` (`NoteEditor.vue:845`), busca global ao documento que precisa ser escopada por painel. |
| "Notificações existem só na UI (sino), **sem envio de e-mail**" | Subestima a lacuna. Além de não haver canal de e-mail, o vocabulário de eventos é apenas `mention` (`WorkspaceEventEmitter.php:26-29`) e não existe conceito de "seguir página". "Atualizações de página seguidas" não pode ser enviada por e-mail porque não é gerada em canal nenhum. |
| "Não há sistema de macros [...] nem marketplace de plugins" | Correto quanto a macros externas, mas `BlockRegistry.php` não é só uma sugestão de onde implementar: já é um registro declarativo em produção, com allowlist de sanitização por bloco e um bloco `embed` (interno). Marketplace de plugins é não-goal por spec §3 N3, não uma lacuna a preencher. |
| "PWA [...] não há app nativo nem PWA instalável" | Correto, com um esclarecimento útil: spec §3 N3 exclui PWA **do v0**, não permanentemente — diferente de N1 (tempo real), que é exclusão permanente. |
| "Decisão pendente de proveniência do roadmap — único item aberto" | Correto. Vale acrescentar que spec §14.5 já reúne a evidência (baseline offline-first/Material You/realtime); o que falta é registrar a confirmação, não investigar. |
| Inventário de estado atual | Omitia o trabalho pós-2026-08-05 (65 commits): i18n `en`/`pt-BR` com preferência por usuário, suporte a RTL, temas no site publicado, verificação de segredos no artefato de release. |
| — | Não mencionava quatro lacunas reais: papéis não aplicados (#1), ausência de lixeira (#4), ausência de exportação PDF (#7) e compartilhamento público apenas por workspace inteiro (#8). |
