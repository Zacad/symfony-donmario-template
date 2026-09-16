<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListAccountAssignments;

final readonly class ListAccountAssignmentsResult
{
    /** @param list<AccountAssignmentResult> $assignments */
    public function __construct(public array $assignments, public ?AssignmentCursorResult $next = null)
    {
    }
}
