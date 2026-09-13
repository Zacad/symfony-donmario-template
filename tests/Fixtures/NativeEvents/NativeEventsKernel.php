<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\NativeEvents;

use App\Kernel;

final class NativeEventsKernel extends Kernel
{
    public function __construct(private readonly string $fixtureRoot)
    {
        parent::__construct('test', false);
    }

    public function getProjectDir(): string
    {
        return $this->fixtureRoot;
    }

    public function getCacheDir(): string
    {
        return $this->fixtureRoot.'/var/cache/test';
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
        return $this->fixtureRoot.'/var/log';
    }
}
