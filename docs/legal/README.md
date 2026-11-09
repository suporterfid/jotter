# Textos legais (rascunhos)

Rascunhos para o operador revisar (#390, item 4). **Nada aqui está publicado.**

- [termos-de-uso.rascunho.md](termos-de-uso.rascunho.md)
- [politica-de-privacidade.rascunho.md](politica-de-privacidade.rascunho.md)

Para publicar:

1. Preencha os trechos entre colchetes e confira cada prazo e fornecedor com a sua
   configuração (`.env`). Peça a revisão de um advogado.
2. Publique os dois textos em URLs públicas: no seu site ou como notas publicadas do
   próprio Jotter, com link público.
3. Configure `JOTTER_BRAND_TERMS_URL` e `JOTTER_BRAND_PRIVACY_URL`. Os links aparecem no
   rodapé e na tela de login, e o cadastro (`JOTTER_SIGNUP_ENABLED`) passa a exigir o
   aceite, gravado em `users.terms_accepted_at`.
4. Dê uma versão aos textos publicados com `JOTTER_TERMS_VERSION` (por exemplo, a data
   da publicação: `2026-11-09`). A versão aceita fica em `users.terms_version`. Quem
   ainda não aceitou essa versão (contas antigas, contas criadas por um administrador)
   vê um aviso com os links e o botão **Li e aceito**; o aceite vai para a auditoria
   (`user.terms_accepted`). Sempre que mudar um dos textos, troque a versão.
5. Num serviço oferecido a terceiros, guarde os registros de acesso por 6 meses
   (Marco Civil, art. 15): `JOTTER_ACCESS_LOG_RETENTION_DAYS=184`. O `jotter:doctor`
   avisa quando os textos estão publicados e o prazo é menor.
