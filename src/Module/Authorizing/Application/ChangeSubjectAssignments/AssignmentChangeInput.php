<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ChangeSubjectAssignments;

final readonly class AssignmentChangeInput
{
    public function __construct(
        public string $operation,
        public string $kind,
        public string $key,
    ) {
    }
}
