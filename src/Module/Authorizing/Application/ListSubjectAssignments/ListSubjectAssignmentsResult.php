<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListSubjectAssignments;

final readonly class ListSubjectAssignmentsResult
{
    /** @param list<SubjectAssignmentResult> $assignments */
    public function __construct(public array $assignments, public ?AssignmentCursorResult $next = null)
    {
    }
}
