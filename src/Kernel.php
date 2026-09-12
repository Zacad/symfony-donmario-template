<?php

namespace App;

use App\Platform\Architecture\CqrsPass;
use App\Platform\Architecture\ModuleInventoryPass;
use App\Platform\Architecture\ModuleMap;
use App\Platform\Architecture\ModuleServicesPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        // After attribute defaults (100), before FrameworkBundle exposes controllers (0).
        $container->addCompilerPass(new ModuleInventoryPass(new ModuleMap($this->getProjectDir())), PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
        $container->addCompilerPass(new CqrsPass(new ModuleMap($this->getProjectDir())), PassConfig::TYPE_BEFORE_REMOVING, 10);
        $container->addCompilerPass(new ModuleServicesPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }
}
