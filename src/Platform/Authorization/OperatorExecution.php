<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

/** Available only to the exact trusted operator adapters checked at build time. */
final readonly class OperatorExecution
{
    public function __construct(private ExecutionContext $context)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(string $scope, callable $operation): mixed
    {
        if (!in_array($scope, ['accounts', 'assignments', 'catalogue', 'tasks'], true)) {
            throw new \LogicException('authorization.context: Unknown operator scope.');
        }

        return $this->context->run(new Actor(ActorKind::Operator, scope: $scope), $operation);
    }
}
