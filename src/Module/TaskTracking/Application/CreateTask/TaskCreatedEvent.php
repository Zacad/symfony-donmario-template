<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\CreateTask;

use App\Platform\Event\ApplicationEvent;
use Symfony\Component\Uid\Uuid;

final readonly class TaskCreatedEvent extends ApplicationEvent
{
    public function __construct(public Uuid $taskId)
    {
    }
}
