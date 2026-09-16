<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain;

final readonly class AssignmentChanges
{
    public function __construct(public int $added, public int $removed, public int $unchanged)
    {
    }
}
