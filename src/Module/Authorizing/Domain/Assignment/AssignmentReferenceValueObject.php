<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain\Assignment;

use App\Module\Authorizing\Domain\AuthorizationSyntaxValidator;

final readonly class AssignmentReferenceValueObject
{
    public function __construct(public AssignmentKindEnum $kind, public string $key)
    {
        AuthorizationSyntaxValidator::key($key);
    }
}
