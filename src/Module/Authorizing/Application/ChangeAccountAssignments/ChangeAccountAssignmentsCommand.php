<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ChangeAccountAssignments;

use Symfony\Component\Uid\Uuid;

final readonly class ChangeAccountAssignmentsCommand
{
    /** @param list<AssignmentChangeInput> $changes */
    public function __construct(public Uuid $accountId, public array $changes)
    {
    }
}
