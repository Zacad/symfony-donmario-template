<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Domain;

use Symfony\Component\Uid\Uuid;

/** Module-internal persistence port; transaction coordination belongs to the caller. */
interface TaskRepository
{
    public function add(Task $task): void;

    public function find(Uuid $id): ?Task;
}
