<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\CompleteTask;

use Symfony\Component\Uid\Uuid;

final readonly class CompleteTaskResult
{
    public function __construct(public Uuid $id, public bool $changed, public \DateTimeImmutable $completedAt)
    {
    }
}
