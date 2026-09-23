<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListSubjectAssignments;

final readonly class SubjectAssignmentResult
{
    public function __construct(
        public string $kind,
        public string $key,
    ) {
    }
}
