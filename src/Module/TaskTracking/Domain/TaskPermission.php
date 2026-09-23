<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Domain;

enum TaskPermission: string
{
    case Create = 'task_tracking.task.create';
    case View = 'task_tracking.task.view';
    case Complete = 'task_tracking.task.complete';
}
