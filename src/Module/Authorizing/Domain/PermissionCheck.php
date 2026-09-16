<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain;

use Symfony\Component\Uid\Uuid;

final readonly class PermissionCheck
{
    public function __construct(
        public Uuid $accountId,
        public string $permission,
        public string $scope,
        public ?string $resourceType = null,
        public ?Uuid $resourceId = null,
    ) {
        AuthorizationInput::key($permission);
        AuthorizationInput::scope($scope, $resourceType, $resourceId);
    }
}
