<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class QueryBus
{
    public function __construct(#[Autowire(service: 'query.bus')] private MessageBusInterface $bus)
    {
    }

    public function ask(object $query): mixed
    {
        try {
            return DispatchResult::from($this->bus->dispatch(new Envelope($query)));
        } catch (\Throwable $failure) {
            throw DispatchResult::cause($failure);
        }
    }
}
