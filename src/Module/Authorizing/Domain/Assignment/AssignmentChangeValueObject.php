<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain\Assignment;

final readonly class AssignmentChangeValueObject
{
    public function __construct(
        public AssignmentOperationEnum $operation,
        public AssignmentReferenceValueObject $reference,
    ) {
    }
}
