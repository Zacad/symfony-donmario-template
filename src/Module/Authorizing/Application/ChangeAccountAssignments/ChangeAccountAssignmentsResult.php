<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ChangeAccountAssignments;

final readonly class ChangeAccountAssignmentsResult
{
    public function __construct(public int $requested, public int $added, public int $removed, public int $unchanged)
    {
    }
}
