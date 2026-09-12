<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Domain\Event;

use App\Platform\Event\DomainEvent;
use Symfony\Component\Uid\Uuid;

final readonly class TaskCreatedEvent extends DomainEvent
{
    public function __construct(public Uuid $taskId)
    {
    }
}
