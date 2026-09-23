<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\CreateTask;

use Symfony\Component\Uid\Uuid;

final readonly class CreateTaskCommand
{
    public function __construct(public string $title, public ?Uuid $ownerAccountId = null)
    {
    }
}
