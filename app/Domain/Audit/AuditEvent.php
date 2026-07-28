<?php

namespace App\Domain\Audit;

enum AuditEvent: string
{
    case AUTH_LOGIN_SUCCESS = 'auth.login_success';
    case AUTH_LOGIN_FAILURE = 'auth.login_failure';
    case AUTH_LOGOUT = 'auth.logout';
    case AUTH_UNAUTHORIZED = 'auth.unauthorized';
    case AUTH_FORBIDDEN = 'auth.forbidden';

    case VAULT_PATH_TRAVERSAL_REJECTED = 'vault.path_traversal_rejected';
    case VAULT_ROOT_REJECTED = 'vault.root_rejected';
    case WORKSPACE_ARCHIVED = 'workspace.archived';

    case ATTACHMENT_CREATED = 'attachment.created';
    case ATTACHMENT_DELETED = 'attachment.deleted';

    case NOTE_CREATED = 'note.created';
    case NOTE_UPDATED = 'note.updated';
    case NOTE_DELETED = 'note.deleted';
    case NOTE_MOVED = 'note.moved';

    case SYSTEM_AUDIT_PRUNED = 'system.audit_pruned';
}
