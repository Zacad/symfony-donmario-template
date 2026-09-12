<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

/** Append-only Application capability; the inventory is supplied by CqrsPass. */
final readonly class ApplicationEventRecorder
{
    /** @param array<class-string, string> $events */
    public function __construct(private InvocationContext $context, private array $events = [])
    {
    }

    public function record(object $event): void
    {
        try {
            if ('event' !== ($this->events[$event::class] ?? null)) {
                throw new \LogicException('event.message: record an inventoried concrete Application event DTO.');
            }
            $this->context->record($event);
        } catch (\Throwable $failure) {
            $this->context->fail($failure);
            throw $failure;
        }
    }
}
