<?php

namespace App\Domain\Privacy;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Provisioning\TenantDeletion;
use App\Domain\Users\UserDeletion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * After a restore, deletes again every tenant and account named in the
 * erasure log (block 4c), through the same code as `tenant:delete` and
 * `user:delete`. A row is only deleted when its id and its created_at both
 * match the tombstone, so a new row that reused a deleted id is never touched.
 */
final class ErasureReplay
{
    public function __construct(
        private readonly AuditRecorder $audit = new AuditRecorder,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{tenants: int, users: int, already_gone: int, refused: list<string>}
     */
    public function replay(array $entries): array
    {
        $result = ['tenants' => 0, 'users' => 0, 'already_gone' => 0, 'refused' => []];
        $users = new UserDeletion;
        $tenants = new TenantDeletion($users);

        // Tenants first: deleting one also deletes the accounts that only belonged to it.
        usort($entries, static fn (array $a, array $b): int => ($a['type'] === 'tenant' ? 0 : 1) <=> ($b['type'] === 'tenant' ? 0 : 1));

        foreach ($entries as $entry) {
            $model = $entry['type'] === 'tenant'
                ? Tenant::query()->find($entry['id'])
                : User::query()->find($entry['id']);
            if ($model === null || ! self::sameRow($model->created_at, $entry['created_at'] ?? null)) {
                $result['already_gone']++;

                continue;
            }

            try {
                if ($model instanceof Tenant) {
                    $tenants->delete($model, 'cli:jotter:restore');
                    $result['tenants']++;
                } else {
                    $users->delete($model, 'cli:jotter:restore');
                    $result['users']++;
                }
            } catch (RuntimeException $exception) {
                $result['refused'][] = $entry['type'].' '.$entry['id'].': '.$exception->getMessage();
            }
        }

        if ($result['tenants'] + $result['users'] > 0 || $result['refused'] !== []) {
            $this->audit->record(event: AuditEvent::INSTANCE_ERASURES_REPLAYED, actorId: 'cli:jotter:restore', metadata: [
                'tenants' => $result['tenants'], 'users' => $result['users'], 'refused' => count($result['refused']),
            ]);
        }

        return $result;
    }

    private static function sameRow(mixed $rowCreatedAt, ?string $tombstoneCreatedAt): bool
    {
        if ($tombstoneCreatedAt === null || $rowCreatedAt === null) {
            return $tombstoneCreatedAt === null && $rowCreatedAt === null;
        }

        return Carbon::parse($rowCreatedAt)->getTimestamp() === Carbon::parse($tombstoneCreatedAt)->getTimestamp();
    }
}
