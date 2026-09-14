<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Authenticating;

use App\Kernel;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\SymfonyPasswordHasher;
use App\Platform\Messaging\CommandBus;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** CLI-only access to the real command bus; the web app uses its ordinary kernel. */
final class AuthenticatingKernel extends Kernel
{
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('test.authenticating.commands', CommandBus::class)->setPublic(true);
        // Retain the real adapter for TestContainer replacement before the handler is loaded.
        $container->setAlias('test.authenticating.password_hasher', SymfonyPasswordHasher::class)->setPublic(true);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'/authenticating-verification';
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
