<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListAccountAssignments;

use Symfony\Component\Uid\Uuid;

final readonly class AccountAssignmentResult
{
    public function __construct(
        public Uuid $id,
        public int $source,
        public string $kind,
        public string $key,
        public string $scope,
        public ?string $resourceType,
        public ?Uuid $resourceId,
    ) {
    }
}
