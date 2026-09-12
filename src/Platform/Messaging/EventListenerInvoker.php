<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Constructed inline by CqrsPass only, after validating the original listener. */
#[Exclude]
final readonly class EventListenerInvoker
{
    /** @param \Closure(): callable $listener */
    public function __construct(private \Closure $listener)
    {
    }

    public function __invoke(object $event): void
    {
        // Resolution belongs inside HandleMessageMiddleware's per-handler catch,
        // rather than in its lazy descriptor iterator's next() operation.
        ($this->listener)()($event);
    }
}
