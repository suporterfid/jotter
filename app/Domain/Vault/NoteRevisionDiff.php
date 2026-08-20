<?php

namespace App\Domain\Vault;

use InvalidArgumentException;

/**
 * Produces a bounded line-level diff without persisting derived data.
 */
final class NoteRevisionDiff
{
    private const DEFAULT_MAX_LINES = 5000;

    /**
     * @return array{changed: bool, lines: list<array{type: string, from_line: int|null, to_line: int|null, text: string}>}
     */
    public function compare(string $from, string $to, int $maxLines = self::DEFAULT_MAX_LINES): array
    {
        if ($maxLines < 1) {
            throw new InvalidArgumentException('The line limit must be positive.');
        }

        $fromLines = $this->splitLines($from);
        $toLines = $this->splitLines($to);

        if (count($fromLines) > $maxLines || count($toLines) > $maxLines) {
            throw new InvalidArgumentException('Revision comparison exceeds the line limit.');
        }

        if ($fromLines === $toLines) {
            return ['changed' => false, 'lines' => []];
        }

        return [
            'changed' => true,
            'lines' => $this->formatOperations($this->operations($fromLines, $toLines)),
        ];
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $contents): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $contents);
        $normalized = rtrim($normalized, "\n");

        return $normalized === '' ? [] : explode("\n", $normalized);
    }

    /**
     * Myers' shortest edit script keeps memory bounded by the number of lines.
     *
     * @param  list<string>  $from
     * @param  list<string>  $to
     * @return list<array{type: string, text: string}>
     */
    private function operations(array $from, array $to): array
    {
        $fromCount = count($from);
        $toCount = count($to);
        $max = $fromCount + $toCount;
        $frontier = [1 => 0];
        $trace = [];

        for ($distance = 0; $distance <= $max; $distance++) {
            $trace[] = $frontier;

            for ($diagonal = -$distance; $diagonal <= $distance; $diagonal += 2) {
                if (
                    $diagonal === -$distance
                    || ($diagonal !== $distance && ($frontier[$diagonal - 1] ?? 0) < ($frontier[$diagonal + 1] ?? 0))
                ) {
                    $fromIndex = $frontier[$diagonal + 1] ?? 0;
                } else {
                    $fromIndex = ($frontier[$diagonal - 1] ?? 0) + 1;
                }

                $toIndex = $fromIndex - $diagonal;
                while (
                    $fromIndex < $fromCount
                    && $toIndex < $toCount
                    && $from[$fromIndex] === $to[$toIndex]
                ) {
                    $fromIndex++;
                    $toIndex++;
                }

                $frontier[$diagonal] = $fromIndex;

                if ($fromIndex >= $fromCount && $toIndex >= $toCount) {
                    return $this->backtrack($trace, $from, $to, $distance);
                }
            }
        }

        return [];
    }

    /**
     * @param  list<array<int, int>>  $trace
     * @param  list<string>  $from
     * @param  list<string>  $to
     * @return list<array{type: string, text: string}>
     */
    private function backtrack(array $trace, array $from, array $to, int $distance): array
    {
        $fromIndex = count($from);
        $toIndex = count($to);
        $operations = [];

        for ($currentDistance = $distance; $currentDistance > 0; $currentDistance--) {
            // The trace is captured at the start of each iteration, so the
            // entry at this distance contains the previous edit frontier.
            $previousFrontier = $trace[$currentDistance];
            $diagonal = $fromIndex - $toIndex;

            if (
                $diagonal === -$currentDistance
                || ($diagonal !== $currentDistance && ($previousFrontier[$diagonal - 1] ?? 0) < ($previousFrontier[$diagonal + 1] ?? 0))
            ) {
                $previousDiagonal = $diagonal + 1;
            } else {
                $previousDiagonal = $diagonal - 1;
            }

            $previousFromIndex = $previousFrontier[$previousDiagonal] ?? 0;
            $previousToIndex = $previousFromIndex - $previousDiagonal;

            while ($fromIndex > $previousFromIndex && $toIndex > $previousToIndex) {
                array_unshift($operations, ['type' => 'context', 'text' => $from[$fromIndex - 1]]);
                $fromIndex--;
                $toIndex--;
            }

            if ($fromIndex === $previousFromIndex) {
                array_unshift($operations, ['type' => 'added', 'text' => $to[$toIndex - 1]]);
                $toIndex--;
            } else {
                array_unshift($operations, ['type' => 'removed', 'text' => $from[$fromIndex - 1]]);
                $fromIndex--;
            }
        }

        while ($fromIndex > 0 && $toIndex > 0) {
            array_unshift($operations, ['type' => 'context', 'text' => $from[$fromIndex - 1]]);
            $fromIndex--;
            $toIndex--;
        }
        while ($fromIndex > 0) {
            array_unshift($operations, ['type' => 'removed', 'text' => $from[$fromIndex - 1]]);
            $fromIndex--;
        }
        while ($toIndex > 0) {
            array_unshift($operations, ['type' => 'added', 'text' => $to[$toIndex - 1]]);
            $toIndex--;
        }

        return $operations;
    }

    /**
     * @param  list<array{type: string, text: string}>  $operations
     * @return list<array{type: string, from_line: int|null, to_line: int|null, text: string}>
     */
    private function formatOperations(array $operations): array
    {
        $fromLine = 0;
        $toLine = 0;
        $formatted = [];

        foreach ($operations as $operation) {
            if ($operation['type'] === 'context') {
                $fromLine++;
                $toLine++;
                $formatted[] = [
                    'type' => 'context',
                    'from_line' => $fromLine,
                    'to_line' => $toLine,
                    'text' => $operation['text'],
                ];
                continue;
            }

            if ($operation['type'] === 'removed') {
                $fromLine++;
                $formatted[] = [
                    'type' => 'removed',
                    'from_line' => $fromLine,
                    'to_line' => null,
                    'text' => $operation['text'],
                ];
                continue;
            }

            $toLine++;
            $formatted[] = [
                'type' => 'added',
                'from_line' => null,
                'to_line' => $toLine,
                'text' => $operation['text'],
            ];
        }

        return $formatted;
    }
}
