<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

use Symfony\Component\Uid\Uuid;

/** Only the native password-upgrade adapter may establish this scoped identity. */
final readonly class AuthenticationExecution
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
    public function run(Uuid $accountId, callable $operation): mixed
    {
        return $this->context->run(new Actor(ActorKind::Authentication, $accountId), $operation);
    }
}
