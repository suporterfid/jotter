# Política de Privacidade — RASCUNHO para revisão

> **Rascunho (#390, item 4). Não publicado.** Os dados, prazos e fornecedores abaixo
> descrevem como o Jotter funciona por padrão (versão de 2026-11-08). Confira cada item
> com a configuração real da sua instância (`.env`) antes de publicar. Os trechos entre
> colchetes (`[...]`) dependem do operador. Este texto não substitui a revisão de um
> advogado. Depois de revisado, publique-o numa URL e configure
> `JOTTER_BRAND_PRIVACY_URL`.
>
> **Como atender os pedidos do art. 18:** o próprio titular corrige o nome em "Editar
> perfil" (só com login local; com OIDC ou GrandpaSSOn o nome vem do provedor). A exclusão
> de conta é feita por um administrador em Administração → Usuários → Excluir, ou por
> `php artisan user:delete <e-mail>`.

**Última atualização:** [DATA]

## 1. Quem trata os seus dados

O controlador dos dados pessoais tratados no Serviço em **[ENDEREÇO DO SITE]** é
**[NOME DO OPERADOR]**, [CPF/CNPJ], [ENDEREÇO]. O encarregado pelo tratamento de dados
(DPO) pode ser contatado em **[E-MAIL DO ENCARREGADO]**.

Esta Política explica, conforme a Lei Geral de Proteção de Dados (Lei nº 13.709/2018,
"LGPD"), quais dados tratamos, para quê, com quem os compartilhamos, por quanto tempo os
guardamos e como você exerce os seus direitos.

## 2. Dados que tratamos

| Categoria | Exemplos | De onde vêm |
| --- | --- | --- |
| Conta | nome, e-mail, senha (guardada só como *hash*), idioma, data do pedido de cadastro, data do aceite destes documentos | você, no cadastro ou no perfil |
| Conteúdo | notas, anexos, comentários, menções, histórico de versões, propriedades das notas | você e as pessoas dos workspaces que você usa |
| Acesso e organização | workspaces, papéis, permissões por nota, grupos, links públicos que você criar | administradores e você |
| Credenciais de integração | tokens de API e MCP, autorizações OAuth de aplicativos conectados (guardados só como *hash*), data do último uso | você e os administradores |
| Registros técnicos e de segurança | eventos de auditoria (login, falhas de login, criação e alteração de notas, revogações), endereço IP, data e hora | o Serviço, automaticamente |
| Estatísticas de uso | contagens diárias agregadas por workspace (notas criadas, editadas, comentários). [A leitura de notas só entra se o operador ligar `JOTTER_ANALYTICS_RECORD_READS`] | o Serviço, a partir dos registros de auditoria |
| Preferências de notificação | quais e-mails você quer receber e com que frequência | você |

O Serviço **não** usa cookies de rastreamento nem de publicidade, e não vende dados.
Usamos apenas cookies estritamente necessários (sessão e proteção contra CSRF). O tema
claro ou escuro fica guardado no seu navegador (*localStorage*).

## 3. Para que usamos e com qual base legal

| Finalidade | Base legal (LGPD, art. 7º) |
| --- | --- |
| Criar e manter sua conta, analisar o pedido de cadastro, permitir o login | execução de contrato e procedimentos preliminares (V) |
| Guardar, indexar, buscar, versionar e mostrar o seu Conteúdo a quem você der acesso | execução de contrato (V) |
| Enviar e-mails do Serviço (aprovação de conta, menções, comentários, resumos) | execução de contrato (V); as notificações opcionais podem ser desligadas nas preferências |
| Proteger o Serviço: registros de auditoria, limites de tentativas, detecção de abuso | legítimo interesse (IX) e cumprimento de obrigação legal (II), incluindo o Marco Civil da Internet |
| Cópias de segurança para recuperação de desastres | legítimo interesse (IX) |
| Busca semântica, quando ativada pelo operador num workspace | execução de contrato (V) [ou consentimento (I), se o operador preferir pedi-lo] |

## 4. Com quem compartilhamos

Compartilhamos dados apenas com quem precisamos para operar o Serviço:

- **Hospedagem:** [NOME DO PROVEDOR], onde ficam os servidores, os arquivos e o banco de dados ([PAÍS]).
- **Envio de e-mail:** [NOME DO PROVEDOR SMTP] ([PAÍS]).
- **[Se a busca semântica estiver ligada]** **Provedor de embeddings:** [NOME, ex.: OpenAI / servidor próprio] ([PAÍS]). Recebe o texto das notas dos workspaces em que a busca semântica foi ativada, e o texto das buscas feitas neles. Workspaces sem essa ativação nunca são enviados.
- **[Se usar login externo]** **Provedor de identidade:** [GrandpaSSOn / Google Workspace / Entra ID / outro], que confirma sua identidade no login.
- **Aplicativos que você conectar:** assistentes de IA e outros clientes que você autorizar por OAuth ou token recebem o Conteúdo dos workspaces e pastas que você liberou. O que eles fazem depois segue a política de quem os fornece.
- **[Se o armazenamento no GitHub estiver ligado]** **GitHub (GitHub, Inc., Estados Unidos):** quando a sua organização escolhe guardar um workspace num repositório privado do GitHub, todo o Conteúdo desse workspace (notas e anexos) é gravado nesse repositório. Os commits levam o nome de quem editou, nunca o e-mail. O repositório pertence à sua organização, que é a **controladora** dele: ela decide quem tem acesso e por quanto tempo o guarda, e confirma isso ao conectar. **Quem tem acesso ao repositório vê o workspace inteiro**, inclusive pastas e notas que o Serviço restringe a algumas pessoas. Workspaces que continuam na pasta do servidor nunca são enviados ao GitHub.
- **Autoridades:** quando a lei ou uma ordem judicial exigir.

**Transferência internacional:** quando algum desses fornecedores trata dados fora do
Brasil, a transferência segue o art. 33 da LGPD, com [cláusulas contratuais padrão /
outra garantia].

## 5. Por quanto tempo guardamos

| Dado | Prazo padrão |
| --- | --- |
| Conta e Conteúdo | enquanto a conta existir; depois do pedido de exclusão, apagamos em até [30] dias |
| Pedido de cadastro recusado | apagado na hora da recusa |
| Notas apagadas (lixeira) | 30 dias na lixeira (`JOTTER_TRASH_RETENTION_DAYS`), depois apagadas de vez |
| Versões antigas de notas | 30 dias (`vault:prune-revisions`) |
| Registros de acesso (entrada, saída, conexões de aplicativos, com IP) | [6 meses] (`JOTTER_ACCESS_LOG_RETENTION_DAYS=184`), como exige o Marco Civil da Internet, art. 15 |
| Demais registros de auditoria (inclui IP) | 90 dias (`JOTTER_AUDIT_RETENTION_DAYS`, `audit:prune`); as estatísticas agregadas, sem IP, podem ficar por mais tempo |
| Exportações em PDF | 24 horas |
| Tokens e autorizações OAuth | até expirarem ou serem revogados; códigos e tokens vencidos são apagados diariamente |
| Cópias de segurança | as [7] mais recentes no servidor (`JOTTER_BACKUP_KEEP`) e até [30] dias fora dele; um dado apagado some das cópias quando a mais antiga que o contém é descartada. Até lá, as cópias ficam protegidas e **nunca trazem de volta um dado apagado**: toda restauração reaplica as exclusões já feitas |
| Workspace guardado no GitHub | enquanto a organização mantiver o repositório. Quando uma nota é apagada, ou a organização é excluída do Serviço, o arquivo sai do repositório num novo commit. **O histórico do Git continua guardando as versões anteriores, e o Serviço nunca reescreve esse histórico.** Para apagar de vez, a organização arquiva ou exclui o repositório no GitHub; o Serviço avisa isso na exclusão. As nossas cópias de segurança não incluem esses workspaces, salvo se o operador pedir |
| Outros registros exigidos por lei | pelo prazo legal |

## 6. Segurança

Senhas, tokens e códigos de acesso são guardados apenas como *hash*. O tráfego usa HTTPS.
Você pode ligar a verificação em duas etapas (código de um app autenticador) em **Seu
perfil**[; para administradores ela é obrigatória].
[Se `JOTTER_BACKUP_KEY` estiver configurada] As cópias de segurança são criptografadas, e a
chave fica guardada fora do servidor.
O acesso aos workspaces segue papéis e permissões por nota. As credenciais de agentes de
IA são limitadas por escopo, workspace e pasta, e nunca têm poderes de administrador. As
cópias de segurança ficam fora da área pública do site. Nenhum sistema é perfeitamente
seguro: se ocorrer um incidente que possa trazer risco ou dano relevante, avisaremos você
e a ANPD, conforme o art. 48 da LGPD.

## 7. Seus direitos

Você pode, a qualquer momento e sem custo (LGPD, art. 18):

- confirmar se tratamos seus dados e ter acesso a eles;
- corrigir dados incompletos, inexatos ou desatualizados (nome e idioma podem ser
  alterados no próprio Serviço; para corrigir o e-mail, escreva para o encarregado);
- pedir anonimização, bloqueio ou eliminação de dados desnecessários ou tratados em
  desconformidade com a lei;
- pedir a portabilidade: o Conteúdo pode ser exportado por você mesmo, em Markdown, pela
  exportação do workspace;
- pedir a eliminação dos seus dados e da sua conta;
- saber com quem compartilhamos seus dados;
- revogar consentimentos e se opor a tratamentos baseados em legítimo interesse;
- reclamar à Autoridade Nacional de Proteção de Dados (ANPD).

Dois desses direitos você exerce sozinho, em **Seu perfil → Seus dados**:

- **Baixar meus dados:** um arquivo JSON com tudo o que guardamos sobre você (conta,
  acessos aos workspaces, comentários, tokens, aplicativos conectados e histórico de
  atividade). O texto das notas não entra, porque pertence ao workspace; para levar as
  notas, use a exportação do workspace.
- **Pedir a exclusão da minha conta:** um administrador conclui a exclusão em até
  [30] dias. Você pode cancelar o pedido até lá.

Para os demais, escreva para **[E-MAIL DO ENCARREGADO]**. Responderemos em até 15 dias.
Podemos pedir uma confirmação de identidade antes de atender.

Quando a conta é excluída, apagamos seus dados de conta, seus acessos aos workspaces,
seus tokens e as autorizações dos aplicativos que você conectou. Seu e-mail também sai dos
registros de auditoria. As notas e anexos que você criou em workspaces compartilhados
pertencem ao workspace e continuam lá. Seus comentários continuam visíveis, mas assinados
como "Usuário excluído".

## 8. Mudanças nesta Política

Se esta Política mudar de forma relevante, avisaremos por e-mail ou no Serviço antes de a
mudança valer. A data no topo indica a versão em vigor.

## 9. Contato

Encarregado (DPO): [NOME] — [E-MAIL DO ENCARREGADO]
[NOME DO OPERADOR] — [ENDEREÇO]
