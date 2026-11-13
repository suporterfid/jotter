# Conectar um bucket S3: guardar um workspace no AWS S3, no R2 ou no MinIO

Este guia é para quem administra uma organização no Jotter. Ele explica como guardar um workspace num bucket compatível com S3, em vez da pasta do servidor.

> **Atenção: quem tem acesso ao bucket vê o workspace inteiro.**
> O Jotter controla quem vê cada nota **dentro** do Jotter: papéis, pastas restritas e notas privadas. No bucket essas regras não existem. Toda pessoa, aplicativo ou chave com leitura no bucket (ou no prefixo do workspace) lê **todas** as notas e anexos, inclusive os de pastas restritas. Dê acesso ao bucket só a quem pode ver tudo.

## O que muda

- As notas continuam sendo arquivos Markdown, com as mesmas pastas e os mesmos `[[links]]`. Cada arquivo vira um objeto em `<prefixo>/<workspace>-<id>/caminho/da/nota.md`. Dá para baixar tudo com qualquer cliente S3 (`aws s3 sync`, `rclone`) e abrir no Obsidian.
- **Salvar é imediato.** O Jotter grava no bucket na hora, sem fila.
- **Edições feitas direto no bucket não se perdem.** Na AWS, no R2 e no MinIO, o Jotter só grava por cima de uma nota se ela não mudou desde que foi lida. Se mudou, quem está editando recebe o aviso de conflito do editor. As mudanças externas aparecem no Jotter em até uma hora, na reindexação automática.
- **Se o provedor sair do ar**, o Jotter responde "armazenamento externo indisponível" e o workspace fica marcado como degradado. Nada é corrompido. Assim que o bucket volta a responder, tudo volta ao normal.
- **Se a chave for revogada**, o workspace fica **somente leitura** até alguém atualizar a chave e clicar em **Testar conexão**.
- Anexos grandes (acima de 8 MB) são entregues por um link temporário de 60 segundos, direto do bucket.

## Antes de começar

1. **Crie um bucket privado.** Deixe o acesso público bloqueado.
2. **Ligue o versionamento do bucket** (recomendado). Com ele, uma exclusão por engano pode ser desfeita no próprio provedor. Sem ele, o Jotter inclui o workspace nas cópias de segurança da instância, e o `jotter:doctor` avisa.
3. **Crie uma regra de ciclo de vida que expire versões antigas em até 30 dias.** É a mesma janela das cópias de segurança: uma nota apagada não deve sobreviver mais do que isso no provedor (LGPD).
4. **Crie uma chave só para o Jotter, limitada ao bucket e ao prefixo.** Veja os exemplos abaixo.

### AWS S3: política do IAM restrita ao prefixo

Troque `meu-bucket` e `jotter/acme` pelos seus valores. O prefixo é o mesmo que você vai informar no Jotter.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:ListBucket"],
      "Resource": "arn:aws:s3:::meu-bucket",
      "Condition": { "StringLike": { "s3:prefix": ["jotter/acme/*"] } }
    },
    {
      "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::meu-bucket/jotter/acme/*"
    },
    {
      "Effect": "Allow",
      "Action": ["s3:GetBucketVersioning", "s3:GetLifecycleConfiguration"],
      "Resource": "arn:aws:s3:::meu-bucket"
    }
  ]
}
```

As duas últimas permissões só servem para o Jotter ler o versionamento e o ciclo de vida. Sem elas tudo funciona, mas o Jotter trata o bucket como **sem versionamento** e o inclui nas cópias de segurança.

- Endpoint: `https://s3.<região>.amazonaws.com`, por exemplo `https://s3.sa-east-1.amazonaws.com`.
- Região: a do bucket, por exemplo `sa-east-1`.

### Cloudflare R2

1. Em **R2 → Manage R2 API Tokens**, crie um token com **Object Read & Write**, aplicado **só a este bucket**.
2. Endpoint: `https://<account-id>.r2.cloudflarestorage.com`. Região: `auto`.
3. O R2 não tem versionamento de objetos. Por isso o Jotter **sempre** inclui workspaces do R2 nas cópias de segurança da instância.

### MinIO

- Crie um usuário com uma política limitada ao bucket e ao prefixo, como a da AWS acima.
- Marque **Estilo de caminho (path-style)**.
- Um MinIO numa rede interna só funciona se o operador do Jotter liberar o nome do servidor em `JOTTER_VAULT_ALLOWED_PRIVATE_HOSTS`. Fora dessa lista, o Jotter só aceita endpoints `https://` com endereço público. É uma proteção contra SSRF.
- Atenção: em 2026 o projeto MinIO arquivou a edição comunitária, que não recebe mais correções de segurança. Para produção, prefira um provedor mantido.

### Google Cloud Storage

O GCS fala S3 por chaves HMAC, mas **ainda não foi validado** com o Jotter (veja *Provedores testados*). Quando o endpoint é `storage.googleapis.com`, o Jotter não usa gravação condicional e serializa as próprias gravações. Uma edição feita direto no bucket pode se perder se cair entre a leitura e a gravação do Jotter.

## Conectar

1. Em **Administração → Armazenamento → Adicionar armazenamento**, escolha **Bucket compatível com S3**.
2. Informe o endpoint, a região, o bucket, o prefixo (por exemplo `jotter/acme`), a chave de acesso e o segredo. Marque **path-style** no MinIO.
3. Clique em **Testar conexão**. O teste grava, lê e apaga um arquivo `.jotter-probe-…` dentro do prefixo. Depois lê o versionamento e o ciclo de vida do bucket, que aparecem na tela.

O segredo é cifrado com a chave da instância e nunca volta pela API nem aparece em logs.

## Mover o workspace

Com o armazenamento testado, use **Mover um workspace** na mesma aba (ou `php artisan vault:move <workspace> --to=<id>`). Antes de começar, o Jotter confere que o prefixo do workspace está vazio e que a chave consegue gravar, ler e apagar. Se o versionamento estiver desligado, ele avisa.

Durante a cópia o workspace fica somente leitura. Cada arquivo copiado é conferido pelo SHA-256, e no fim o Jotter troca o armazenamento de uma vez. A pasta antiga fica guardada por 7 dias, para desfazer com `vault:move <workspace> --rollback`. Para voltar à pasta do servidor depois, mova de novo e escolha **Pasta local (padrão)**.

## Apagar e cópias de segurança

- **Excluir a organização do Jotter** apaga todos os objetos dos prefixos dos workspaces dela, inclusive cópias deixadas por mudanças de armazenamento. Com o versionamento ligado, as versões antigas continuam no bucket até a regra de ciclo de vida expirá-las. O Jotter mostra essa orientação e a registra na auditoria.
- **Cópias de segurança da instância:** um workspace no S3 só fica fora do ZIP quando o bucket tem versionamento ligado. Sem versionamento (e no R2), ele entra.
- **Depois de restaurar uma cópia**, o Jotter devolve ao bucket os arquivos que sumiram desde a cópia, sem sobrescrever nenhum objeto que ainda exista, e reindexa o workspace a partir do bucket.

## Provedores testados

| Provedor | Situação |
|---|---|
| API S3 simulada em memória | Testada a cada execução da suíte (contrato completo, falhas, conflitos, backup, LGPD) |
| Servidor S3 local (moto) | Contrato completo aprovado no ambiente de desenvolvimento em nuvem, com assinatura e protocolo reais |
| MinIO | O `jt ci` roda o mesmo contrato contra o serviço MinIO do `compose.ci.yaml` |
| AWS S3 | **Ainda não validado** com credenciais reais |
| Cloudflare R2 | **Ainda não validado** com credenciais reais |
| Google Cloud Storage | **Não suportado** até passar no contrato real (`If-Match`, chaves HMAC) |

Para validar um provedor real, rode o contrato com as credenciais fora do repositório:

```sh
JOTTER_TEST_S3_ENDPOINT=https://s3.sa-east-1.amazonaws.com \
JOTTER_TEST_S3_REGION=sa-east-1 JOTTER_TEST_S3_PATH_STYLE=0 \
JOTTER_TEST_S3_BUCKET=meu-bucket-de-teste \
JOTTER_TEST_S3_KEY=... JOTTER_TEST_S3_SECRET=... \
./scripts/jt.sh test --filter=S3VaultStoreMinioContractTest
```
