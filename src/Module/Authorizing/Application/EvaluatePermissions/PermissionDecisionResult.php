<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\EvaluatePermissions;

final readonly class PermissionDecisionResult
{
    public function __construct(public bool $allowed)
    {
    }
}
