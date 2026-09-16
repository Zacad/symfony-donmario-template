<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Authorizing;

use App\Kernel;
use App\Module\TaskTracking\Infrastructure\Testing\FaultTaskRepository;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\QueryBus;
use App\Tests\Fixtures\Cqrs\FaultControl;
use App\Tests\Fixtures\Cqrs\FaultRepositoryPass;
use Doctrine\DBAL\Logging\Middleware;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

require_once __DIR__.'/../Cqrs/FaultTaskRepository.php';

/** Real buses, persistence and native DBAL instrumentation in an isolated container cache. */
final class AuthorizingKernel extends Kernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->register(FaultControl::class)->setAutowired(true)->setAutoconfigured(true);
        $container->register(FaultTaskRepository::class)->setAutowired(true)->setAutoconfigured(true);
        $container->addCompilerPass(new FaultRepositoryPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
        $container->register(SqlLog::class);
        $container->register('test.authorizing.sql_middleware', Middleware::class)
            ->setArguments([new Reference(SqlLog::class)])
            ->addTag('doctrine.middleware', ['connection' => 'default']);
        foreach (['commands' => CommandBus::class, 'queries' => QueryBus::class, 'fault' => FaultControl::class, 'sql' => SqlLog::class] as $name => $class) {
            $container->setAlias('test.authorizing.'.$name, $class)->setPublic(true);
        }
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'/authorizing-verification';
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
