<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\CompleteTask;

final readonly class CompleteTaskCommand
{
    public function __construct(public string $id)
    {
    }
}
