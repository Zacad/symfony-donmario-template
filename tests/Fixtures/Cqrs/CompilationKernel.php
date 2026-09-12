<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Cqrs;

use App\Kernel;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\QueryBus;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
