<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class CommandBus
{
    public function __construct(#[Autowire(service: 'command.bus')] private MessageBusInterface $bus)
    {
    }

    public function dispatch(object $command): mixed
    {
        try {
            // Wrapping deliberately keeps a supplied Envelope as an invalid message;
            // its stamps cannot control our bus or bypass the invocation scope.
            return DispatchResult::from($this->bus->dispatch(new Envelope($command)));
        } catch (\Throwable $failure) {
            throw DispatchResult::cause($failure);
        }
    }
}
