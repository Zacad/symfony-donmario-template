<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

use App\Platform\Messaging\InvocationContext;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class AuthorizationMiddleware implements MiddlewareInterface
{
    public function __construct(private InvocationContext $invocation, private ExecutionContext $execution, private ContainerInterface $policies)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        $context = $this->execution->enter($message::class);
        try {
            $this->invocation->enterPolicy();
            try {
                // Resolution is inside the restrictive scope too, including constructors.
                $policy = $this->policies->get($message::class);
                if (!is_callable($policy)) {
                    throw new \LogicException('authorization.policy: Invalid policy service.');
                }
                $allowed = $policy($message, $context);
                if (!is_bool($allowed)) {
                    throw new \LogicException('authorization.policy: Invalid policy decision.');
                }
                if (!$allowed) {
                    throw new AuthorizationDenied(ActorKind::Anonymous !== $context->actor->kind);
                }
                $this->invocation->assertHealthy();
            } finally {
                $this->invocation->leavePolicy();
            }

            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->execution->leave();
        }
    }
}
