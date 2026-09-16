<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Application\CheckAccountExistence;

use App\Module\Authorizing\Application\ChangeAccountAssignments\ChangeAccountAssignmentsCommand;
use App\Module\Authorizing\Application\EvaluatePermissions\EvaluatePermissionsQuery;
use App\Platform\Authorization\PolicyContext;

final readonly class CheckAccountExistencePolicy
{
    public function __invoke(CheckAccountExistenceQuery $message, PolicyContext $context): bool
    {
        return $context->supportRead
            || EvaluatePermissionsQuery::class === $context->caller
            || ChangeAccountAssignmentsCommand::class === $context->caller;
    }
}
