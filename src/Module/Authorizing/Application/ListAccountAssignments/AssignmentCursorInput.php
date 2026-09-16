<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListAccountAssignments;

use Symfony\Component\Uid\Uuid;

final readonly class AssignmentCursorInput
{
    public function __construct(public Uuid $accountId, public int $source, public Uuid $assignmentId)
    {
    }
}
