<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\EvaluatePermissions;

use Symfony\Component\Uid\Uuid;

final readonly class PermissionCheckInput
{
    public function __construct(
        public Uuid $accountId,
        public string $permission,
        public string $scope,
        public ?string $resourceType = null,
        public ?Uuid $resourceId = null,
    ) {
    }
}
