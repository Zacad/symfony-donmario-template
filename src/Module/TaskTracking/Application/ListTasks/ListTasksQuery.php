<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\ListTasks;

use Symfony\Component\Uid\Uuid;

final readonly class ListTasksQuery
{
    public function __construct(public ?Uuid $ownerAccountId = null, public int $limit = 50, public ?string $after = null)
    {
    }
}
