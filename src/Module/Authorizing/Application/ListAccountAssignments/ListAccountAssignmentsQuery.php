<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListAccountAssignments;

use Symfony\Component\Uid\Uuid;

final readonly class ListAccountAssignmentsQuery
{
    public function __construct(public Uuid $accountId, public int $limit = 50, public ?AssignmentCursorInput $after = null)
    {
    }
}
