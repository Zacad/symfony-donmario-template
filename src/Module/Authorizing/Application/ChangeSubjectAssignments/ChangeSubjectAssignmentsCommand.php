<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ChangeSubjectAssignments;

use Symfony\Component\Uid\Uuid;

final readonly class ChangeSubjectAssignmentsCommand
{
    /** @param list<AssignmentChangeInput> $changes */
    public function __construct(public Uuid $subjectId, public array $changes)
    {
    }
}
