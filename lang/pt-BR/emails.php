<?php

return [
    'greeting' => 'Olá, :name.',
    'signoff' => '— Equipe :brand',
    'footer' => [
        'terms' => 'Termos',
        'privacy' => 'Privacidade',
        'support' => 'Suporte',
        'powered_by' => 'Powered by Jotter',
    ],
    'welcome' => [
        'subject' => 'Bem-vindo ao :brand',
        'intro' => 'Sua conta no :brand está pronta. O espaço ":workspace" está esperando pela sua primeira nota.',
        'login_button' => 'Entrar',
        'credentials' => 'Entre com :email e a senha que o administrador lhe passou; depois troque-a no menu do seu perfil.',
        'webdav' => 'Sincronize com o Obsidian ou qualquer cliente WebDAV:',
        'mcp' => 'Conecte um assistente de IA pelo Model Context Protocol:',
        'mcp_guide' => 'Guia do MCP',
        'trial' => '{1} Seu período de teste dura 1 dia.|[2,*] Seu período de teste dura :days dias; avisaremos antes de terminar.',
    ],
    'signup_requested' => [
        'subject' => ':name pediu uma conta',
        'body' => ':name (:email) pediu uma conta no :brand. O acesso fica desligado até um administrador aprovar.',
        'button' => 'Abrir o :brand',
        'where' => 'Aprove ou rejeite o pedido em Administração → Usuários.',
    ],
    'signup_approved' => [
        'subject' => 'Sua conta no :brand foi aprovada',
        'body' => 'Um administrador aprovou sua conta no :brand. Você já pode entrar com o e-mail e a senha que escolheu.',
        'login_button' => 'Entrar',
    ],
    'signup_rejected' => [
        'subject' => 'Seu pedido de conta no :brand',
        'body' => 'Seu pedido de conta no :brand não foi aprovado, e os dados que você enviou foram apagados. Se acha que foi um engano, fale com o administrador deste site.',
    ],
    'password_reset' => [
        'subject' => 'Sua senha do :brand foi redefinida',
        'body' => 'Um administrador redefiniu a senha da sua conta no :brand. Use a nova senha que ele compartilhou com você para entrar e, em seguida, escolha uma senha sua.',
        'login_button' => 'Entrar',
        'warning' => 'Se você não esperava esta alteração, contate o administrador imediatamente.',
    ],
    'trial_reminder' => [
        'subject' => '{1} Seu período de teste no :brand termina amanhã|[2,*] Seu período de teste no :brand termina em :days dias',
        'body' => '{1} O período de teste de ":tenant" no :brand termina amanhã (:date).|[2,*] O período de teste de ":tenant" no :brand termina em :days dias (:date).',
        'what_happens' => 'Depois dessa data a conta fica somente leitura: notas, busca e exportações continuam disponíveis, mas a edição pausa até um plano estar ativo.',
        'contact' => 'Fale conosco sobre um plano',
    ],
    'trial_ended' => [
        'subject' => 'Seu período de teste no :brand terminou',
        'body' => 'O período de teste de ":tenant" no :brand terminou.',
        'read_only' => 'A conta agora está somente leitura. Tudo o que você escreveu está seguro e continua podendo ser lido, buscado e exportado. Ative um plano para voltar a editar.',
        'contact' => 'Ativar um plano',
    ],
];
