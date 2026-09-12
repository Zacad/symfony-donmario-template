<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Infrastructure\Testing;

use App\Module\TaskTracking\Domain\Task;
use App\Module\TaskTracking\Domain\TaskRepository;
use App\Module\TaskTracking\Infrastructure\Persistence\DoctrineTaskRepository;
use App\Tests\Fixtures\Cqrs\FaultControl;
use Symfony\Component\Uid\Uuid;

/** Faults surround the actual Doctrine adapter, through the actual Domain port. */
final readonly class FaultTaskRepository implements TaskRepository
{
    public function __construct(private DoctrineTaskRepository $inner, private FaultControl $fault)
    {
    }

    public function add(Task $task): void
    {
        $this->inner->add($task);
        $this->fault->afterAdd?->__invoke($task);
    }

    public function find(Uuid $id): ?Task
    {
        $task = $this->inner->find($id);
        $this->fault->afterFind?->__invoke($task);

        return $task;
    }
}
