<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\CompleteTask;

use App\Module\TaskTracking\Domain\TaskPermission;
use App\Module\TaskTracking\Domain\TaskRepository;
use App\Module\TaskTracking\Infrastructure\Framework\Symfony\Security\TaskTrackingVoter;
use App\Platform\Authorization\Authorize;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
#[Authorize(voter: TaskTrackingVoter::class, permission: TaskPermission::Complete, label: 'Complete tasks')]
final readonly class CompleteTaskHandler
{
    public function __construct(private TaskRepository $tasks)
    {
    }

    public function __invoke(CompleteTaskCommand $command): ?CompleteTaskResult
    {
        $task = $this->tasks->findForCompletion(Uuid::fromString($command->id));
        if (null === $task) {
            return null;
        }

        // Sample only after the lock has been acquired; Domain normalizes to UTC whole seconds.
        $changed = $task->complete(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $completedAt = $task->completedAt() ?? throw new \LogicException('Unexpected task completion state.');

        return new CompleteTaskResult($task->id(), $changed, $completedAt);
    }
}
