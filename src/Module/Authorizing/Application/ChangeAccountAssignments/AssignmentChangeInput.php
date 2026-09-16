<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ChangeAccountAssignments;

use Symfony\Component\Uid\Uuid;

final readonly class AssignmentChangeInput
{
    public function __construct(
        public string $operation,
        public string $kind,
        public string $key,
        public string $scope,
        public ?string $resourceType = null,
        public ?Uuid $resourceId = null,
    ) {
    }
}
