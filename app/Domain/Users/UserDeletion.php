<?php

namespace App\Domain\Users;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Models\Identity;
use App\Models\MachineToken;
use App\Models\Membership;
use App\Models\NoteAclEntry;
use App\Models\OAuthGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deletes a user account and what identifies them (LGPD art. 18, #390
 * follow-up), in one transaction:
 *
 * - removed: memberships, manual and OAuth tokens and grants, note ACL entries,
 *   linked identities, sessions; notifications, preferences, watches and group
 *   memberships go with the user row (foreign keys cascade);
 * - kept, anonymised: comments (the text stays in the workspace, the author
 *   becomes "deleted user"), revision and audit actors, PDF export requester;
 *   e-mail addresses are removed from audit metadata.
 *
 * Notes and attachments stay: they belong to the workspace, not to the person.
 */
final class UserDeletion
{
    public const DELETED_ACTOR = 'deleted-user';

    public function __construct(private readonly AuditRecorder $audit = new AuditRecorder) {}

    /**
     * @throws RuntimeException when the account must not be deleted
     * @return array<string, int> rows touched per kind
     */
    public function delete(User $user, ?string $actorId = null): array
    {
        if ($actorId !== null && $actorId === (string) $user->id) {
            throw new RuntimeException(__('messages.user_delete_self'));
        }
        if ($user->is_admin && User::query()->where('is_admin', true)->where('is_active', true)->whereKeyNot($user->id)->doesntExist()) {
            throw new RuntimeException(__('messages.user_delete_last_admin'));
        }

        $subjectIds = $user->membershipSubjectIds();
        $email = (string) $user->email;
        $userId = (int) $user->id;
        $counts = [];

        DB::transaction(function () use ($user, $subjectIds, $email, $userId, &$counts): void {
            $counts['memberships'] = Membership::query()->whereIn('subject_id', $subjectIds)->delete();
            $counts['tokens'] = MachineToken::query()->where(static fn ($q) => $q->whereIn('subject_id', $subjectIds)->orWhere('user_id', $userId))->delete();
            $counts['oauth_grants'] = OAuthGrant::query()->where(static fn ($q) => $q->whereIn('subject_id', $subjectIds)->orWhere('user_id', $userId))->delete();
            $counts['acl_entries'] = NoteAclEntry::query()->where('principal_type', 'user')->where('principal_id', $userId)->delete();
            $counts['identities'] = Identity::query()->where('user_id', $userId)->delete();
            $counts['sessions'] = DB::table('sessions')->where('user_id', $userId)->delete();

            $counts['comments'] = DB::table('note_comments')->where('user_id', $userId)->update(['actor_name' => __('messages.deleted_user'), 'user_id' => null]);
            $counts['revisions'] = DB::table('note_revisions')->whereIn('actor_id', $subjectIds)->update(['actor_id' => self::DELETED_ACTOR]);
            $counts['pdf_exports'] = DB::table('pdf_exports')->whereIn('requested_by_subject', $subjectIds)->update(['requested_by_subject' => self::DELETED_ACTOR]);
            $counts['audit_actor'] = DB::table('audit_log')->whereIn('actor_subject_id', $subjectIds)->update(['actor_subject_id' => self::DELETED_ACTOR]);
            if (DB::connection()->getDriverName() === 'mysql' && $email !== '') {
                $counts['audit_email'] = DB::table('audit_log')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.email')) = ?", [$email])
                    ->update(['metadata' => DB::raw("JSON_REMOVE(metadata, '$.email')")]);
            }

            $user->delete();
        });

        // Recorded after the deletion, with no personal data about the person.
        $this->audit->record(event: AuditEvent::USER_DELETED, actorId: $actorId, metadata: ['user_id' => $userId] + $counts);

        return $counts;
    }
}
