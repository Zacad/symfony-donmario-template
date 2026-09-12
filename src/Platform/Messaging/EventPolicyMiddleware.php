<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class EventPolicyMiddleware implements MiddlewareInterface
{
    /** @param array<class-string, string> $events */
    public function __construct(private EventDeliveryContext $delivery, private InvocationContext $invocation, private array $events = [])
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            $this->invocation->assertUsable();
            if (!$this->invocation->isRoot() || [] !== $envelope->all() || 'event' !== ($this->events[$envelope->getMessage()::class] ?? null)) {
                throw new \LogicException('event.message: only an after-commit concrete Application event can be delivered.');
            }
            $this->delivery->authorize($envelope->getMessage());
        } catch (\Throwable $failure) {
            $this->invocation->fail($failure);
            throw $failure;
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
