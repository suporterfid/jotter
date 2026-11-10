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
    case MCP_RATE_LIMITED = 'mcp.rate_limited';
    case MCP_NOTE_WRITTEN = 'mcp.note_written';
    case BRAIN_INITIALIZED = 'brain.initialized';
    case OAUTH_CLIENT_REGISTERED = 'oauth.client_registered';
    case OAUTH_GRANT_CREATED = 'oauth.grant_created';
    case OAUTH_GRANT_REVOKED = 'oauth.grant_revoked';
    case OAUTH_TOKEN_ISSUED = 'oauth.token_issued';
    case MCP_ORIGIN_REJECTED = 'mcp.origin_rejected';
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
    case INSTANCE_BACKED_UP = 'instance.backed_up';
    case SIGNUP_REQUESTED = 'signup.requested';
    case USER_DELETED = 'user.deleted';
    case PROFILE_UPDATED = 'user.profile_updated';
    case USER_TERMS_ACCEPTED = 'user.terms_accepted';
    case TWO_FACTOR_ENABLED = 'user.two_factor_enabled';
    case TWO_FACTOR_DISABLED = 'user.two_factor_disabled';
    case TWO_FACTOR_RECOVERY_CODES_REGENERATED = 'user.two_factor_recovery_codes_regenerated';
    case TWO_FACTOR_RECOVERY_CODE_USED = 'user.two_factor_recovery_code_used';
    case TWO_FACTOR_RESET = 'user.two_factor_reset';
    case USER_DATA_EXPORTED = 'user.data_exported';
    case USER_DELETION_REQUESTED = 'user.deletion_requested';
    case USER_DELETION_REQUEST_CANCELLED = 'user.deletion_request_cancelled';
    case TENANT_DELETED = 'tenant.deleted';
    case SIGNUP_APPROVED = 'signup.approved';
    case SIGNUP_REJECTED = 'signup.rejected';
    case INSTANCE_RESTORED = 'instance.restored';
    case TENANT_PLAN_CHANGED = 'tenant.plan_changed';
    case TENANT_TRIAL_REMINDER_SENT = 'tenant.trial_reminder_sent';
    case TENANT_TRIAL_EXPIRED = 'tenant.trial_expired';
    case PLAN_WRITE_BLOCKED = 'plan.write_blocked';
    case PLAN_SEAT_LIMIT_REACHED = 'plan.seat_limit_reached';

    case SYSTEM_AUDIT_PRUNED = 'system.audit_pruned';

    /**
     * Access records in the Marco Civil da Internet sense (art. 5º VIII, art. 15):
     * when an application was used and from which IP. `audit:prune` may keep
     * these longer than the rest of the audit log (JOTTER_ACCESS_LOG_RETENTION_DAYS).
     *
     * @return list<string>
     */
    public static function accessRecordValues(): array
    {
        return array_map(static fn (self $event): string => $event->value, [
            self::AUTH_LOGIN_SUCCESS,
            self::AUTH_LOGIN_FAILURE,
            self::AUTH_LOGOUT,
            self::OAUTH_TOKEN_ISSUED,
            self::MCP_CONNECTED,
        ]);
    }
}
