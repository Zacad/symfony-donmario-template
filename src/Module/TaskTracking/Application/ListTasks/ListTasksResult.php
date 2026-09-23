<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\ListTasks;

final readonly class ListTasksResult
{
    /** @param list<TaskListItemResult> $tasks */
    public function __construct(public array $tasks, public ?string $next)
    {
    }
}
