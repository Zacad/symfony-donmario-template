<?php

declare(strict_types=1);

namespace App\Platform\Event\Recording;

use App\Platform\Event\DomainEvent;

trait RecordsDomainEventsTrait
{
    /** @var list<DomainEvent> */
    private array $recordedDomainEvents = [];

    protected function recordDomainEvent(DomainEvent $event): void
    {
        $this->recordedDomainEvents[] = $event;
    }

    /** @return list<DomainEvent> */
    public function releaseEvents(): array
    {
        $events = $this->recordedDomainEvents;
        $this->recordedDomainEvents = [];

        return $events;
    }
}
