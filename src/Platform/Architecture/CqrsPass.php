<?php

declare(strict_types=1);

namespace App\Platform\Architecture;

use App\Platform\Authorization\Actor;
use App\Platform\Authorization\AuthorizationDenied;
use App\Platform\Authorization\AuthorizationMiddleware;
use App\Platform\Authorization\AuthorizationToken;
use App\Platform\Authorization\Authorize;
use App\Platform\Authorization\ExecutionContext;
use App\Platform\Authorization\PublicAccessVoter;
use App\Platform\Event\ApplicationEvent;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\CommandTransactionMiddleware;
use App\Platform\Messaging\EventBus;
use App\Platform\Messaging\EventPolicyMiddleware;
use App\Platform\Messaging\InvocationContext;
use App\Platform\Messaging\InvocationMiddleware;
use App\Platform\Messaging\MessagePolicyMiddleware;
use App\Platform\Messaging\QueryBus;
use App\Platform\Messaging\ResultValidationMiddleware;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\AddBusNameStampMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Middleware\TraceableMiddleware;
use Symfony\Component\Messenger\Middleware\ValidationMiddleware;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Strategy\UnanimousStrategy;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** After Messenger/autowiring, before module edge checking and service removal. */
final readonly class CqrsPass implements CompilerPassInterface
{
    public function __construct(private ModuleMap $modules)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        // Minimal architecture fixture kernels do not configure application buses.
        if (!$container->has('command.bus') && !$container->has('query.bus') && !$container->has('application.event.bus')) {
            return;
        }
        $this->busInventory($container);
        $this->defaultConnection($container);
        $messages = $this->messages($container);
        $listeners = $this->listeners($container, $messages);
        $this->eventWiring($container, array_filter($messages, static fn (string $kind): bool => 'event' === $kind));
        $seen = $seenListeners = $authorizedHandlers = [];
        foreach (['command' => CommandBus::class, 'query' => QueryBus::class, 'event' => EventBus::class] as $kind => $facade) {
            $bus = 'event' === $kind ? 'application.event.bus' : $kind.'.bus';
            if ('event' !== $kind) {
                $this->wiring($container, $kind, $facade);
                $container->findDefinition('app.'.$kind.'_policy')->setArgument(1, $messages);
            }
            $locator = $this->definition($container, $bus.'.messenger.handlers_locator', HandlersLocator::class);
            $mapping = $locator->getArgument(0);
            if (!is_array($mapping)) {
                $this->fail('handlers', $bus.' requires a literal handler map.');
            }
            foreach ($mapping as $message => $handlers) {
                // Native FrameworkBundle handlers remain native. Runtime policy
                // admits only the inventoried public application messages.
                if (is_string($message) && str_starts_with($message, 'Symfony\\')) {
                    continue;
                }
                if (!is_string($message) || !isset($messages[$message])) {
                    $this->fail('message', $bus.' has an unknown, internal or wildcard message registration.');
                }
                if ($kind !== $messages[$message]) {
                    $this->fail('bus', $message.' is registered on '.$bus.'.');
                }
                if (!$handlers instanceof IteratorArgument || ('event' !== $kind && 1 !== count($handlers->getValues()))) {
                    $this->fail('handler_count', $message.' requires exactly one handler on '.$bus.'.');
                }
                foreach ($handlers->getValues() as $handlerReference) {
                    $descriptor = $this->reference($container, $handlerReference);
                    $this->assertDefinition($container, $descriptor, 'descriptor for '.$message, HandlerDescriptor::class);
                    $options = $descriptor->getArgument(1);
                    if (!is_array($options) || ($options['bus'] ?? null) !== $bus
                        || [] !== array_diff(array_keys($options), ['bus', 'priority', 'method'])
                        || '__invoke' !== ($options['method'] ?? '__invoke')) {
                        $this->fail('registration', $message.' requires an explicit bus and ordinary __invoke handler.');
                    }
                    $handler = $this->reference($container, $descriptor->getArgument(0));
                    if (null !== $handler->getFactory() || null !== $handler->getConfigurator() || $handler->isSynthetic() || null !== $handler->getFile()) {
                        $this->fail('handler', $message.' requires an ordinary handler service.');
                    }
                    $class = $handler->getClass() ?? '';
                    $this->assertPrivateHandler($container, $handler, $class);
                    $reflection = $container->getReflectionClass($class);
                    $messageReflection = $container->getReflectionClass($message);
                    if (null === $reflection || null === $messageReflection
                        || ('event' !== $kind && $reflection->getNamespaceName() !== $messageReflection->getNamespaceName())
                        || $reflection->implementsInterface(BatchHandlerInterface::class) || !$reflection->hasMethod('__invoke')) {
                        $this->fail('handler', $message.' requires a co-located, owning-module handler.');
                    }
                    $method = $reflection->getMethod('__invoke');
                    $parameters = $method->getParameters();
                    $type = $parameters[0]->getType() ?? null;
                    if (!$method->isPublic() || $method->isStatic() || 1 !== count($parameters)
                        || !$type instanceof \ReflectionNamedType || $type->getName() !== $message
                        || $type->allowsNull() || $parameters[0]->isVariadic() || $parameters[0]->isPassedByReference()
                        || ('event' === $kind ? 'void' !== ($method->getReturnType() instanceof \ReflectionNamedType ? $method->getReturnType()->getName() : null) : !$this->returnType($method->getReturnType(), 'command' === $kind))) {
                        $this->fail('signature', $class.' requires one exact message argument and a declared public-data return type.');
                    }
                    if ('event' === $kind) {
                        if (!isset($listeners[$class]) || $listeners[$class]['message'] !== $message) {
                            $this->fail('listener', $class.' must be an inventoried Infrastructure/EventListener/*Listener.');
                        }
                        if (isset($seenListeners[$class]) || ($options['priority'] ?? 0) !== $listeners[$class]['priority']) {
                            $this->fail('registration', $class.' must retain its single declared listener registration and priority.');
                        }
                        $seenListeners[$class] = true;
                    }
                    $seen[$message] = true;
                    $authorizedHandlers[] = [$message, $kind, $reflection];
                }
            }
        }
        foreach ($messages as $message => $kind) {
            if ('event' !== $kind && !isset($seen[$message])) {
                $this->fail('handler_count', $message.' requires exactly one handler on '.$kind.'.bus.');
            }
        }
        foreach ($listeners as $class => $listener) {
            if (!isset($seenListeners[$class])) {
                $this->fail('listener_inventory', $class.' is missing its declared application-event registration.');
            }
        }
        $this->authorization($container, $authorizedHandlers);
    }

    /** @param list<array{string, string, \ReflectionClass<object>}> $handlers */
    private function authorization(ContainerBuilder $container, array $handlers): void
    {
        foreach ($container->getDefinitions() as $definition) {
            if (!$definition->isAbstract() && !$definition->hasTag('container.excluded')
                && in_array(ltrim($definition->getClass() ?? '', '\\'), [Actor::class, AuthorizationToken::class, Authorize::class, AuthorizationDenied::class], true)) {
                $this->fail('authorization', 'Authorization data must not be services.');
            }
        }

        $routes = $capabilities = $voterPermissions = [];
        foreach ($handlers as [$message, $kind, $handler]) {
            $attributes = $handler->getAttributes(Authorize::class);
            foreach ($handler->getMethods() as $method) {
                if ([] !== $method->getAttributes(Authorize::class)) {
                    $this->fail('authorization', $handler->name.' requires class-level authorization only.');
                }
            }
            if ('event' === $kind) {
                if ([] !== $attributes) {
                    $this->fail('authorization', 'Event listeners cannot declare authorization capabilities.');
                }
                continue;
            }
            if (1 !== count($attributes)) {
                $this->fail('authorization', $handler->name.' requires exactly one Authorize declaration.');
            }
            try {
                $metadata = $attributes[0]->newInstance();
            } catch (\Throwable) {
                $this->fail('authorization', $handler->name.' has invalid authorization metadata.');
            }
            if ($metadata->public && (null !== $metadata->voter || null !== $metadata->permission || null !== $metadata->label)) {
                $this->fail('authorization', $handler->name.' public authorization cannot declare a voter, permission or label.');
            }
            if (!$metadata->public && null === $metadata->voter) {
                $this->fail('authorization', $handler->name.' requires a voter or explicit public authorization.');
            }
            if (null === $metadata->permission && null !== $metadata->label
                || (null !== $metadata->permission && (null === $metadata->label || '' === trim($metadata->label) || mb_strlen($metadata->label) > 100 || 1 === preg_match('/[\x00\r\n]/', $metadata->label)))) {
                $this->fail('authorization', $handler->name.' has invalid capability metadata.');
            }
            $module = ModuleMap::owner($handler->name);
            if (null === $module || $module !== ModuleMap::owner($message)) {
                $this->fail('authorization', $handler->name.' must be owned by its message module.');
            }
            $voterName = $metadata->public ? PublicAccessVoter::class : (string) $metadata->voter;
            $voter = $container->getReflectionClass($voterName);
            if (null === $voter || $voter->name !== $voterName || !$voter->isFinal() || !$voter->isInstantiable()
                || !ContractTypes::isAuthorizationVoter($voter->name) || !$voter->implementsInterface(VoterInterface::class)
                || (!$metadata->public && ModuleMap::owner($voter->name) !== $module)) {
                $this->fail('authorization_voter', $handler->name.' requires its approved concrete final voter class.');
            }
            $permission = null;
            $enum = $metadata->permission;
            if (null !== $enum) {
                $enumClass = new \ReflectionEnum($enum::class);
                if (!$enumClass->isBacked() || 'string' !== $enumClass->getBackingType()->getName()
                    || 1 !== preg_match('/Permission(?:Enum)?\z/D', $enumClass->getShortName())
                    || ModuleMap::owner($enumClass->name) !== $module || 'Domain' !== ModuleMap::layer($enumClass->name)
                    || !is_string($enum->value) || 1 !== preg_match('/\A[a-z0-9._-]{1,64}\z/D', $enum->value)
                    || !str_starts_with($enum->value, rtrim(ModuleMap::prefix($module), '_').'.')) {
                    $this->fail('authorization', $handler->name.' requires module-owned string-backed Permission metadata.');
                }
                $permission = $enum->value;
            }
            $routes[$message] = $voter->name;
            $voterPermissions[$voter->name][$message] = $permission;
            if (null === $permission) {
                continue;
            }
            $label = $metadata->label;
            $short = $container->getReflectionClass($message)?->getShortName();
            if (null === $short) {
                $this->fail('authorization', 'Capability operation '.$message.' cannot be reflected.');
            }
            if (isset($capabilities[$permission])) {
                if ($capabilities[$permission]['module'] !== rtrim(ModuleMap::prefix($module), '_')
                    || $capabilities[$permission]['label'] !== $label) {
                    $this->fail('authorization', 'Capability '.$permission.' has inconsistent metadata.');
                }
                $capabilities[$permission]['operations'][] = $short;
                $capabilities[$permission]['kinds'][] = $kind;
            } else {
                $capabilities[$permission] = [
                    'module' => rtrim(ModuleMap::prefix($module), '_'),
                    'key' => $permission,
                    'label' => $label,
                    'operations' => [$short],
                    'kinds' => [$kind],
                ];
            }
        }

        if (!isset($voterPermissions[PublicAccessVoter::class])) {
            $this->definition($container, PublicAccessVoter::class, PublicAccessVoter::class)->clearTag('app.authorization.voter');
        }

        $taggedVoters = [];
        foreach ($container->findTaggedServiceIds('app.authorization.voter', true) as $id => $tags) {
            $definition = $container->getDefinition($id);
            $class = ltrim($definition->getClass() ?? '', '\\');
            $reflection = $container->getReflectionClass($class);
            if ((string) $id !== $class || 1 !== count($tags) || [[]] !== array_values($tags)
                || !isset($voterPermissions[$class]) || null === $reflection || !$reflection->isFinal() || !$reflection->isInstantiable()
                || !ContractTypes::isAuthorizationVoter($class) || !$reflection->implementsInterface(VoterInterface::class)
                || $definition->isPublic() || !$definition->isLazy() || !$definition->isAutowired() || $definition->isAutoconfigured()
                || $definition->hasTag('security.voter') || null !== $definition->getFactory() || null !== $definition->getConfigurator()
                || $definition->isSynthetic() || null !== $definition->getFile() || null !== $definition->getDecoratedService()) {
                $this->fail('authorization_voter', $id.' requires one bare tag on its canonical private lazy autowired non-autoconfigured voter service.');
            }
            foreach ($container->getAliases() as $aliasId => $alias) {
                if ($alias->isPublic() && $container->findDefinition($aliasId) === $definition) {
                    $this->fail('authorization_voter', $class.' cannot have public aliases.');
                }
            }
            ksort($voterPermissions[$class]);
            $definition->setArgument(0, $voterPermissions[$class]);
            $taggedVoters[$class] = true;
        }
        foreach ($voterPermissions as $voter => $_) {
            $definitions = [];
            foreach ($container->getDefinitions() as $id => $definition) {
                if (!$definition->isAbstract() && !$definition->hasTag('container.excluded') && $voter === ltrim($definition->getClass() ?? '', '\\')) {
                    $definitions[] = (string) $id;
                }
            }
            if (!isset($taggedVoters[$voter]) || [$voter] !== $definitions) {
                $this->fail('authorization_voter', $voter.' requires exactly one referenced and tagged canonical service.');
            }
        }

        ksort($capabilities);
        $descriptors = [];
        foreach ($capabilities as $capability) {
            $operations = array_values(array_unique($capability['operations']));
            sort($operations);
            $descriptors[] = [
                'module' => $capability['module'],
                'key' => $capability['key'],
                'label' => $capability['label'],
                'access' => [] === array_diff($capability['kinds'], ['query']) ? 'read' : 'write',
                'operations' => $operations,
            ];
        }

        $catalog = $this->definition($container, 'App\\Module\\Authorizing\\Domain\\Capability\\AuthorizationCatalogService', 'App\\Module\\Authorizing\\Domain\\Capability\\AuthorizationCatalogService');
        $catalog->setArgument(0, $descriptors);
        ksort($routes);
        $middleware = $this->definition($container, AuthorizationMiddleware::class, AuthorizationMiddleware::class);
        $middleware->setArgument(3, $routes);

        $manager = $this->definition($container, 'app.authorization.decision_manager', AccessDecisionManager::class);
        $strategy = $this->definition($container, 'app.authorization.unanimous_strategy', UnanimousStrategy::class);
        $voters = $manager->getArgument(0);
        if ($manager->isPublic() || $strategy->isPublic() || [false] !== $strategy->getArguments()
            || !$voters instanceof TaggedIteratorArgument || 'app.authorization.voter' !== $voters->getTag()
            || null !== $voters->getIndexAttribute() || $voters->needsIndexes() || [] !== $voters->getExclude() || !$voters->excludeSelf()
            || null !== $voters->getDefaultIndexMethod(false) || null !== $voters->getDefaultPriorityMethod(false)) {
            $this->fail('authorization_manager', 'The isolated manager requires the exact app.authorization.voter tagged iterator and private UnanimousStrategy(false) wiring.');
        }
        $this->assertReference($container, $manager->getArgument(1), 'app.authorization.unanimous_strategy');
        $this->assertReference($container, $middleware->getArgument(2), 'app.authorization.decision_manager');
        foreach ($container->getAliases() as $id => $alias) {
            if ($alias->isPublic() && in_array($container->findDefinition($id), [$manager, $strategy], true)) {
                $this->fail('authorization_manager', 'The isolated authorization manager cannot have public aliases.');
            }
        }
    }

    /** @return array<class-string, string> */
    private function messages(ContainerBuilder $container): array
    {
        $messages = [];
        foreach ($this->modules->modules() as $module) {
            foreach (glob($this->modules->path($module).'/Application/*/*.php') ?: [] as $file) {
                $class = 'App\\Module\\'.$module.'\\Application\\'.basename(dirname($file)).'\\'.basename($file, '.php');
                $kind = ContractTypes::messageKind($class);
                if (null === $kind) {
                    continue;
                }
                $reflection = $container->getReflectionClass($class);
                if (null === $reflection || $reflection->isEnum() || !$reflection->isFinal() || !$reflection->isReadOnly()) {
                    $this->fail('message', $class.' must be a final readonly message DTO.');
                }
                if ('event' === $kind && ApplicationEvent::class !== ($reflection->getParentClass() ?: null)?->name) {
                    $this->fail('message', $class.' must directly extend ApplicationEvent.');
                }
                $messages[$reflection->name] = $kind;
            }
        }

        return $messages;
    }

    private function wiring(ContainerBuilder $container, string $kind, string $facade): void
    {
        $bus = $kind.'.bus';
        $this->definition($container, InvocationContext::class, InvocationContext::class);
        $helper = $this->definition($container, $facade, $facade);
        $busDefinition = $this->definition($container, $bus, MessageBus::class);
        $this->assertReference($container, $helper->getArgument(0), $bus);
        $scope = $this->definition($container, 'app.'.$kind.'_scope', InvocationMiddleware::class);
        $this->assertReference($container, $scope->getArgument(0), InvocationContext::class);
        $this->assertReference($container, $scope->getArgument(1), 'doctrine');
        $this->assertReference($container, $scope->getArgument(3), 'logger');
        $policy = $this->definition($container, 'app.'.$kind.'_policy', MessagePolicyMiddleware::class);
        if ($scope->getArgument(2) !== $kind || $policy->getArgument(0) !== $kind) {
            $this->fail('wiring', $bus.' has the wrong invocation/message policy.');
        }
        $expected = ['app.'.$kind.'_scope', 'app.'.$kind.'_policy', $bus.'.middleware.add_bus_name_stamp_middleware', 'messenger.middleware.validation'];
        if ('command' === $kind) {
            $transaction = $this->definition($container, CommandTransactionMiddleware::class, CommandTransactionMiddleware::class);
            $this->assertReference($container, $transaction->getArgument(0), 'doctrine');
            $this->assertReference($container, $transaction->getArgument(1), InvocationContext::class);
            $expected[] = CommandTransactionMiddleware::class;
        }
        $authorization = $this->definition($container, AuthorizationMiddleware::class, AuthorizationMiddleware::class);
        $this->assertReference($container, $authorization->getArgument(0), InvocationContext::class);
        $this->assertReference($container, $authorization->getArgument(1), ExecutionContext::class);
        $execution = $this->definition($container, ExecutionContext::class, ExecutionContext::class);
        $this->assertReference($container, $execution->getArgument(0), InvocationContext::class);
        $this->assertReference($container, $execution->getArgument(1), 'request_stack');
        $this->assertReference($container, $execution->getArgument(2), 'security.token_storage');
        $this->assertReference($container, $execution->getArgument(3), 'security.authentication.trust_resolver');
        $expected[] = AuthorizationMiddleware::class;
        $resultValidation = $this->definition($container, ResultValidationMiddleware::class, ResultValidationMiddleware::class);
        $this->assertReference($container, $resultValidation->getArgument(0), 'validator');
        $expected[] = ResultValidationMiddleware::class;
        $expected[] = $bus.'.middleware.handle_message';
        $middleware = $busDefinition->getArgument(0);
        $values = $middleware instanceof IteratorArgument ? $middleware->getValues() : [];
        if ($container->getParameter('kernel.debug') && isset($values[0]) && $values[0] instanceof Reference && (string) $values[0] === $bus.'.middleware.traceable') {
            $this->definition($container, $bus.'.middleware.traceable', TraceableMiddleware::class);
            array_shift($values);
        }
        if (count($expected) !== count($values)) {
            $this->fail('middleware', $bus.' requires the synchronous scope/policy/input-validation/transaction/authorization/result-validation/handling order.');
        }
        foreach ($expected as $index => $id) {
            $this->assertReference($container, $values[$index], $id);
        }
        $stamp = $this->definition($container, $bus.'.middleware.add_bus_name_stamp_middleware', AddBusNameStampMiddleware::class);
        if ($stamp->getArgument(0) !== $bus) {
            $this->fail('wiring', $bus.' has an incorrect bus-name stamp.');
        }
        $validation = $this->definition($container, 'messenger.middleware.validation', ValidationMiddleware::class);
        $this->assertReference($container, $validation->getArgument(0), 'validator');
        $handling = $this->definition($container, $bus.'.middleware.handle_message', HandleMessageMiddleware::class);
        $this->assertReference($container, $handling->getArgument(0), $bus.'.messenger.handlers_locator');
        if (false !== $handling->getArgument(1)) {
            $this->fail('wiring', $bus.' cannot allow unhandled messages.');
        }
    }

    private function busInventory(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (($definition->hasTag('messenger.bus') || MessageBus::class === $definition->getClass()) && !in_array($id, ['command.bus', 'query.bus', 'application.event.bus'], true)) {
                $this->fail('bus', $id.' is an unapproved additional message bus.');
            }
        }
    }

    /**
     * @param array<class-string, string> $messages
     *
     * @return array<class-string, array{message: class-string, priority: int}>
     */
    private function listeners(ContainerBuilder $container, array $messages): array
    {
        $listeners = [];
        foreach ($this->modules->modules() as $module) {
            foreach (glob($this->modules->path($module).'/Infrastructure/EventListener/*.php') ?: [] as $file) {
                $class = 'App\\Module\\'.$module.'\\Infrastructure\\EventListener\\'.basename($file, '.php');
                $reflection = $container->getReflectionClass($class);
                if (null === $reflection || !$reflection->isInstantiable() || !ContractTypes::isOwnEventListener($class) || !$reflection->hasMethod('__invoke')) {
                    $this->fail('listener', $class.' requires a concrete invokable Listener.');
                }
                $attributes = $reflection->getAttributes(AsMessageHandler::class);
                if (1 !== count($attributes) || [] !== array_diff(array_keys($attributes[0]->getArguments()), ['bus', 'priority'])) {
                    $this->fail('registration', $class.' requires one class-level AsMessageHandler with only bus/priority.');
                }
                $attribute = $attributes[0]->newInstance();
                if ('application.event.bus' !== $attribute->bus) {
                    $this->fail('registration', $class.' must explicitly use application.event.bus.');
                }
                foreach ($reflection->getMethods() as $method) {
                    if ([] !== $method->getAttributes(AsMessageHandler::class)) {
                        $this->fail('registration', $class.' cannot declare method-level message handling.');
                    }
                }
                $method = $reflection->getMethod('__invoke');
                $parameters = $method->getParameters();
                $type = $parameters[0]->getType() ?? null;
                $return = $method->getReturnType();
                if (!$method->isPublic() || $method->isStatic() || 1 !== count($parameters)
                    || !$type instanceof \ReflectionNamedType || $type->allowsNull()
                    || $parameters[0]->isVariadic() || $parameters[0]->isPassedByReference()
                    || 'event' !== ($messages[$type->getName()] ?? null)
                    || !$return instanceof \ReflectionNamedType || 'void' !== $return->getName()
                    || $reflection->implementsInterface(BatchHandlerInterface::class)) {
                    $this->fail('signature', $class.' requires __invoke(ConcretePublicApplicationEvent): void.');
                }
                /** @var class-string $message */
                $message = $type->getName();
                $listeners[$reflection->name] = ['message' => $message, 'priority' => $attribute->priority];
                $definitions = [];
                foreach ($container->getDefinitions() as $id => $definition) {
                    if ($definition->isAbstract() || $definition->hasTag('container.excluded')) {
                        continue;
                    }
                    if (0 === strcasecmp(ltrim($definition->getClass() ?? '', '\\'), $reflection->name)) {
                        $definitions[$id] = $definition;
                        $this->assertPrivateHandler($container, $definition, $reflection->name);
                    }
                }
                if (1 !== count($definitions)) {
                    $this->fail('listener_inventory', $class.' requires one ordinary private listener service.');
                }
                $tags = reset($definitions)->getTag('messenger.message_handler');
                $tag = $tags[0] ?? null;
                if (1 !== count($tags) || !is_array($tag) || 'application.event.bus' !== ($tag['bus'] ?? null)
                    || [] !== array_diff(array_keys($tag), ['bus', 'priority', 'handles', 'method', 'from_transport', 'sign'])
                    || null !== ($tag['handles'] ?? null) || null !== ($tag['method'] ?? null)
                    || null !== ($tag['from_transport'] ?? null) || false !== ($tag['sign'] ?? false)
                    || ($tag['priority'] ?? 0) !== $attribute->priority) {
                    $this->fail('registration', $class.' must retain exactly its declared listener tag.');
                }
            }
        }

        return $listeners;
    }

    /** @param array<class-string, string> $events */
    private function eventWiring(ContainerBuilder $container, array $events): void
    {
        $bus = 'application.event.bus';
        $facade = $this->definition($container, EventBus::class, EventBus::class);
        $this->assertReference($container, $facade->getArgument(0), $bus);
        $this->assertReference($container, $facade->getArgument(1), InvocationContext::class);
        $policy = $this->definition($container, EventPolicyMiddleware::class, EventPolicyMiddleware::class);
        $this->assertReference($container, $policy->getArgument(1), ExecutionContext::class);
        $policy->setArgument(0, $events);
        $definition = $this->definition($container, $bus, MessageBus::class);
        if ($definition->isPublic()) {
            $this->fail('handler_visibility', 'The application event bus must remain private.');
        }
        foreach ($container->getAliases() as $id => $alias) {
            if ($alias->isPublic() && $container->findDefinition($id) === $definition) {
                $this->fail('handler_visibility', 'The application event bus cannot have a public alias.');
            }
        }
        $middleware = $definition->getArgument(0);
        $references = $middleware instanceof IteratorArgument ? $middleware->getValues() : [];
        $classes = [];
        foreach ($references as $reference) {
            $classes[] = $this->reference($container, $reference)->getClass();
        }
        $position = array_search(EventPolicyMiddleware::class, $classes, true);
        $send = array_search(SendMessageMiddleware::class, $classes, true);
        $handle = array_search(HandleMessageMiddleware::class, $classes, true);
        if (false === $position || false === $send || false === $handle || $position > $send || $position > $handle
            || in_array(InvocationMiddleware::class, $classes, true) || in_array(CommandTransactionMiddleware::class, $classes, true)
            || in_array(AuthorizationMiddleware::class, $classes, true)) {
            $this->fail('middleware', $bus.' requires public-event policy before native sending/handling, without an outer command transaction.');
        }
        $this->assertReference($container, $references[$position], EventPolicyMiddleware::class);
        if (true !== $container->findDefinition($bus.'.middleware.handle_message')->getArgument(1)) {
            $this->fail('wiring', $bus.' must allow events with no listeners.');
        }
        $senders = $container->findDefinition('messenger.senders_locator');
        if ([ApplicationEvent::class => ['events']] !== $senders->getArgument(0)) {
            $this->fail('transport', 'Only public Application events may route to events.');
        }
        $senderMap = $this->reference($container, $senders->getArgument(1))->getArgument(0);
        $sender = is_array($senderMap) ? ($senderMap['events'] ?? null) : null;
        if ($sender instanceof ServiceClosureArgument) {
            $sender = $sender->getValues()[0] ?? null;
        }
        $this->assertReference($container, $sender, 'messenger.transport.events');
        foreach ($container->findTaggedServiceIds('messenger.receiver') as $id => $tags) {
            if (!in_array($id, ['messenger.transport.events', 'messenger.transport.events_failed'], true)) {
                $this->fail('transport', 'Only the native events and events_failed transports are supported.');
            }
        }
        foreach (['events', 'events_failed'] as $name) {
            $transport = $container->findDefinition('messenger.transport.'.$name);
            $dsn = $container->resolveEnvPlaceholders($transport->getArgument(0), true);
            if (!in_array($dsn, 'events' === $name ? ['sync://', 'doctrine://default'] : ['doctrine://default'], true)) {
                $this->fail('transport', 'Events support sync:// or doctrine://default; failures require doctrine://default.');
            }
            $options = $transport->getArgument(1);
            foreach (['table_name' => 'platform_messaging_message', 'queue_name' => $name, 'auto_setup' => false, 'use_notify' => false, 'redeliver_timeout' => 300] as $key => $value) {
                if (!is_array($options) || ($options[$key] ?? null) !== $value) {
                    $this->fail('transport', 'Native events require the owned queue table and configured queue options.');
                }
            }
        }
    }

    private function defaultConnection(ContainerBuilder $container): void
    {
        $registry = $this->definition($container, 'doctrine', \Doctrine\Bundle\DoctrineBundle\Registry::class);
        $arguments = $container->getParameterBag()->resolveValue($registry->getArguments());
        if (!is_array($arguments) || 'default' !== ($arguments[3] ?? null) || 'default' !== ($arguments[4] ?? null)
            || !is_array($arguments[1] ?? null) || 'doctrine.dbal.default_connection' !== ($arguments[1]['default'] ?? null)
            || ['default' => 'doctrine.orm.default_entity_manager'] !== ($arguments[2] ?? null)) {
            $this->fail('wiring', 'Command transactions and event transport require the canonical default Doctrine connection and manager.');
        }
        $id = 'doctrine.dbal.default_connection';
        if (!$container->hasDefinition($id) || $container->hasAlias($id)) {
            $this->fail('wiring', 'The default DBAL connection cannot be replaced by an alias or decorator.');
        }
        $connection = $container->getDefinition($id);
        $factory = $connection->getFactory();
        if (!is_array($factory) || [0, 1] !== array_keys($factory) || 'createConnection' !== $factory[1]
            || null !== $connection->getDecoratedService() || $connection->isLazy()
            || [0, 1, 2] !== array_keys($connection->getArguments())) {
            $this->fail('wiring', 'The default DBAL connection requires its standard factory and lifecycle.');
        }
        $this->definition($container, 'doctrine.dbal.connection_factory', \Doctrine\Bundle\DoctrineBundle\ConnectionFactory::class);
        $this->assertReference($container, $factory[0], 'doctrine.dbal.connection_factory');
        $checked = clone $connection;
        $checked->setFactory(null);
        $this->assertDefinition($container, $checked, $id, \Doctrine\DBAL\Connection::class);
        $options = $connection->getArgument(0);
        if (!is_array($options) || \Doctrine\DBAL\Connection::class !== ($options['wrapperClass'] ?? \Doctrine\DBAL\Connection::class)) {
            $this->fail('wiring', 'The default DBAL connection cannot select a replacement connection wrapper.');
        }
        $this->assertReference($container, $connection->getArgument(1), 'doctrine.dbal.default_connection.configuration');
        $configuration = $container->findDefinition('doctrine.dbal.default_connection.configuration');
        $checkedConfiguration = clone $configuration;
        $checkedConfiguration->setMethodCalls([]);
        $this->assertDefinition($container, $checkedConfiguration, 'default DBAL configuration', \Doctrine\DBAL\Configuration::class);
        /** @var list<array{0: string, 1: array<int|string, mixed>, 2?: bool}> $calls */
        $calls = $configuration->getMethodCalls();
        foreach ($calls as [$method, $arguments]) {
            if (!in_array($method, ['setMiddlewares', 'setSchemaManagerFactory', 'setSchemaAssetsFilter', 'setResultCache', 'setAutoCommit'], true)
                || ('setAutoCommit' === $method && [true] !== $arguments)) {
                $this->fail('wiring', 'The default DBAL configuration must preserve the owned transaction lifecycle: '.$method.'.');
            }
        }
        $managerId = 'doctrine.orm.default_entity_manager';
        if (!$container->hasDefinition($managerId) || $container->hasAlias($managerId)) {
            $this->fail('wiring', 'The default EntityManager cannot be replaced by an alias or decorator.');
        }
        $manager = $container->getDefinition($managerId);
        $configurator = $manager->getConfigurator();
        if (!is_array($configurator) || [0, 1] !== array_keys($configurator) || 'configure' !== $configurator[1]
            || null !== $manager->getDecoratedService() || [0, 1, 2] !== array_keys($manager->getArguments())) {
            $this->fail('wiring', 'The default EntityManager requires the standard constructor/configurator lifecycle.');
        }
        $this->definition($container, 'doctrine.orm.default_manager_configurator', \Doctrine\Bundle\DoctrineBundle\ManagerConfigurator::class);
        $this->assertReference($container, $configurator[0], 'doctrine.orm.default_manager_configurator');
        $checkedManager = clone $manager;
        $checkedManager->setConfigurator(null);
        $this->assertDefinition($container, $checkedManager, $managerId, \Doctrine\ORM\EntityManager::class);
        foreach (['doctrine.dbal.default_connection', 'doctrine.orm.default_configuration', 'doctrine.dbal.default_connection.event_manager'] as $index => $reference) {
            $this->assertReference($container, $manager->getArgument($index), $reference);
        }
    }

    private function returnType(?\ReflectionType $type, bool $allowVoid): bool
    {
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (!$this->returnType($member, false)) {
                    return false;
                }
            }

            return true;
        }
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }
        $name = $type->getName();

        if (($allowVoid && 'void' === $name) || in_array($name, ['null', 'string', 'int', 'float', 'bool', 'true', 'false'], true) || ContractTypes::isImmutable($name)) {
            return true;
        }
        if (!ContractTypes::isPublic($name) || str_ends_with($name, 'Input') || (!class_exists($name) && !enum_exists($name))) {
            return false;
        }
        if (enum_exists($name) || !in_array(ContractTypes::messageKind($name), ['command', 'query'], true)) {
            return true;
        }
        $visited = [];

        // Ordinary message-shaped results retain their existing contract. Once
        // their public DTO graph contains an array, the output needs a Result name.
        return !$this->containsArray($type, $visited);
    }

    /** @param array<class-string, true> $visited */
    private function containsArray(?\ReflectionType $type, array &$visited): bool
    {
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if ($this->containsArray($member, $visited)) {
                    return true;
                }
            }

            return false;
        }
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }
        $name = $type->getName();
        if ('array' === $name) {
            return true;
        }
        if (!ContractTypes::isPublic($name) || !class_exists($name)) {
            return false;
        }
        $class = new \ReflectionClass($name);
        if ($class->isEnum() || isset($visited[$class->name])) {
            return false;
        }
        $visited[$class->name] = true;
        foreach ($class->getProperties() as $property) {
            if ($this->containsArray($property->getType(), $visited)) {
                return true;
            }
        }

        return false;
    }

    private function definition(ContainerBuilder $container, string $id, string $class): Definition
    {
        if (!$container->has($id)) {
            $this->fail('wiring', $id.' is required.');
        }
        $definition = $container->findDefinition($id);
        $this->assertDefinition($container, $definition, $id, $class);

        return $definition;
    }

    private function assertDefinition(ContainerBuilder $container, Definition $definition, string $id, string $class): void
    {
        if ($definition->getClass() !== $class || !$definition->isShared() || $definition->isSynthetic() || null !== $definition->getFile()
            || null !== $definition->getFactory() || null !== $definition->getConfigurator()
            || [] !== $definition->getProperties() || !$this->supportedCalls($container, $definition, $class)) {
            $this->fail('wiring', $id.' must be the standard '.$class.' service.');
        }
    }

    private function supportedCalls(ContainerBuilder $container, Definition $definition, string $class): bool
    {
        /** @var list<array{0: string, 1: array<int|string, mixed>, 2?: bool}> $calls */
        $calls = $definition->getMethodCalls();
        if ([] === $calls) {
            return true;
        }

        // Permit the standard logger setter, never a second constructor call.
        return HandleMessageMiddleware::class === $class && 1 === count($calls)
            && 'setLogger' === $calls[0][0] && [0] === array_keys($calls[0][1])
            && !($calls[0][2] ?? false) && $calls[0][1][0] instanceof Reference
            && is_a($this->reference($container, $calls[0][1][0])->getClass() ?? '', \Psr\Log\LoggerInterface::class, true);
    }

    private function assertPrivateHandler(ContainerBuilder $container, Definition $handler, string $class): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isPublic() && 0 === strcasecmp(ltrim($definition->getClass() ?? '', '\\'), ltrim($class, '\\'))) {
                $this->fail('handler_visibility', $id.' exposes handler '.$class.' as a public service.');
            }
        }
        foreach ($container->getAliases() as $id => $alias) {
            if (!$alias->isPublic()) {
                continue;
            }
            $target = $container->findDefinition($id);
            if ($target === $handler || 0 === strcasecmp(ltrim($target->getClass() ?? '', '\\'), ltrim($class, '\\'))) {
                $this->fail('handler_visibility', $id.' exposes handler '.$class.' through a public alias.');
            }
        }
    }

    private function reference(ContainerBuilder $container, mixed $value): Definition
    {
        if (!$value instanceof Reference || !$container->has((string) $value)) {
            $this->fail('wiring', 'A literal reference to an existing service is required.');
        }

        return $container->findDefinition((string) $value);
    }

    private function assertReference(ContainerBuilder $container, mixed $value, string $id): void
    {
        if (!$value instanceof Reference || !$container->has($id) || !$container->has((string) $value)
            || $container->findDefinition((string) $value) !== $container->findDefinition($id)) {
            $this->fail('wiring', 'Expected a reference to '.$id.'.');
        }
    }

    private function fail(string $rule, string $message): never
    {
        throw new \LogicException('cqrs.'.$rule.': '.$message);
    }
}
