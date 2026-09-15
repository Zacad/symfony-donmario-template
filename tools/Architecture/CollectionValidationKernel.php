<?php

declare(strict_types=1);

namespace App\Tools\Architecture;

use App\Kernel;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Development audit kernel: normal application configuration, isolated cache. */
final class CollectionValidationKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'/collection-validation';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('collection_validation.validator', 'validator')->setPublic(true);
    }
}
