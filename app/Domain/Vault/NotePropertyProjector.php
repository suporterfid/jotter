<?php

namespace App\Domain\Vault;

use App\Models\Note;
use App\Models\NoteProperty;
use Illuminate\Support\Carbon;

final class NotePropertyProjector
{
    public function project(Note $note, array $frontmatter): void
    {
        $currentKeys = [];

        foreach ($frontmatter as $key => $value) {
            if ($key === 'tags' || $value === null) {
                continue;
            }

            $currentKeys[] = (string) $key;
            $inferred = $this->inferTypeAndValues($value);

            NoteProperty::query()->updateOrCreate(
                [
                    'note_id' => $note->id,
                    'name' => (string) $key,
                ],
                [
                    'workspace_id' => $note->workspace_id,
                    'type' => $inferred['type'] instanceof NotePropertyType ? $inferred['type']->value : $inferred['type'],
                    'value_string' => $inferred['value_string'],
                    'value_numeric' => $inferred['value_numeric'],
                    'value_boolean' => $inferred['value_boolean'],
                    'value_datetime' => $inferred['value_datetime'],
                    'value_json' => $inferred['value_json'],
                ]
            );
        }

        // Delete property rows for keys removed from front-matter
        NoteProperty::query()
            ->where('note_id', $note->id)
            ->whereNotIn('name', $currentKeys)
            ->delete();
    }

    /**
     * @return array{
     *     type: NotePropertyType,
     *     value_string: ?string,
     *     value_numeric: ?float,
     *     value_boolean: ?bool,
     *     value_datetime: ?string,
     *     value_json: ?array
     * }
     */
    public function inferTypeAndValues(mixed $value): array
    {
        $result = [
            'type' => NotePropertyType::STRING,
            'value_string' => null,
            'value_numeric' => null,
            'value_boolean' => null,
            'value_datetime' => null,
            'value_json' => null,
        ];

        if ($value instanceof \DateTimeInterface) {
            $result['type'] = NotePropertyType::DATETIME;
            $result['value_datetime'] = $value->format('Y-m-d H:i:s');
        } elseif (is_bool($value)) {
            $result['type'] = NotePropertyType::BOOLEAN;
            $result['value_boolean'] = $value;
        } elseif (is_int($value) || is_float($value)) {
            $result['type'] = NotePropertyType::NUMERIC;
            $result['value_numeric'] = (float) $value;
        } elseif (is_string($value)) {
            if ($this->isIsoDateTime($value)) {
                $result['type'] = NotePropertyType::DATETIME;
                $result['value_datetime'] = Carbon::parse($value)->toDateTimeString();
            } else {
                $result['type'] = NotePropertyType::STRING;
                $result['value_string'] = $value;
            }
        } elseif (is_array($value)) {
            if (array_is_list($value)) {
                $result['type'] = NotePropertyType::LIST;
            } else {
                $result['type'] = NotePropertyType::JSON;
            }
            $result['value_json'] = $value;
        } else {
            $result['type'] = NotePropertyType::STRING;
            $result['value_string'] = (string) $value;
        }

        return $result;
    }

    private function isIsoDateTime(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?)?$/i', trim($value))) {
            try {
                Carbon::parse($value);
                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }
}
