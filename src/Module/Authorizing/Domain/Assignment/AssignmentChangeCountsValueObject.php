<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain\Assignment;

use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;

final readonly class AssignmentChangeCountsValueObject
{
    public function __construct(public int $added, public int $removed)
    {
        if ($added < 0 || $removed < 0) {
            throw new InvalidAuthorizationInputException();
        }
    }
}
