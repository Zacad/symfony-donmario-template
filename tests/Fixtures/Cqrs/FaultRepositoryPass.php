<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Cqrs;

use App\Module\TaskTracking\Domain\TaskRepository;
use App\Module\TaskTracking\Infrastructure\Testing\FaultTaskRepository;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class FaultRepositoryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Apply after module YAML loads, before autowiring/ownership validation.
        $container->setAlias(TaskRepository::class, FaultTaskRepository::class);
    }
}
