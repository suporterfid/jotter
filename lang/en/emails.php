<?php

return [
    'greeting' => 'Hello, :name.',
    'signoff' => '— The :brand team',
    'footer' => [
        'terms' => 'Terms',
        'privacy' => 'Privacy',
        'support' => 'Support',
        'powered_by' => 'Powered by Jotter',
    ],
    'welcome' => [
        'subject' => 'Welcome to :brand',
        'intro' => 'Your :brand account is ready. Your workspace ":workspace" is waiting for your first note.',
        'login_button' => 'Sign in',
        'credentials' => 'Sign in with :email and the password your administrator gave you, then change it from your profile menu.',
        'webdav' => 'Sync with Obsidian or any WebDAV client:',
        'mcp' => 'Connect an AI assistant with the Model Context Protocol:',
        'mcp_guide' => 'MCP guide',
        'trial' => '{1} Your trial lasts 1 day.|[2,*] Your trial lasts :days days; we will remind you before it ends.',
    ],
    'password_reset' => [
        'subject' => 'Your :brand password was reset',
        'body' => 'An administrator has reset the password of your :brand account. Use the new password they shared with you to sign in, then choose a password of your own.',
        'login_button' => 'Sign in',
        'warning' => 'If you did not expect this change, contact your administrator immediately.',
    ],
    'trial_reminder' => [
        'subject' => '{1} Your :brand trial ends tomorrow|[2,*] Your :brand trial ends in :days days',
        'body' => '{1} The trial of ":tenant" on :brand ends tomorrow (:date).|[2,*] The trial of ":tenant" on :brand ends in :days days (:date).',
        'what_happens' => 'After that date the account becomes read-only: notes, search, and exports stay available, but editing pauses until a plan is active.',
        'contact' => 'Talk to us about a plan',
    ],
    'trial_ended' => [
        'subject' => 'Your :brand trial has ended',
        'body' => 'The trial of ":tenant" on :brand has ended.',
        'read_only' => 'The account is now read-only. Everything you wrote is safe and can still be read, searched, and exported. Activate a plan to resume editing.',
        'contact' => 'Activate a plan',
    ],
];
