<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain\Assignment;

use App\Module\Authorizing\Domain\AuthorizationSyntaxValidator;
use Symfony\Component\Uid\Uuid;

final readonly class EntitlementCheckValueObject
{
    public function __construct(public Uuid $subjectId, public string $permission)
    {
        AuthorizationSyntaxValidator::key($permission);
    }
}
