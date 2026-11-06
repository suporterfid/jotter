<?php

namespace App\Domain\Semantic\Contracts;

use App\Domain\Semantic\EmbeddingFailed;

interface EmbeddingProvider
{
    /**
     * One vector per input, in input order.
     *
     * @param list<string> $inputs
     * @return list<list<float>>
     *
     * @throws EmbeddingFailed
     */
    public function embed(array $inputs, int $timeoutSeconds): array;

    /** Identifies the vectors' space; rows from another model are re-embedded. */
    public function model(): string;
}
