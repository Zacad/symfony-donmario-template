<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\GetTask;

use Symfony\Component\Uid\Uuid;

final readonly class GetTaskResult
{
    public function __construct(public Uuid $id, public string $title, public ?Uuid $ownerAccountId = null, public ?\DateTimeImmutable $completedAt = null)
    {
    }
}
