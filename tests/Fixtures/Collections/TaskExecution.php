<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Collections;

use App\Platform\Authorization\Actor;
use App\Platform\Authorization\ActorKind;
use App\Platform\Authorization\ExecutionContext;

/** Trusted test-process adapter; only the production tasks operator scope is supplied. */
final readonly class TaskExecution
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
    public function run(callable $operation): mixed
    {
        return $this->context->run(new Actor(ActorKind::Operator, scope: 'tasks'), $operation);
    }
}
