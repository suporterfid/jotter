<?php

namespace App\Domain\Privacy;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Append-only list of erased tenants and accounts (LGPD block 4c, decision
 * "Erasure from backups: tombstones replayed on restore"). It lives outside
 * the database, so a restore that rewrites the tables cannot erase it, and a
 * copy rides in every backup. `jotter:restore` replays it (ErasureReplay), so
 * a restore never brings deleted data back.
 *
 * One JSON object per line: {type: tenant|user, id, created_at, slug?,
 * deleted_at, actor}. No names or e-mails. `created_at` is the erased row's
 * own creation time: after a restore, MySQL may hand a deleted row's id to a
 * new row, and a replay only deletes a row whose id AND created_at match.
 */
final class ErasureLog
{
    public const FILE_IN_BACKUP = 'erasure-log.ndjson';

    public const TYPES = ['tenant', 'user'];

    public static function path(): string
    {
        return (string) (config('jotter.erasure_log.path') ?: storage_path('app/private/erasure-log.ndjson'));
    }

    /**
     * Records one erasure; recording the same row twice keeps the first entry.
     *
     * @param  array{slug?: string}  $extra
     */
    public function record(string $type, int $id, ?string $createdAt, ?string $actor = null, array $extra = []): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new RuntimeException("Unknown erasure type [{$type}].");
        }
        foreach ($this->entries() as $entry) {
            if ($entry['type'] === $type && $entry['id'] === $id && $entry['created_at'] === $createdAt) {
                return;
            }
        }

        $line = array_filter([
            'type' => $type,
            'id' => $id,
            'created_at' => $createdAt,
            'slug' => $extra['slug'] ?? null,
            'deleted_at' => now()->toIso8601String(),
            'actor' => $actor,
        ], static fn ($value): bool => $value !== null);

        File::ensureDirectoryExists(dirname(self::path()), 0750);
        if (file_put_contents(self::path(), json_encode($line, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the erasure log at '.self::path().'.');
        }
        @chmod(self::path(), 0600);
    }

    public function exists(): bool
    {
        return is_file(self::path());
    }

    /**
     * @return list<array{type: string, id: int, created_at: ?string, slug?: string, deleted_at: string, actor?: string}>
     */
    public function entries(): array
    {
        return $this->exists() ? self::parse((string) file_get_contents(self::path())) : [];
    }

    /**
     * @return list<array{type: string, id: int, created_at: ?string, slug?: string, deleted_at: string, actor?: string}>
     */
    public static function parse(string $ndjson): array
    {
        $entries = [];
        foreach (preg_split('/\R/', $ndjson) ?: [] as $line) {
            $entry = json_decode(trim($line), true);
            if (! is_array($entry) || ! in_array($entry['type'] ?? null, self::TYPES, true) || ! is_int($entry['id'] ?? null) || ! is_string($entry['deleted_at'] ?? null)) {
                continue;
            }
            $entry['created_at'] = is_string($entry['created_at'] ?? null) ? $entry['created_at'] : null;
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Union of several logs, one entry per erased row (the earliest one).
     *
     * @param  list<array<string, mixed>>  ...$lists
     * @return list<array<string, mixed>>
     */
    public static function merge(array ...$lists): array
    {
        $byKey = [];
        foreach ($lists as $list) {
            foreach ($list as $entry) {
                $key = $entry['type'].'|'.$entry['id'].'|'.($entry['created_at'] ?? '');
                if (! isset($byKey[$key]) || strcmp($entry['deleted_at'], $byKey[$key]['deleted_at']) < 0) {
                    $byKey[$key] = $entry;
                }
            }
        }
        $merged = array_values($byKey);
        usort($merged, static fn (array $a, array $b): int => strcmp($a['deleted_at'], $b['deleted_at']));

        return $merged;
    }

    /**
     * Rewrites the live log as $entries (used after a restore merges logs).
     *
     * @param  list<array<string, mixed>>  $entries
     */
    public function replace(array $entries): void
    {
        File::ensureDirectoryExists(dirname(self::path()), 0750);
        $tmp = self::path().'.tmp-'.bin2hex(random_bytes(4));
        $body = implode('', array_map(static fn (array $entry): string => json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL, $entries));
        if (file_put_contents($tmp, $body, LOCK_EX) === false || ! rename($tmp, self::path())) {
            @unlink($tmp);
            throw new RuntimeException('Unable to rewrite the erasure log at '.self::path().'.');
        }
        @chmod(self::path(), 0600);
    }

    /**
     * Drops entries older than $days (JOTTER_ERASURE_LOG_DAYS); returns how many.
     */
    public function prune(int $days): int
    {
        if ($days < 1 || ! $this->exists()) {
            return 0;
        }
        $cutoff = now()->subDays($days);
        $entries = $this->entries();
        $kept = array_values(array_filter($entries, static fn (array $entry): bool => ! \Illuminate\Support\Carbon::parse($entry['deleted_at'])->lt($cutoff)));
        if (count($kept) !== count($entries)) {
            $this->replace($kept);
        }

        return count($entries) - count($kept);
    }
}
