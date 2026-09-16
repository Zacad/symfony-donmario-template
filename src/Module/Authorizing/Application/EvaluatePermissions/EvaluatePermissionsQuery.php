<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\EvaluatePermissions;

final readonly class EvaluatePermissionsQuery
{
    /** @param list<PermissionCheckInput> $checks */
    public function __construct(public array $checks)
    {
    }
}
