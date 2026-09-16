<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain;

use Symfony\Component\Uid\Uuid;

final readonly class AssignmentChange
{
    public function __construct(
        public string $operation,
        public string $kind,
        public string $key,
        public string $scope,
        public ?string $resourceType = null,
        public ?Uuid $resourceId = null,
    ) {
        if (!in_array($operation, ['add', 'remove'], true) || !in_array($kind, ['role', 'permission'], true)) {
            throw new InvalidAuthorizationInput();
        }

        AuthorizationInput::key($key);
        AuthorizationInput::scope($scope, $resourceType, $resourceId);
    }
}
