<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\EvaluatePermissions;

use App\Platform\Authorization\PolicyContext;

final readonly class EvaluatePermissionsPolicy
{
    public function __invoke(EvaluatePermissionsQuery $message, PolicyContext $context): bool
    {
        return $context->supportRead || $context->actor->isOperator('assignments');
    }
}
