<?php

declare(strict_types=1);

namespace App\Platform\Architecture;

use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Checks module service files before framework passes change controller visibility. */
final readonly class ModuleInventoryPass implements CompilerPassInterface
{
    public function __construct(private ModuleMap $modules)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        $resources = array_map(strval(...), $container->getResources());
        $registered = [];
        foreach ($container->getDefinitions() as $definition) {
            if (!$definition->isAbstract() && !$definition->hasTag('container.excluded') && null !== $definition->getClass()) {
                $name = $container->getParameterBag()->resolveValue($definition->getClass());
                if (!is_string($name) || !str_starts_with(strtolower(ltrim($name, '\\')), 'app\\module\\')) {
                    continue;
                }
                // Canonicalize only first-party module classes: optional vendor
                // definitions must not be eagerly autoloaded during inventory.
                $class = $container->getReflectionClass(ltrim($name, '\\'))?->name;
                if (null === $class) {
                    continue;
                }
                if (ContractTypes::isDataCandidate($class)) {
                    throw new \LogicException('module.inventory.data: '.$class.' is message/result/event data and must be excluded from service registration.');
                }
                $registered[$class] = true;
                // Includes explicitly registered Domain services, without making
                // Domain value objects/entities part of automatic service discovery.
                if ($definition->isPublic() || !$definition->isAutowired() || !$definition->isAutoconfigured()) {
                    throw new \LogicException('module.inventory.defaults: '.$class.' requires private, autowired, autoconfigured registration.');
                }
            }
        }
        foreach ($this->modules->modules() as $module) {
            $root = $this->modules->path($module);
            // New/removed source files must invalidate dev's compiled inventory.
            $container->addResource(new DirectoryResource($root, '/\.php$/'));
            foreach (['Application', 'Infrastructure', 'UI'] as $layer) {
                if (!is_dir($root.'/'.$layer)) {
                    continue;
                }
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$layer, \FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    if (!$file instanceof \SplFileInfo || !$file->isFile() || 'php' !== $file->getExtension()) {
                        continue;
                    }
                    $class = 'App\\Module\\'.$module.'\\'.str_replace('/', '\\', substr($file->getPathname(), \strlen($root) + 1, -4));
                    $reflection = $container->getReflectionClass($class);
                    if (null === $reflection || ContractTypes::isPublic($reflection->name) || $reflection->isInterface() || $reflection->isTrait() || $reflection->isAbstract() || $reflection->isEnum()) {
                        continue;
                    }
                    $config = $root.'/Resources/config/services.yaml';
                    if (!in_array((string) new FileResource($config), $resources, true)) {
                        throw new \LogicException('module.inventory.import: '.$module.' service configuration was not imported.');
                    }
                    if (!isset($registered[$class])) {
                        throw new \LogicException('module.inventory.service: '.$class.' was not registered.');
                    }
                }
            }
        }
    }
}
