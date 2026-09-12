<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\CreateTask;

final readonly class CreateTaskCommand
{
    public function __construct(public string $title)
    {
    }
}
