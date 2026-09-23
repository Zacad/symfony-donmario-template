<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use App\Platform\Authorization\ExecutionContext;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class EventPolicyMiddleware implements MiddlewareInterface
{
    /** @param array<class-string, string> $events */
    public function __construct(private array $events, private ExecutionContext $execution)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ('event' !== ($this->events[$envelope->getMessage()::class] ?? null)) {
            throw new \LogicException('event.message: dispatch an inventoried concrete Application event DTO.');
        }

        // Native sync transport re-enters this bus; both frames retain the publisher
        // actor. Worker delivery starts a fresh anonymous frame. Keep this separate
        // from InvocationContext so each async listener command still owns its reset.
        $this->execution->enter();
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->execution->leave();
        }
    }
}
