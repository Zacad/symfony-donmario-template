<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListSubjectAssignments;

use Symfony\Component\Uid\Uuid;

final readonly class ListSubjectAssignmentsQuery
{
    public function __construct(public Uuid $subjectId, public int $limit = 50, public ?AssignmentCursorInput $after = null)
    {
    }
}
