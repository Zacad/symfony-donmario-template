<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Inventory;

use App\Kernel;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Real Kernel/FrameworkBundle registration and YAML loading, with disposable paths. */
final class InventoryKernel extends Kernel
{
    public function __construct(private readonly string $fixtureProjectDir)
    {
        parent::__construct('test', false);
    }

    public function createContainer(): ContainerBuilder
    {
        $this->initializeBundles();

        return $this->buildContainer();
    }

    public function getProjectDir(): string
    {
        return $this->fixtureProjectDir;
    }

    // Ignore runner APP_*_DIR overrides: every fixture-owned file belongs in /tmp.
    public function getCacheDir(): string
    {
        return $this->fixtureProjectDir.'/var/cache';
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    public function getShareDir(): string
    {
        return $this->getCacheDir();
    }

    public function getLogDir(): string
    {
        return $this->fixtureProjectDir.'/var/log';
    }
}
