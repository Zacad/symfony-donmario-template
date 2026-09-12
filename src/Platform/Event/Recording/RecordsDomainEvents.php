<?php

declare(strict_types=1);

namespace App\Platform\Event\Recording;

use App\Platform\Event\DomainEvent;

interface RecordsDomainEvents
{
    /** @return list<DomainEvent> */
    public function releaseEvents(): array;
}
