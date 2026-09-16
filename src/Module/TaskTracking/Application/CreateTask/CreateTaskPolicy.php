<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\CreateTask;

use App\Module\Authorizing\Application\EvaluatePermissions\EvaluatePermissionsQuery;
use App\Module\Authorizing\Application\EvaluatePermissions\EvaluatePermissionsResult;
use App\Module\Authorizing\Application\EvaluatePermissions\PermissionCheckInput;
use App\Platform\Authorization\PolicyContext;
use App\Platform\Messaging\QueryBus;

final readonly class CreateTaskPolicy
{
    public function __construct(private QueryBus $queries)
    {
    }

    public function __invoke(CreateTaskCommand $message, PolicyContext $context): bool
    {
        if ($context->actor->isOperator('tasks')) {
            return true;
        }
        if (!$context->actor->isAccount() || null === $context->actor->accountId) {
            return false;
        }

        $result = $this->queries->ask(new EvaluatePermissionsQuery([
            new PermissionCheckInput($context->actor->accountId, 'task_tracking.task.create', 'global'),
        ]));

        return $result instanceof EvaluatePermissionsResult && 1 === count($result->decisions) && $result->decisions[0]->allowed;
    }
}
