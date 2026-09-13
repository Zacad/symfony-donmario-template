<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use App\Platform\Event\ApplicationEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class EventBus
{
    public function __construct(#[Autowire(service: 'application.event.bus')] private MessageBusInterface $bus, private InvocationContext $context)
    {
    }

    public function dispatch(ApplicationEvent $event): void
    {
        try {
            $this->context->assertEventDispatchAllowed();
            $this->bus->dispatch($event);
        } catch (\Throwable $failure) {
            $this->context->fail($failure);
            throw $failure;
        }
    }
}
