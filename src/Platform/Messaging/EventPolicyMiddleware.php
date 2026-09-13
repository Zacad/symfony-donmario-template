<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class EventPolicyMiddleware implements MiddlewareInterface
{
    /** @param array<class-string, string> $events */
    public function __construct(private array $events = [])
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ('event' !== ($this->events[$envelope->getMessage()::class] ?? null)) {
            throw new \LogicException('event.message: dispatch an inventoried concrete Application event DTO.');
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
