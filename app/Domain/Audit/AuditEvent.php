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

    case MEMBERSHIP_GRANTED = 'membership.granted';
    case MEMBERSHIP_UPDATED = 'membership.updated';
    case MEMBERSHIP_REVOKED = 'membership.revoked';

    case USER_CREATED = 'user.created';
    case USER_DEACTIVATED = 'user.deactivated';
    case USER_REACTIVATED = 'user.reactivated';
    case USER_PASSWORD_RESET = 'user.password_reset';

    case MCP_CONNECTED = 'mcp.connected';
    case MCP_METHOD_CALLED = 'mcp.method_called';
    case MCP_AUTH_FAILED = 'mcp.auth_failed';

    case ATTACHMENT_CREATED = 'attachment.created';
    case ATTACHMENT_DELETED = 'attachment.deleted';

    case NOTE_CREATED = 'note.created';
    case NOTE_UPDATED = 'note.updated';
    case NOTE_DELETED = 'note.deleted';
    case NOTE_MOVED = 'note.moved';

    case SYSTEM_AUDIT_PRUNED = 'system.audit_pruned';
}
