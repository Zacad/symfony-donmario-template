<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\EvaluatePermissions;

final readonly class EvaluatePermissionsResult
{
    /** @param list<PermissionDecisionResult> $decisions */
    public function __construct(public array $decisions)
    {
    }
}
