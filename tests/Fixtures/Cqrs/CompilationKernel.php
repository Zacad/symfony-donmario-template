<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Cqrs;

use App\Kernel;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\CommandTransactionMiddleware;
use App\Platform\Messaging\InvocationContext;
use App\Platform\Messaging\QueryBus;
use App\Platform\Messaging\ResultValidationMiddleware;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;

final class CompilationKernel extends Kernel
{
    private readonly string $directory;

    public function __construct(private readonly string $scenario, string $environment)
    {
        parent::__construct($environment, false);
        $this->directory = sys_get_temp_dir().'/cqrs-compilation-'.bin2hex(random_bytes(12));
    }

    /** @param callable(ContainerBuilder): void $test */
    public static function run(string $scenario, callable $test): void
    {
        $kernel = new self($scenario, 'test');
        try {
            $kernel->initializeBundles();
            $test($kernel->buildContainer());
        } finally {
            $kernel->shutdown();
            new Filesystem()->remove($kernel->directory);
        }
    }

    public function cleanup(): void
    {
        $this->shutdown();
        new Filesystem()->remove($this->directory);
    }

    /** @param callable(ContainerInterface): void $test */
    public static function withBooted(string $scenario, callable $test): void
    {
        $kernel = new self($scenario, 'test');
        try {
            $kernel->boot();
            $test($kernel->getContainer());
        } finally {
            $kernel->cleanup();
        }
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $type = in_array($this->scenario, ['locator-constructor', 'descriptor-constructor'], true) ? PassConfig::TYPE_BEFORE_REMOVING : PassConfig::TYPE_BEFORE_OPTIMIZATION;
        $container->addCompilerPass(new AlterCqrsPass($this->scenario), $type, 20);
        $container->addCompilerPass(new class($this->scenario) implements CompilerPassInterface {
            public function __construct(private readonly string $scenario)
            {
            }

            public function process(ContainerBuilder $container): void
            {
                switch ($this->scenario) {
                    case 'result-validator':
                        $container->register('test.other.validator', \Symfony\Component\Validator\Validator\RecursiveValidator::class);
                        $container->getDefinition(ResultValidationMiddleware::class)->setArgument(0, new Reference('test.other.validator'));
                        break;
                    case 'transaction-context':
                        $container->register('test.other.context', InvocationContext::class);
                        $container->getDefinition(CommandTransactionMiddleware::class)->setArgument(1, new Reference('test.other.context'));
                        break;
                    case 'manager-connection':
                        $container->register('test.other.connection', \Doctrine\DBAL\Connection::class);
                        $container->getDefinition('doctrine.orm.default_entity_manager')->setArgument(0, new Reference('test.other.connection'));
                        break;
                    case 'default-connection':
                        $container->getDefinition('doctrine')->setArgument(3, 'other');
                        break;
                    case 'connection-autocommit':
                        $container->getDefinition('doctrine.dbal.default_connection.configuration')->addMethodCall('setAutoCommit', [false]);
                        break;
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING, 20);
        $container->setAlias('test.cqrs.validator', 'validator')->setPublic(true);
        $container->setAlias('test.cqrs.commands', CommandBus::class)->setPublic(true);
        $container->setAlias('test.cqrs.queries', QueryBus::class)->setPublic(true);
    }

    public function getCacheDir(): string
    {
        return $this->directory;
    }

    public function getBuildDir(): string
    {
        return $this->directory;
    }

    public function getShareDir(): string
    {
        return $this->directory;
    }
}
