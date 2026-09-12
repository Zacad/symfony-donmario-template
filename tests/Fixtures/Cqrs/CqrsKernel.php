<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Cqrs;

use App\Kernel;
use App\Module\TaskTracking\Domain\TaskRepository;
use App\Module\TaskTracking\Infrastructure\Testing\FaultTaskRepository;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\QueryBus;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

require_once __DIR__.'/FaultTaskRepository.php';

final class CqrsKernel extends Kernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->register(FaultControl::class)->setAutowired(true)->setAutoconfigured(true);
        $container->register(FaultTaskRepository::class)->setAutowired(true)->setAutoconfigured(true);
        $container->addCompilerPass(new FaultRepositoryPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        foreach (['commands' => CommandBus::class, 'queries' => QueryBus::class, 'fault' => FaultControl::class, 'repository' => TaskRepository::class] as $name => $class) {
            $container->setAlias('test.cqrs.'.$name, $class)->setPublic(true);
        }
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'/cqrs-verification';
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
