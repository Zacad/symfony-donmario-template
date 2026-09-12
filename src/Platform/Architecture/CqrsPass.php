<?php

declare(strict_types=1);

namespace App\Platform\Architecture;

use App\Platform\Event\ApplicationEvent;
use App\Platform\Messaging\ApplicationEventRecorder;
use App\Platform\Messaging\BestEffortEventDispatcher;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\CommandTransactionMiddleware;
use App\Platform\Messaging\EventDeliveryContext;
use App\Platform\Messaging\EventListenerInvoker;
use App\Platform\Messaging\EventPolicyMiddleware;
use App\Platform\Messaging\InvocationContext;
use App\Platform\Messaging\InvocationMiddleware;
use App\Platform\Messaging\MessagePolicyMiddleware;
use App\Platform\Messaging\QueryBus;
use Symfony\Component\Cache\Messenger\EarlyExpirationMessage;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\Messenger\PingWebhookMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\AddBusNameStampMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\TraceableMiddleware;
use Symfony\Component\Messenger\Middleware\ValidationMiddleware;
use Symfony\Component\Process\Messenger\RunProcessMessage;

/** After Messenger/autowiring, before module edge checking and service removal. */
final readonly class CqrsPass implements CompilerPassInterface
{
    public function __construct(private ModuleMap $modules)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        // Minimal architecture fixture kernels do not configure the application buses.
        if (!$container->has('command.bus') && !$container->has('query.bus') && !$container->has('application.event.bus')) {
            return;
        }
        $this->busInventory($container);
        $messages = $this->messages($container);
        $listeners = $this->listeners($container, $messages);
        $this->eventWiring($container, array_filter($messages, static fn (string $kind): bool => 'event' === $kind));
        $seen = [];
        $seenListeners = [];
        $deferred = [];
        foreach (['command' => CommandBus::class, 'query' => QueryBus::class, 'event' => ApplicationEventRecorder::class] as $kind => $facade) {
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
                if (in_array($message, [RedispatchMessage::class, EarlyExpirationMessage::class, RunCommandMessage::class, RunProcessMessage::class, PingWebhookMessage::class], true)) {
                    continue; // Exact framework-only messages; runtime policy rejects them.
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
                        || $reflection->implementsInterface(BatchHandlerInterface::class)
                        || !$reflection->hasMethod('__invoke')) {
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
                        if (isset($seenListeners[$class])) {
                            $this->fail('registration', $class.' has duplicate listener registrations.');
                        }
                        if (($options['priority'] ?? 0) !== $listeners[$class]['priority']) {
                            $this->fail('registration', $class.' must retain its declared listener priority.');
                        }
                        $seenListeners[$class] = true;
                        $deferred[] = [$descriptor, $class];
                    }
                    $seen[$message] = true;
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
        // Never accept user-supplied wrappers/aliases as original registrations.
        // All cardinality, signature, privacy and wiring checks above run first.
        foreach ($deferred as [$descriptor, $class]) {
            $reference = $descriptor->getArgument(0);
            if (!$reference instanceof Reference) {
                $this->fail('wiring', 'A deferred listener requires its original service reference.');
            }
            $options = $descriptor->getArgument(1);
            if (!is_array($options)) {
                $this->fail('registration', 'A deferred listener requires its checked descriptor options.');
            }
            $descriptor->setArgument(0, new Definition(EventListenerInvoker::class, [new ServiceClosureArgument($reference)]));
            $descriptor->setArgument(1, $options + ['alias' => $class.'::__invoke']);
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
        $this->assertReference($container, $scope->getArgument(3), BestEffortEventDispatcher::class);
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
        $expected[] = $bus.'.middleware.handle_message';
        $middleware = $busDefinition->getArgument(0);
        $values = $middleware instanceof IteratorArgument ? $middleware->getValues() : [];
        if ($container->getParameter('kernel.debug') && isset($values[0]) && $values[0] instanceof Reference && (string) $values[0] === $bus.'.middleware.traceable') {
            $this->definition($container, $bus.'.middleware.traceable', TraceableMiddleware::class);
            array_shift($values);
        }
        if (count($expected) !== count($values)) {
            $this->fail('middleware', $bus.' requires the synchronous scope/policy/validation/transaction/handling order.');
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
        $approved = ['command.bus', 'query.bus', 'application.event.bus'];
        foreach ($container->getDefinitions() as $id => $definition) {
            if (($definition->hasTag('messenger.bus') || MessageBus::class === $definition->getClass()) && !in_array($id, $approved, true)) {
                $this->fail('bus', $id.' is an unapproved additional message bus.');
            }
        }
        if ([] !== $container->findTaggedServiceIds('messenger.receiver')) {
            $this->fail('wiring', 'Synchronous application buses cannot configure transports.');
        }
        if ($container->has('messenger.senders_locator') && [] !== $container->findDefinition('messenger.senders_locator')->getArgument(0)) {
            $this->fail('wiring', 'Synchronous application buses cannot configure transport routing.');
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
                    // Autoconfiguration leaves .abstract.instanceof definitions
                    // carrying the concrete class. These are inheritance templates,
                    // not additional runtime listeners.
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
                $definition = reset($definitions);
                $tags = $definition->getTag('messenger.message_handler');
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
        $this->definition($container, EventDeliveryContext::class, EventDeliveryContext::class);
        $this->definition($container, InvocationContext::class, InvocationContext::class);
        $recorder = $this->definition($container, ApplicationEventRecorder::class, ApplicationEventRecorder::class);
        $this->assertReference($container, $recorder->getArgument(0), InvocationContext::class);
        $recorder->setArgument(1, $events);
        $dispatcher = $this->definition($container, BestEffortEventDispatcher::class, BestEffortEventDispatcher::class);
        $this->assertReference($container, $dispatcher->getArgument(0), EventDeliveryContext::class);
        $this->assertReference($container, $dispatcher->getArgument(1), $bus);
        $this->assertReference($container, $dispatcher->getArgument(2), 'logger');
        $policy = $this->definition($container, EventPolicyMiddleware::class, EventPolicyMiddleware::class);
        $this->assertReference($container, $policy->getArgument(0), EventDeliveryContext::class);
        $this->assertReference($container, $policy->getArgument(1), InvocationContext::class);
        $policy->setArgument(2, $events);
        $definition = $this->definition($container, $bus, MessageBus::class);
        if ($definition->isPublic()) {
            $this->fail('wiring', 'The application event bus must remain private.');
        }
        foreach ($container->getAliases() as $id => $alias) {
            if ($alias->isPublic() && $container->findDefinition($id) === $definition) {
                $this->fail('wiring', 'The application event bus cannot have a public alias.');
            }
        }
        $middleware = $definition->getArgument(0);
        $values = $middleware instanceof IteratorArgument ? $middleware->getValues() : [];
        if ($container->getParameter('kernel.debug') && isset($values[0]) && $values[0] instanceof Reference && (string) $values[0] === $bus.'.middleware.traceable') {
            $this->definition($container, $bus.'.middleware.traceable', TraceableMiddleware::class);
            array_shift($values);
        }
        $expected = [EventPolicyMiddleware::class, $bus.'.middleware.add_bus_name_stamp_middleware', $bus.'.middleware.handle_message'];
        if (count($values) !== count($expected)) {
            $this->fail('middleware', $bus.' requires only delivery policy, bus stamp and ordinary handling.');
        }
        foreach ($expected as $index => $id) {
            $this->assertReference($container, $values[$index], $id);
        }
        $stamp = $this->definition($container, $bus.'.middleware.add_bus_name_stamp_middleware', AddBusNameStampMiddleware::class);
        if ($stamp->getArgument(0) !== $bus) {
            $this->fail('wiring', $bus.' requires its own bus-name stamp.');
        }
        $handling = $this->definition($container, $bus.'.middleware.handle_message', HandleMessageMiddleware::class);
        $this->assertReference($container, $handling->getArgument(0), $bus.'.messenger.handlers_locator');
        if (true !== $handling->getArgument(1)) {
            $this->fail('wiring', $bus.' must allow events with no listeners.');
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

        return ($allowVoid && 'void' === $name) || in_array($name, ['null', 'string', 'int', 'float', 'bool', 'true', 'false'], true)
            || ContractTypes::isImmutable($name) || (ContractTypes::isPublic($name) && (class_exists($name) || enum_exists($name)));
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
            || [] !== $definition->getProperties()
            || !$this->supportedCalls($container, $definition, $class)) {
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
        // FrameworkBundle/Monolog's one ordinary logger setter is the sole
        // supported post-construction call. In particular, never allow a second
        // __construct() to replace a previously checked stack, map or descriptor.
        if (HandleMessageMiddleware::class !== $class || 1 !== count($calls)
            || 'setLogger' !== $calls[0][0] || [0] !== array_keys($calls[0][1])
            || ($calls[0][2] ?? false) || !$calls[0][1][0] instanceof Reference) {
            return false;
        }
        $logger = $this->reference($container, $calls[0][1][0]);
        foreach (['logger', 'monolog.logger.messenger'] as $id) {
            if ($container->has($id) && $logger === $container->findDefinition($id)) {
                return true;
            }
        }

        return false;
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
        if (!$container->has($id) || $this->reference($container, $value) !== $container->findDefinition($id)) {
            $this->fail('wiring', 'Expected a reference to '.$id.'.');
        }
    }

    private function fail(string $rule, string $message): never
    {
        throw new \LogicException('cqrs.'.$rule.': '.$message);
    }
}
