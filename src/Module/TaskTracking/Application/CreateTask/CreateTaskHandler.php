<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\CreateTask;

use App\Module\TaskTracking\Domain\Event\TaskCreatedEvent as DomainTaskCreatedEvent;
use App\Module\TaskTracking\Domain\Task;
use App\Module\TaskTracking\Domain\TaskRepository;
use App\Platform\Authorization\AuthorizeWith;
use App\Platform\Messaging\EventBus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
#[AuthorizeWith(CreateTaskPolicy::class)]
final readonly class CreateTaskHandler
{
    public function __construct(private TaskRepository $tasks, private EventBus $events)
    {
    }

    public function __invoke(CreateTaskCommand $command): Uuid
    {
        $task = new Task($command->title);
        $this->tasks->add($task);
        foreach ($task->releaseEvents() as $event) {
            if ($event instanceof DomainTaskCreatedEvent) {
                $this->events->dispatch(new TaskCreatedEvent($event->taskId));
            }
        }

        return $task->id();
    }
}
