<?php

declare(strict_types=1);

namespace App\Platform\Architecture;

use App\Platform\Messaging\ApplicationEventRecorder;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\EventListenerInvoker;
use App\Platform\Messaging\QueryBus;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\Bundle\DoctrineBundle\Repository\ContainerRepositoryFactory;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;

/**
 * Register at TYPE_BEFORE_REMOVING, after wiring resolution and before removal/inlining.
 *
 * Checks declared DI edges, including inline definitions and module-consumed Symfony
 * locators. Ordinary referenced services are not traversed transitively: in particular,
 * a shared EntityManager/registry does not make every repository a module dependency.
 * This is not analysis of factory bodies, runtime lookups, SQL or service inventory.
 * Registration defaults/inventory must be checked before framework passes alter them.
 */
final class ModuleServicesPass implements CompilerPassInterface
{
    private const string PLATFORM = 'Platform';

    private ContainerBuilder $container;
    private string $injectionClass = '';

    /** @var array<string, array<int, true>> */
    private array $visited = [];

    /** @var array<int, true> */
    private array $repositoryLocators = [];

    public function process(ContainerBuilder $container): void
    {
        $this->container = $container;
        $this->injectionClass = '';
        $this->visited = $this->repositoryLocators = [];

        try {
            // Doctrine's aggregate repository locator is framework wiring, never a
            // module-facing provider, even when it currently contains only one module.
            foreach ($container->getDefinitions() as $definition) {
                if (ContainerRepositoryFactory::class !== $this->className($definition)) {
                    continue;
                }
                $locator = $definition->getArguments()[0] ?? null;
                if ($locator instanceof Reference && $container->has((string) $locator)) {
                    $locator = $container->findDefinition((string) $locator);
                }
                if ($locator instanceof Definition) {
                    $this->repositoryLocators[spl_object_id($locator)] = true;
                }
            }

            foreach ($container->getDefinitions() as $id => $definition) {
                $this->definition($definition, $id, null, $id);
            }
        } finally {
            unset($this->container);
            $this->injectionClass = '';
            $this->visited = $this->repositoryLocators = [];
        }
    }

    private function definition(Definition $definition, string $id, ?string $module, string $source): void
    {
        // Symfony keeps excluded classes as abstract diagnostic placeholders.
        if ($definition->isAbstract() && $definition->hasTag('container.excluded')) {
            return;
        }

        $class = $this->className($definition);
        $this->assertServiceClass($class, $id);
        if ($definition->isAbstract()) {
            return;
        }
        if (null === $module && EventListenerInvoker::class === $class) {
            if (!$this->isDeferredEventListener($definition, $id, $source)) {
                throw new \LogicException('module.services.event_invoker: '.$id.' must be the exact inline, application-event descriptor wrapper.');
            }

            return;
        }
        if (ContractTypes::isOwnEventListener($class)) {
            if ($definition->isPublic()) {
                throw new \LogicException('module.services.listener_private: '.$id.' must be private.');
            }
            foreach ($this->container->getAliases() as $aliasId => $alias) {
                if ($alias->isPublic() && $this->resolveId($aliasId) === $id) {
                    throw new \LogicException('module.services.listener_private: '.$aliasId.' exposes '.$id.'.');
                }
            }
        }
        $owner = ModuleMap::owner($class);
        if (null !== $module) {
            $this->dependency($definition, $id, $module, $source);
        } elseif (null !== $owner) {
            $module = $owner;
            $source = $id;
        } elseif (str_starts_with($class, 'App\\Platform\\')) {
            $module = self::PLATFORM;
            $source = $id;
        }

        // Module-owned inline definitions have their own dependency layer.
        // Transparent vendor wiring/locators retain their consumer's layer.
        $site = null !== $owner || str_starts_with($class, 'App\\Platform\\') ? $class : (null !== $module ? $this->injectionClass : '');
        $key = (null === $module ? '' : $module.':'.$source).':'.$site;
        $objectId = spl_object_id($definition);
        if (isset($this->visited[$key][$objectId])) {
            return;
        }
        $this->visited[$key][$objectId] = true;

        $previousSite = $this->injectionClass;
        $this->injectionClass = $site;
        try {
            $this->inspectDefinition($definition, $id, $module, $source, $class);
        } finally {
            $this->injectionClass = $previousSite;
        }
    }

    private function inspectDefinition(Definition $definition, string $id, ?string $module, string $source, string $class): void
    {
        $arguments = $definition->getArguments();
        if (null !== $module) {
            if ($definition->isSynthetic() || null !== $definition->getFile()) {
                throw new \LogicException(sprintf('module.services.dynamic: "%s" (%s) uses synthetic or file-loaded wiring; declare concrete service definitions.', $source, $module));
            }

            if (ServiceLocator::class === $class && null !== $definition->getFactory()) {
                if (!$this->isContextLocator($definition, $id, $source)) {
                    throw new \LogicException(sprintf('module.services.locator: "%s" (%s) -> "%s"; only statically declared Symfony locator maps and their generated consumer context are supported.', $source, $module, $id));
                }
                // The exact ServiceLocator::withContext() diagnostic-container edge.
                // Its prototype/factory reference is still inspected below.
                unset($arguments[1]);
            }

            $this->callable($definition->getFactory(), $definition, $module, $source, 'factory');
            $this->callable($definition->getConfigurator(), $definition, $module, $source, 'configurator');
        }

        $reflection = $this->container->getReflectionClass($class, false);
        $this->arguments($arguments, null === $definition->getFactory() ? $reflection?->getConstructor() : null, $module, $source);
        foreach ($definition->getProperties() as $property => $value) {
            $type = $reflection?->hasProperty($property) ? $reflection->getProperty($property)->getType() : null;
            $this->value($value, $module, $source, $this->domainPort($type, $module));
        }
        /** @var list<array{0: string, 1: array<int|string, mixed>, 2?: bool}> $calls */
        $calls = $definition->getMethodCalls();
        foreach ($calls as [$method, $values]) {
            $signature = $reflection?->hasMethod($method) ? $reflection->getMethod($method) : null;
            $this->arguments($values, $signature, $module, $source);
        }
        $this->value($definition->getFactory(), $module, $source);
        $this->value($definition->getConfigurator(), $module, $source);
    }

    private function value(mixed $value, ?string $module, string $source, ?string $port = null): void
    {
        if (\is_array($value)) {
            foreach ($value as $item) {
                $this->value($item, $module, $source);
            }
        } elseif ($value instanceof ArgumentInterface) {
            $this->value($value->getValues(), $module, $source);
        } elseif ($value instanceof Definition) {
            $this->definition($value, $source.' (inline '.$this->className($value).')', $module, $source);
        } elseif (null !== $module && $value instanceof Reference) {
            $id = $this->resolveId((string) $value);
            if ('service_container' === $id) {
                $this->rejectContainer($id, $module, $source);
            }
            $usedEnvs = [];
            $this->container->resolveEnvPlaceholders($id, null, $usedEnvs);
            if ($usedEnvs || str_contains($id, '%')) {
                throw new \LogicException(sprintf('module.services.dynamic: "%s" (%s) uses a computed service identifier; declare a literal Reference.', $source, $module));
            }
            if (!$this->container->hasDefinition($id)) {
                // Optional missing references have no service edge. Symfony validates
                // required missing references in its subsequent removal passes.
                return;
            }
            $target = $this->container->getDefinition($id);
            $this->dependency($target, $id, $module, $source, $port);
            if (ServiceLocator::class === $this->className($target)) {
                $this->definition($target, $id, $module, $source);
            }
        } elseif (null !== $module && ((\is_string($value) && str_starts_with($value, '@=')) || (\is_object($value) && is_a($value, 'Symfony\Component\ExpressionLanguage\Expression')))) {
            throw new \LogicException(sprintf('module.services.expression: "%s" (%s) uses unsupported Expression wiring; declare explicit References instead.', $source, $module));
        } elseif (null !== $module && $value instanceof \Closure) {
            throw new \LogicException(sprintf('module.services.dynamic: "%s" (%s) uses a runtime closure; use ServiceClosureArgument with explicit References.', $source, $module));
        }
    }

    private function dependency(Definition $target, string $id, string $module, string $source, ?string $port = null): void
    {
        $class = $this->className($target);
        if ('' === $class) {
            throw new \LogicException(sprintf('module.services.dynamic: "%s" (%s) -> "%s" has no declared class; service ownership cannot be checked.', $source, $module, $id));
        }
        $this->assertServiceClass($class, $id);
        $owner = ModuleMap::owner($class);
        if (null !== $owner && $owner !== $module) {
            throw new \LogicException(sprintf('module.services.foreign: "%s" (%s) -> "%s" [%s] (%s); cross-module service dependencies are forbidden.', $source, $module, $id, $class, $owner));
        }
        if (self::PLATFORM !== $module && str_starts_with($class, 'App\\Platform\\')) {
            $application = 'Application' === ModuleMap::layer($this->injectionClass) && !ContractTypes::isDataCandidate($this->injectionClass);
            $bus = in_array($class, [CommandBus::class, QueryBus::class], true)
                && ($application || 'UI' === ModuleMap::layer($this->injectionClass) || ContractTypes::isOwnEventListener($this->injectionClass));
            $recorder = ApplicationEventRecorder::class === $class && $application;
            if ($id !== $class || (!$bus && !$recorder)) {
                throw new \LogicException(sprintf('module.services.platform: "%s" (%s) -> "%s" [%s]; Platform is not a module-facing facade.', $source, $module, $id, $class));
            }
        }
        if (self::PLATFORM !== $module && str_starts_with($class, 'Symfony\\Component\\Messenger\\')) {
            throw new \LogicException(sprintf('module.services.messenger: "%s" (%s) -> "%s"; use the approved command/query facade instead of raw Messenger services.', $source, $module, $id));
        }
        if (ContractTypes::isOwnEventListener($this->injectionClass)
            && !(in_array($class, [CommandBus::class, QueryBus::class], true) && $id === $class)
            && !ContractTypes::isImmutable($class)) {
            throw new \LogicException(sprintf('module.services.listener_dependency: "%s" -> "%s" [%s]; own event listeners may inject only exact command/query helpers or immutable values.', $source, $id, $class));
        }
        if ($target->isSynthetic() || null !== $target->getFile()) {
            throw new \LogicException(sprintf('module.services.dynamic: "%s" (%s) uses synthetic or file-loaded wiring; declare concrete service definitions.', $source, $module));
        }

        if (is_a($class, RepositoryFactory::class, true) || isset($this->repositoryLocators[spl_object_id($target)])) {
            throw new \LogicException(sprintf('module.services.repository_provider: "%s" (%s) -> "%s"; Doctrine repository factories and their aggregate locators are not module-facing services.', $source, $module, $id));
        }
        if ($target->hasTag('container.service_locator') && ServiceLocator::class !== $class) {
            throw new \LogicException(sprintf('module.services.locator: "%s" (%s) -> "%s"; only statically declared Symfony locator maps and their generated consumer context are supported.', $source, $module, $id));
        }
        if (ServiceLocator::class === $class) {
            return;
        }
        // ContainerBag implements PSR ContainerInterface but resolves parameters,
        // never services. AbstractController's bounded locator includes this service.
        if ('parameter_bag' === $id && ContainerBag::class === $class) {
            return;
        }
        if (is_a($class, ContainerInterface::class, true)) {
            $this->rejectContainer($id, $module, $source);
        }
        if ((is_a($class, ObjectManager::class, true) || is_a($class, ManagerRegistry::class, true)) && !$this->isStandardDoctrineProvider($id, $class)) {
            throw new \LogicException(sprintf('module.services.doctrine_provider: "%s" (%s) -> "%s"; only the standard "doctrine" registry and "doctrine.orm.default_entity_manager" are supported.', $source, $module, $id));
        }
        $this->assertLayerDependency($class, $id, $module, $source, $port);
    }

    /** @param array<int|string, mixed> $arguments */
    private function arguments(array $arguments, ?\ReflectionFunctionAbstract $signature, ?string $module, string $source): void
    {
        $parameters = [];
        foreach ($signature?->getParameters() ?? [] as $index => $parameter) {
            $parameters[$index] = $parameters[$parameter->getName()] = $parameter->getType();
        }
        foreach ($arguments as $index => $value) {
            $type = $parameters[is_int($index) ? $index : ltrim($index, '$')] ?? null;
            $this->value($value, $module, $source, $this->domainPort($type, $module));
        }
    }

    private function domainPort(?\ReflectionType $type, ?string $module): ?string
    {
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }
        $class = $type->getName();
        $reflection = $this->container->getReflectionClass($class, false);

        return $reflection?->isInterface() && $module === ModuleMap::owner($reflection->name) && 'Domain' === ModuleMap::layer($reflection->name) ? $reflection->name : null;
    }

    private function assertLayerDependency(string $class, string $id, string $module, string $source, ?string $port): void
    {
        $layer = ModuleMap::layer($this->injectionClass);
        if (!in_array($layer, ['Domain', 'Application'], true)) {
            return;
        }
        $targetLayer = ModuleMap::layer($class);
        $outward = $module === ModuleMap::owner($class) && (in_array($targetLayer, ['Infrastructure', 'UI', 'Resources'], true) || ('Domain' === $layer && 'Application' === $targetLayer));
        $persistence = str_starts_with($class, 'Doctrine\\') || str_starts_with($class, 'Symfony\\Bridge\\Doctrine\\') || is_a($class, \PDO::class, true) || is_a($class, \PDOStatement::class, true);
        if (!$outward && !$persistence) {
            return;
        }
        // Resolve ownership on the implementation, but preserve the caller's
        // declared port: alias resolution deliberately erases Reference type IDs.
        if ('Infrastructure' === $targetLayer && null !== $port && is_a($class, $port, true)) {
            return;
        }

        throw new \LogicException(sprintf('module.services.layer: "%s" (%s/%s) -> "%s" [%s]; inject a module-owned Domain interface instead of an outward implementation or persistence service.', $source, $module, $layer, $id, $class));
    }

    private function assertServiceClass(string $class, string $id): void
    {
        if (ContractTypes::isEventRecordingSupport($class)) {
            throw new \LogicException(sprintf('module.services.support: "%s" [%s] is Domain event recording support, not a service.', $id, $class));
        }
        $reflection = $this->container->getReflectionClass($class, false);
        $kind = match (true) {
            ContractTypes::isDataCandidate($class),
            true === $reflection?->isSubclassOf('App\\Platform\\Event\\BaseEvent') => 'contract',
            [] !== ($reflection?->getAttributes(Entity::class) ?? []) => 'entity',
            true === $reflection?->isSubclassOf(AbstractMigration::class),
            1 === preg_match('/^App\\\\Module\\\\[^\\\\]+\\\\Resources\\\\[Mm]igrations\\\\/D', $class) => 'migration',
            default => null,
        };
        if (null !== $kind) {
            throw new \LogicException(sprintf('module.services.data: "%s" [%s] is %s data, not a service.', $id, $class, $kind));
        }
    }

    private function callable(mixed $callable, Definition $definition, string $module, string $source, string $kind): void
    {
        if (null === $callable) {
            return;
        }
        // Also recognizes expression factories without requiring ExpressionLanguage
        // as a runtime dependency of this check.
        if (\is_string($callable) && str_starts_with($callable, '@=')) {
            $this->value($callable, $module, $source);
        }
        if (!\is_array($callable) || [0, 1] !== array_keys($callable) || !\is_string($callable[1]) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $callable[1])) {
            throw new \LogicException(sprintf('module.services.callable: "%s" (%s) uses an unsupported %s; declare a literal class/service and method.', $source, $module, $kind));
        }

        [$provider, $method] = $callable;
        if (\is_string($provider)) {
            $reflection = $this->container->getReflectionClass($provider, false);
            if (null === $reflection) {
                throw new \LogicException(sprintf('module.services.callable: "%s" (%s) uses an unsupported %s; declare a literal class/service and method.', $source, $module, $kind));
            }
            $this->dependency(new Definition($reflection->name), $reflection->name, $module, $source);

            return;
        }
        if ($provider instanceof Reference) {
            $this->value($provider, $module, $source);
            $id = $this->resolveId((string) $provider);
            $target = $this->container->hasDefinition($id) ? $this->container->getDefinition($id) : null;
        } elseif ($provider instanceof Definition) {
            $id = $source.' (inline '.$this->className($provider).')';
            $target = $provider;
            $this->definition($provider, $id, $module, $source);
        } else {
            throw new \LogicException(sprintf('module.services.callable: "%s" (%s) uses an unsupported %s; declare a literal class/service and method.', $source, $module, $kind));
        }

        if (null === $target) {
            return;
        }
        $class = $this->className($target);
        if (ServiceLocator::class === $class && !(ServiceLocator::class === $this->className($definition) && 'factory' === $kind && 'withContext' === $method)) {
            throw new \LogicException(sprintf('module.services.lookup_factory: "%s" (%s) uses a service locator as a %s; runtime service identifiers are not supported.', $source, $module, $kind));
        }
        if ($this->isStandardDoctrineProvider($id, $class)) {
            $entity = $definition->getArguments()[0] ?? null;
            $entityClass = \is_string($entity) ? $this->container->getReflectionClass($entity, false) : null;
            if ('factory' !== $kind || 'getRepository' !== $method || null === $entityClass || ModuleMap::owner($entityClass->name) !== $module || [] === $entityClass->getAttributes(Entity::class)) {
                throw new \LogicException(sprintf('module.services.repository_factory: "%s" (%s) must use getRepository() with a literal entity class owned by that module.', $source, $module));
            }
        }
    }

    private function isContextLocator(Definition $definition, string $id, string $source): bool
    {
        $factory = $definition->getFactory();
        $arguments = $definition->getArguments();
        if (!\is_array($factory) || [0, 1] !== array_keys($factory) || !$factory[0] instanceof Reference || 'withContext' !== $factory[1]
            || [0, 1] !== array_keys($arguments) || $source !== $arguments[0] || !$arguments[1] instanceof Reference || 'service_container' !== (string) $arguments[1]
            || [['id' => $source]] !== $definition->getTag('container.service_locator_context')
            || [] !== $definition->getProperties() || [] !== $definition->getMethodCalls() || null !== $definition->getConfigurator()
            || !str_starts_with($id, '.service_locator.') || !str_ends_with($id, '.'.$source)) {
            return false;
        }

        $prototypeId = substr($id, 0, -\strlen('.'.$source));
        if ($this->resolveId($prototypeId) !== $this->resolveId((string) $factory[0]) || !$this->container->has($prototypeId)) {
            return false;
        }
        $prototype = $this->container->findDefinition($prototypeId);

        return ServiceLocator::class === $this->className($prototype) && null === $prototype->getFactory() && $prototype->hasTag('container.service_locator');
    }

    private function isDeferredEventListener(Definition $invoker, string $id, string $source): bool
    {
        // This is an exact compiler-generated edge, not a transparent Platform
        // wrapper permission. Module consumers still take the ordinary checks.
        if ($id !== $source.' (inline '.EventListenerInvoker::class.')' || !$this->container->hasDefinition($source)
            || $invoker->isPublic() || !$invoker->isShared() || $invoker->isSynthetic()
            || null !== $invoker->getFile() || null !== $invoker->getFactory() || null !== $invoker->getConfigurator()
            || [] !== $invoker->getMethodCalls() || [] !== $invoker->getProperties() || [] !== $invoker->getTags()
            || [0] !== array_keys($invoker->getArguments()) || in_array($invoker, $this->container->getDefinitions(), true)) {
            return false;
        }
        $closure = $invoker->getArgument(0);
        if (!$closure instanceof ServiceClosureArgument || [0] !== array_keys($closure->getValues())) {
            return false;
        }
        $reference = $closure->getValues()[0];
        if (!$reference instanceof Reference || !$this->container->has((string) $reference)) {
            return false;
        }
        $target = $this->container->findDefinition((string) $reference);
        $class = $this->className($target);
        if (!ContractTypes::isOwnEventListener($class) || $target->isAbstract() || $target->isPublic()
            || $target->isSynthetic() || null !== $target->getFactory() || null !== $target->getConfigurator() || null !== $target->getFile()) {
            return false;
        }
        $instances = 0;
        foreach ($this->container->getDefinitions() as $candidate) {
            if (!$candidate->isAbstract() && !$candidate->hasTag('container.excluded')
                && 0 === strcasecmp(ltrim($candidate->getClass() ?? '', '\\'), $class)) {
                ++$instances;
            }
        }
        if (1 !== $instances) {
            return false;
        }
        $locatorId = 'application.event.bus.messenger.handlers_locator';
        if (!$this->container->has($locatorId)) {
            return false;
        }
        $locator = $this->container->findDefinition($locatorId);
        $mapping = $locator->getArguments()[0] ?? null;
        if (HandlersLocator::class !== $locator->getClass() || !is_array($mapping)) {
            return false;
        }
        foreach ($mapping as $event => $handlers) {
            if (!is_string($event) || !ContractTypes::isEventData($event) || !$handlers instanceof IteratorArgument) {
                continue;
            }
            foreach ($handlers->getValues() as $descriptorReference) {
                if (!$descriptorReference instanceof Reference || !$this->container->has((string) $descriptorReference)) {
                    continue;
                }
                $descriptor = $this->container->findDefinition((string) $descriptorReference);
                $arguments = $descriptor->getArguments();
                $options = $arguments[1] ?? null;
                if ($descriptor !== $this->container->getDefinition($source)
                    || HandlerDescriptor::class !== $descriptor->getClass() || ($arguments[0] ?? null) !== $invoker
                    || [0, 1] !== array_keys($arguments) || $descriptor->isPublic() || !$descriptor->isShared() || $descriptor->isSynthetic()
                    || null !== $descriptor->getFactory() || null !== $descriptor->getConfigurator() || null !== $descriptor->getFile()
                    || [] !== $descriptor->getMethodCalls() || [] !== $descriptor->getProperties()
                    || !is_array($options) || 'application.event.bus' !== ($options['bus'] ?? null)
                    || $class.'::__invoke' !== ($options['alias'] ?? null)
                    || [] !== array_diff(array_keys($options), ['bus', 'priority', 'method', 'alias'])
                    || '__invoke' !== ($options['method'] ?? '__invoke')) {
                    continue;
                }
                $reflection = $this->container->getReflectionClass($class);
                if (null === $reflection || !$reflection->hasMethod('__invoke')) {
                    return false;
                }
                $method = $reflection->getMethod('__invoke');
                $parameters = $method->getParameters();
                $type = ($parameters[0] ?? null)?->getType();
                $return = $method->getReturnType();

                return $method->isPublic() && !$method->isStatic() && 1 === count($parameters)
                    && $type instanceof \ReflectionNamedType && $type->getName() === $event && !$type->allowsNull()
                    && !$parameters[0]->isVariadic() && !$parameters[0]->isPassedByReference()
                    && $return instanceof \ReflectionNamedType && 'void' === $return->getName();
            }
        }

        return false;
    }

    private function isStandardDoctrineProvider(string $id, string $class): bool
    {
        return ('doctrine' === $id && Registry::class === $class) || ('doctrine.orm.default_entity_manager' === $id && EntityManager::class === $class);
    }

    private function rejectContainer(string $id, string $module, string $source): never
    {
        throw new \LogicException(sprintf('module.services.container: "%s" (%s) -> "%s"; inject explicit services or a bounded Symfony service locator, not a full container.', $source, $module, $id));
    }

    private function className(Definition $definition): string
    {
        $class = ltrim($definition->getClass() ?? '', '\\');

        return $this->container->getReflectionClass($class, false)->name ?? $class;
    }

    private function resolveId(string $id): string
    {
        // Alias cycles are rejected by Symfony before this pass.
        while ($this->container->hasAlias($id)) {
            $id = (string) $this->container->getAlias($id);
        }

        return $id;
    }
}
