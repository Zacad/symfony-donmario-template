<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\TaskTracking\Domain\TaskRepository;
use App\Tests\Fixtures\Repository\RepositoryKernel;

abstract class RepositoryTestCase extends DatabaseTestCase
{
    protected static function getKernelClass(): string
    {
        return RepositoryKernel::class;
    }

    protected function repository(): TaskRepository
    {
        $repository = self::getContainer()->get('test.task_repository');
        self::assertInstanceOf(TaskRepository::class, $repository);

        return $repository;
    }
}
