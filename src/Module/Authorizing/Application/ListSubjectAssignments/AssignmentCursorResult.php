<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListSubjectAssignments;

use Symfony\Component\Uid\Uuid;

final readonly class AssignmentCursorResult
{
    public function __construct(public Uuid $subjectId, public string $kind, public string $key)
    {
    }
}
