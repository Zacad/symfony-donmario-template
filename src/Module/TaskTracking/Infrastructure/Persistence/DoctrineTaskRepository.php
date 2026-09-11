<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Infrastructure\Persistence;

use App\Module\TaskTracking\Domain\Task;
use App\Module\TaskTracking\Domain\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineTaskRepository implements TaskRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(Task $task): void
    {
        $this->entityManager->persist($task);
    }

    public function find(Uuid $id): ?Task
    {
        return $this->entityManager->find(Task::class, $id);
    }
}
