<?php

namespace App\Domain\Users;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Everything Jotter keeps about one person (LGPD art. 18 II and V: access and
 * portability), as one JSON document the person downloads from "Your profile".
 *
 * Personal data only. Note bodies and revision contents belong to the
 * workspace (the same line UserDeletion draws), so the export lists the notes a
 * person touched, never their text; the person's own comments are included in
 * full. Secrets are never exported: password, remember token, token and secret
 * hashes.
 */
final class UserDataExport
{
    public const FORMAT = 'jotter.user-data/1';

    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $userId = (int) $user->id;
        $subjectIds = $user->membershipSubjectIds();

        return [
            'format' => self::FORMAT,
            'generated_at' => now()->toIso8601String(),
            'account' => [
                'id' => $userId,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'locale' => $user->locale,
                'is_admin' => (bool) $user->is_admin,
                'is_active' => (bool) $user->is_active,
                'signup_status' => $user->signup_status,
                'signup_requested_at' => $user->signup_requested_at?->toIso8601String(),
                'terms_accepted_at' => $user->terms_accepted_at?->toIso8601String(),
                'terms_version' => $user->terms_version,
                'deletion_requested_at' => $user->deletion_requested_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'updated_at' => $user->updated_at?->toIso8601String(),
            ],
            'sign_in_identities' => $this->rows(
                DB::table('identities')->where('user_id', $userId)->orderBy('id'),
                ['provider', 'subject_id', 'created_at', 'updated_at'],
            ),
            'memberships' => $this->rows(
                DB::table('memberships as m')
                    ->leftJoin('tenants as t', 't.id', '=', 'm.tenant_id')
                    ->leftJoin('workspaces as w', 'w.id', '=', 'm.workspace_id')
                    ->whereIn('m.subject_id', $subjectIds)
                    ->orderBy('m.id'),
                ['t.slug as tenant', 't.name as tenant_name', 'w.slug as workspace', 'w.name as workspace_name', 'm.role', 'm.created_at'],
            ),
            'groups' => $this->rows(
                DB::table('workspace_group_members as gm')
                    ->join('workspace_groups as g', 'g.id', '=', 'gm.workspace_group_id')
                    ->leftJoin('workspaces as w', 'w.id', '=', 'g.workspace_id')
                    ->where('gm.user_id', $userId)
                    ->orderBy('g.id'),
                ['w.slug as workspace', 'g.name as group', 'gm.created_at'],
            ),
            'note_permissions' => $this->rows(
                $this->withNote(DB::table('note_acl_entries as x'))
                    ->where('x.principal_type', 'user')
                    ->where('x.principal_id', (string) $userId)
                    ->orderBy('x.id'),
                ['w.slug as workspace', 'n.path as note', 'x.permission', 'x.created_at'],
            ),
            'comments' => $this->rows(
                $this->withNote(DB::table('note_comments as x'))->where('x.user_id', $userId)->orderBy('x.id'),
                ['w.slug as workspace', 'n.path as note', 'x.content', 'x.anchor_line', 'x.parent_comment_id', 'x.created_at', 'x.updated_at'],
            ),
            'watched_notes' => $this->rows(
                $this->withNote(DB::table('note_watchers as x'))->where('x.user_id', $userId)->orderBy('x.id'),
                ['w.slug as workspace', 'n.path as note', 'x.is_watching', 'x.created_at', 'x.updated_at'],
            ),
            'note_revisions_authored' => $this->rows(
                $this->withNote(DB::table('note_revisions as x'))->whereIn('x.actor_id', $subjectIds)->orderBy('x.id'),
                ['w.slug as workspace', 'n.path as note', 'x.content_hash', 'x.created_at'],
            ),
            'reviews' => $this->rows(
                $this->withNote(DB::table('note_review_workflows as x'))
                    ->where(static fn (Builder $q) => $q->where('x.reviewer_id', $userId)->orWhere('x.submitted_by_id', $userId))
                    ->orderBy('x.id'),
                ['w.slug as workspace', 'n.path as note', 'x.state', 'x.reviewer_id', 'x.submitted_by_id', 'x.submitted_at', 'x.approved_at'],
            ),
            'public_links_created' => $this->rows(
                $this->withNote(DB::table('note_shares as x'))->where('x.created_by_user_id', $userId)->orderBy('x.id'),
                ['w.slug as workspace', 'n.path as note', 'x.expires_at', 'x.revoked_at', 'x.last_accessed_at', 'x.created_at'],
            ),
            'notification_preferences' => $this->rows(
                DB::table('notification_preferences')->where('user_id', $userId)->orderBy('id'),
                ['type', 'mode', 'updated_at'],
            ),
            'notifications' => $this->rows(
                DB::table('notifications as x')->leftJoin('workspaces as w', 'w.id', '=', 'x.workspace_id')->where('x.user_id', $userId)->orderBy('x.id'),
                ['w.slug as workspace', 'x.type', 'x.title', 'x.data', 'x.read_at', 'x.created_at'],
                ['data'],
            ),
            'notification_emails' => $this->rows(
                DB::table('notification_deliveries')->where('user_id', $userId)->orderBy('id'),
                ['channel', 'kind', 'status', 'sent_at', 'failed_at', 'created_at'],
            ),
            'access_tokens' => $this->rows(
                DB::table('machine_tokens')
                    ->where(static fn (Builder $q) => $q->where('user_id', $userId)->orWhereIn('subject_id', $subjectIds))
                    ->orderBy('id'),
                ['name', 'kind', 'scopes', 'workspace_ids', 'write_paths', 'client_slug', 'expires_at', 'revoked_at', 'last_used_at', 'created_at'],
                ['scopes', 'workspace_ids', 'write_paths'],
            ),
            'connected_apps' => $this->rows(
                DB::table('oauth_grants as x')
                    ->leftJoin('oauth_clients as c', 'c.id', '=', 'x.oauth_client_id')
                    ->where(static fn (Builder $q) => $q->where('x.user_id', $userId)->orWhereIn('x.subject_id', $subjectIds))
                    ->orderBy('x.id'),
                ['c.name as client', 'x.scopes', 'x.workspace_ids', 'x.write_paths', 'x.expires_at', 'x.revoked_at', 'x.created_at'],
                ['scopes', 'workspace_ids', 'write_paths'],
            ),
            'pdf_exports' => $this->rows(
                DB::table('pdf_exports as x')->leftJoin('workspaces as w', 'w.id', '=', 'x.workspace_id')->whereIn('x.requested_by_subject', $subjectIds)->orderBy('x.id'),
                ['w.slug as workspace', 'x.scope', 'x.status', 'x.created_at', 'x.completed_at'],
            ),
            'activity_log' => $this->rows(
                DB::table('audit_log as x')->leftJoin('workspaces as w', 'w.id', '=', 'x.workspace_id')->whereIn('x.actor_subject_id', $subjectIds)->orderBy('x.id'),
                ['x.event', 'w.slug as workspace', 'x.ip_address', 'x.metadata', 'x.created_at'],
                ['metadata'],
            ),
        ];
    }

    private function withNote(Builder $query): Builder
    {
        return $query
            ->leftJoin('notes as n', 'n.id', '=', 'x.note_id')
            ->leftJoin('workspaces as w', 'w.id', '=', 'n.workspace_id');
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $json  output keys holding JSON text, decoded for readability
     * @return list<array<string, mixed>>
     */
    private function rows(Builder $query, array $columns, array $json = []): array
    {
        $rows = [];
        foreach ($query->select($columns)->cursor() as $row) {
            $row = (array) $row;
            foreach ($json as $key) {
                if (is_string($row[$key] ?? null)) {
                    $decoded = json_decode($row[$key], true);
                    $row[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $row[$key];
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
