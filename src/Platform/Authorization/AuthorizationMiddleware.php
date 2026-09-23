<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

use App\Platform\Messaging\InvocationContext;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

final readonly class AuthorizationMiddleware implements MiddlewareInterface
{
    /** @param array<class-string, class-string> $routes */
    public function __construct(
        private InvocationContext $invocation,
        private ExecutionContext $execution,
        private AccessDecisionManagerInterface $decisions,
        private array $routes,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        $token = $this->execution->enter();
        try {
            $this->invocation->enterAuthorizationDecision();
            try {
                $attribute = $this->routes[$message::class] ?? null;
                if (null === $attribute || !$this->decisions->decide($token, [$attribute], $message)) {
                    throw new AuthorizationDenied(ActorKind::Anonymous !== $token->actor->kind);
                }
                $this->invocation->assertHealthy();
            } finally {
                $this->invocation->leaveAuthorizationDecision();
            }

            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->execution->leave();
        }
    }
}
