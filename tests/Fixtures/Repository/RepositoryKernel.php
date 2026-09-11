<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Repository;

use App\Kernel;
use App\Module\TaskTracking\Domain\TaskRepository;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Verification-only access to the real private binding, using separate compiled caches. */
final class RepositoryKernel extends Kernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('test.task_repository', TaskRepository::class)->setPublic(true);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'/repository-verification';
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    public function getShareDir(): string
    {
        return $this->getCacheDir();
    }
}
