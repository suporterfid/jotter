<?php

namespace App\Domain\Auth\GrandpaSson;

final class IntrospectionResult
{
    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $audiences
     */
    public function __construct(
        public readonly bool $active,
        public readonly array $scopes = [],
        public readonly array $audiences = [],
        public readonly ?string $clientId = null,
        public readonly ?string $subject = null,
    ) {}

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function audienceIncludesWorkspace(int $workspaceId): bool
    {
        return in_array("workspace/{$workspaceId}", $this->audiences, true);
    }
}
