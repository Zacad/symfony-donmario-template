<?php

declare(strict_types=1);

namespace App\Platform\Architecture;

use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

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
            $this->assertNoData($container, $definition);
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
                $registered[$class] = true;
                // Includes explicitly registered Domain services, without making
                // Domain value objects/entities part of automatic service discovery.
                $voter = ContractTypes::isAuthorizationVoter($class);
                if ($definition->isPublic() || !$definition->isAutowired() || ($voter ? $definition->isAutoconfigured() : !$definition->isAutoconfigured())) {
                    throw new \LogicException('module.inventory.defaults: '.$class.' requires private, autowired, '.($voter ? 'explicit' : 'autoconfigured').' registration.');
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
                    if (null === $reflection || ContractTypes::isAuthenticationPrincipal($reflection->name) || ContractTypes::isApiResource($reflection->name) || ContractTypes::isPublic($reflection->name) || ContractTypes::isAnyEventData($reflection->name) || $reflection->isSubclassOf('App\\Platform\\Event\\BaseEvent') || $reflection->isInterface() || $reflection->isTrait() || $reflection->isAbstract() || $reflection->isEnum()) {
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

    private function assertNoData(ContainerBuilder $container, mixed $value): void
    {
        if ($value instanceof Definition) {
            if ($value->isAbstract() && $value->hasTag('container.excluded')) {
                return;
            }
            $name = $container->getParameterBag()->resolveValue($value->getClass());
            if (is_string($name) && str_starts_with(strtolower(ltrim($name, '\\')), 'app\\')) {
                $reflection = $container->getReflectionClass(ltrim($name, '\\'), false);
                $class = $reflection->name ?? ltrim($name, '\\');
                if (ContractTypes::isAuthorizationData($class)) {
                    throw new \LogicException('module.inventory.data: '.$class.' is authorization data and must be excluded from service registration.');
                }
                if (ContractTypes::isAuthenticationPrincipal($class)) {
                    throw new \LogicException('module.inventory.data: '.$class.' is authentication principal data and must be excluded from service registration.');
                }
                if (ContractTypes::isApiResource($class)) {
                    throw new \LogicException('module.inventory.data: '.$class.' is API resource data and must be excluded from service registration.');
                }
                if (ContractTypes::isEventRecordingSupport($class)) {
                    throw new \LogicException('module.inventory.support: '.$class.' is Domain event recording support and must be excluded from service registration.');
                }
                if (ContractTypes::isDataCandidate($class) || true === $reflection?->isSubclassOf('App\\Platform\\Event\\BaseEvent')) {
                    throw new \LogicException('module.inventory.data: '.$class.' is message/result/event data and must be excluded from service registration.');
                }
            }
            $this->assertNoData($container, [$value->getArguments(), $value->getProperties(), $value->getMethodCalls(), $value->getFactory(), $value->getConfigurator()]);
        } elseif ($value instanceof ArgumentInterface) {
            $this->assertNoData($container, $value->getValues());
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $this->assertNoData($container, $item);
            }
        }
    }
}
