<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\CreateTask;

use App\Module\TaskTracking\Domain\Event\TaskCreatedEvent as DomainTaskCreatedEvent;
use App\Module\TaskTracking\Domain\Task;
use App\Module\TaskTracking\Domain\TaskRepository;
use App\Platform\Messaging\ApplicationEventRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateTaskHandler
{
    public function __construct(private TaskRepository $tasks, private ApplicationEventRecorder $events)
    {
    }

    public function __invoke(CreateTaskCommand $command): Uuid
    {
        $task = new Task($command->title);
        $this->tasks->add($task);
        foreach ($task->releaseEvents() as $event) {
            if ($event instanceof DomainTaskCreatedEvent) {
                $this->events->record(new TaskCreatedEvent($event->taskId));
            }
        }

        return $task->id();
    }
}
