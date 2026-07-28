<?php

namespace App\Domain\Search;

final class SearchCriteria
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly ?string $query = null,
        public readonly array $tags = [],
        public readonly ?string $title = null,
        public readonly ?string $modifiedAfter = null,
        public readonly ?string $modifiedBefore = null,
        public readonly int $page = 1,
        public readonly int $perPage = 50
    ) {}

    public function isEmpty(): bool
    {
        return $this->query === null
            && empty($this->tags)
            && $this->title === null
            && $this->modifiedAfter === null
            && $this->modifiedBefore === null;
    }
}
