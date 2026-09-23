<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ChangeSubjectAssignments;

final readonly class ChangeSubjectAssignmentsResult
{
    public function __construct(public int $requested, public int $added, public int $removed, public int $unchanged)
    {
    }
}
