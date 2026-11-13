# Conectar o GitHub: guardar um workspace num repositório privado

Este guia é para quem administra uma organização no Jotter. Ele explica como guardar um workspace num repositório **privado** do GitHub, em vez da pasta do servidor.

> **Atenção: quem tem acesso ao repositório vê o workspace inteiro.**
> O Jotter controla quem vê cada nota **dentro** do Jotter: papéis, pastas restritas e notas privadas. No GitHub essas regras não existem. Toda pessoa, aplicativo ou automação com acesso de leitura ao repositório lê **todas** as notas e anexos, inclusive os de pastas restritas. Dê acesso ao repositório só a quem pode ver tudo.

## O que muda

- As notas continuam sendo arquivos Markdown, com as mesmas pastas e os mesmos `[[links]]`. Você pode clonar o repositório e abrir no Obsidian, no VS Code ou em qualquer editor.
- **O Jotter salva na hora e envia ao GitHub em lotes.** Cada salvamento entra numa fila. A cada minuto, o Jotter grava a fila num commit, com uma mensagem como `jotter: 3 notas por Ana (via Jotter)`. A mensagem não leva e-mail.
- **Edições feitas fora do Jotter aparecem em segundos.** Um `git push` no ramo conectado avisa o Jotter na hora, pelo webhook. Se o aviso falhar, o Jotter confere o repositório a cada 5 minutos.
- **Duas pessoas editaram a mesma nota, uma no Jotter e outra no GitHub?** Nada se perde. A versão do GitHub fica com o nome original, a outra vira uma cópia `Nome (conflito GitHub AAAA-MM-DD HHMM).md`, e quem acompanha a nota recebe uma notificação.
- O selo de sincronização do workspace mostra o estado: **sincronizado**, **pendente**, **atrasado** (o GitHub limitou as gravações por um tempo; as notas continuam salvas no Jotter) ou **somente leitura** (o acesso ao repositório foi perdido).

## Antes de começar

1. **Crie um repositório privado e vazio** na conta ou organização do GitHub, por exemplo `acme/jotter-notas`. Um repositório por workspace. O Jotter recusa repositórios públicos e repositórios que já guardam outro workspace.
2. Escolha o ramo. O padrão é `main`.
3. **A organização é a controladora do repositório** (LGPD). Ao conectar, você confirma que a organização decide quem acessa o repositório e por quanto tempo o guarda. O Jotter só grava nele em nome da organização.

## Conectar

### No Jotter hospedado (GitHub App)

1. Em **Administração → Armazenamento → Adicionar armazenamento**, escolha **Repositório no GitHub** e clique em **Conectar o GitHub (instalar o App)**.
2. O GitHub abre a tela de instalação do App do Jotter. Escolha a conta ou organização e marque **Only select repositories**, com o repositório criado no passo anterior.
3. De volta ao Jotter, na mesma aba, escolha o repositório (o ramo vem preenchido com o padrão do repositório), marque a confirmação de controladora e salve.
4. Clique em **Testar conexão**. O teste só lê o repositório e nunca grava nada.

O App pede só **Contents: leitura e escrita** e **Metadata: leitura**. Cada token que o Jotter usa vale por uma hora e só para aquele repositório.

### No Jotter instalado no seu servidor (token pessoal)

1. No GitHub, crie um **fine-grained personal access token** com:
   - acesso **só ao repositório** do workspace;
   - a permissão **Contents: Read and write**;
   - uma validade, por exemplo 1 ano. O `jotter:doctor` avisa quando o token está perto de vencer.
2. Em **Administração → Armazenamento → Adicionar armazenamento**, escolha **Repositório no GitHub** e **Token pessoal (self-hosted)**. Informe o dono, o repositório, o ramo e o token, e confirme que a organização é a controladora.
3. Clique em **Testar conexão**.
4. Para ver edições externas em segundos, crie o webhook do repositório. O Jotter mostra a URL e o segredo logo depois de salvar, e de novo no botão **Webhook** do armazenamento. No GitHub, abra **Settings → Webhooks → Add webhook**, cole os dois, escolha `application/json` e só o evento **push**. Sem o webhook, as edições externas aparecem em até 5 minutos.

## Mover o workspace

Com o armazenamento testado, use **Mover um workspace**, na mesma aba (ou `php artisan vault:move <workspace> --to=<id>`). Durante a cópia o workspace fica somente leitura, e no fim o Jotter troca o armazenamento de uma vez. A pasta antiga fica guardada por 7 dias, para desfazer com `vault:move <workspace> --rollback`. Para voltar à pasta do servidor depois, mova de novo e escolha **Pasta local (padrão)**.

## O que o Jotter ignora no repositório

- Arquivos `.git*`. A exceção é `.gitkeep`, que o Jotter usa para manter pastas vazias.
- Links simbólicos e submódulos.
- Arquivos maiores que o limite (10 MB por padrão).
- Caminhos inválidos, como `..` ou caracteres de controle.

O `jotter:doctor` lista esses arquivos.

## Desconectar, apagar e cópias de segurança

- **Desinstalar o App ou revogar o token** deixa o workspace **somente leitura** na hora. Nada é apagado. Reconecte e teste a conexão para voltar ao normal.
- **Excluir a organização do Jotter** grava um último commit que remove todos os arquivos do repositório. **O histórico do Git continua guardando o conteúdo apagado, e o Jotter nunca reescreve o histórico.** Para apagar de verdade, arquive ou exclua o repositório no GitHub. O Jotter mostra essa orientação e a registra na auditoria.
- As cópias de segurança do Jotter **não incluem** os arquivos de workspaces no GitHub, porque o próprio repositório é a cópia. O operador pode incluí-los com `jotter:backup --with-remote-vaults`.
