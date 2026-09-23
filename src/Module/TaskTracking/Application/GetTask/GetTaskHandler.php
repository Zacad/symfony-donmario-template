<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\GetTask;

use App\Module\TaskTracking\Domain\TaskPermission;
use App\Module\TaskTracking\Domain\TaskRepository;
use App\Module\TaskTracking\Infrastructure\Framework\Symfony\Security\TaskTrackingVoter;
use App\Platform\Authorization\Authorize;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'query.bus')]
#[Authorize(voter: TaskTrackingVoter::class, permission: TaskPermission::View, label: 'View tasks')]
final readonly class GetTaskHandler
{
    public function __construct(private TaskRepository $tasks)
    {
    }

    public function __invoke(GetTaskQuery $query): ?GetTaskResult
    {
        $task = $this->tasks->find(Uuid::fromString($query->id));

        return null === $task ? null : new GetTaskResult($task->id(), $task->title(), $task->ownerAccountId(), $task->completedAt());
    }
}
