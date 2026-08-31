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
    case MACHINE_TOKEN_CREATED = 'machine_token.created';
    case MACHINE_TOKEN_REVOKED = 'machine_token.revoked';

    case ATTACHMENT_CREATED = 'attachment.created';
    case ATTACHMENT_DELETED = 'attachment.deleted';

    case NOTE_CREATED = 'note.created';
    case NOTE_UPDATED = 'note.updated';
    case NOTE_DELETED = 'note.deleted';
    case NOTE_MOVED = 'note.moved';
    case NOTE_VIEWED = 'note.viewed';
    case NOTE_SHARE_CREATED = 'note.share_created';
    case NOTE_SHARE_REVOKED = 'note.share_revoked';
    case NOTE_REVIEWER_ASSIGNED = 'note.reviewer_assigned';
    case NOTE_REVIEW_SUBMITTED = 'note.review_submitted';
    case NOTE_REVIEW_APPROVED = 'note.review_approved';
    case NOTE_REVIEW_CHANGES_REQUESTED = 'note.review_changes_requested';
    case NOTE_REVIEW_INVALIDATED = 'note.review_invalidated';

    case TENANT_PROVISIONED = 'tenant.provisioned';
    case TENANT_EXPORTED = 'tenant.exported';
    case TENANT_PLAN_CHANGED = 'tenant.plan_changed';
    case TENANT_TRIAL_REMINDER_SENT = 'tenant.trial_reminder_sent';
    case TENANT_TRIAL_EXPIRED = 'tenant.trial_expired';
    case PLAN_WRITE_BLOCKED = 'plan.write_blocked';
    case PLAN_SEAT_LIMIT_REACHED = 'plan.seat_limit_reached';

    case SYSTEM_AUDIT_PRUNED = 'system.audit_pruned';
}
