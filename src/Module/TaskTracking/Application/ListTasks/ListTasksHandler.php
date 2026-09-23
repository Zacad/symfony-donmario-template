<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\ListTasks;

use App\Module\TaskTracking\Domain\InvalidTaskInput;
use App\Module\TaskTracking\Domain\Task;
use App\Module\TaskTracking\Domain\TaskListCursor;
use App\Module\TaskTracking\Domain\TaskPermission;
use App\Module\TaskTracking\Domain\TaskRepository;
use App\Module\TaskTracking\Infrastructure\Framework\Symfony\Security\TaskTrackingVoter;
use App\Platform\Authorization\Authorize;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
#[Authorize(voter: TaskTrackingVoter::class, permission: TaskPermission::View, label: 'View tasks')]
final readonly class ListTasksHandler
{
    public function __construct(private TaskRepository $tasks)
    {
    }

    public function __invoke(ListTasksQuery $query): ListTasksResult
    {
        if ($query->limit < 1 || $query->limit > 100) {
            throw new InvalidTaskInput('Invalid task page size.');
        }
        $after = TaskListCursor::decode($query->after, $query->ownerAccountId);
        $tasks = $this->tasks->findPage($query->limit, $after, $query->ownerAccountId);
        $hasMore = count($tasks) > $query->limit;
        $tasks = array_slice($tasks, 0, $query->limit);
        $nextId = $hasMore && [] !== $tasks ? $tasks[array_key_last($tasks)]->id() : null;

        return new ListTasksResult(
            array_map(static fn (Task $task): TaskListItemResult => new TaskListItemResult($task->id(), $task->title(), $task->ownerAccountId(), $task->completedAt()), $tasks),
            null === $nextId ? null : TaskListCursor::encode($query->ownerAccountId, $nextId),
        );
    }
}
