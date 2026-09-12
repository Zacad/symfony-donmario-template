<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\EventCompilation;

use App\Kernel;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskHandler;
use App\Module\TaskTracking\Application\CreateTask\TaskCreatedEvent;
use App\Platform\Architecture\CqrsPass;
use App\Platform\Architecture\ModuleMap;
use App\Platform\Architecture\ModuleServicesPass;
use App\Platform\Messaging\ApplicationEventRecorder;
use App\Platform\Messaging\BestEffortEventDispatcher;
use App\Platform\Messaging\EventDeliveryContext;
use App\Platform\Messaging\EventPolicyMiddleware;
use App\Platform\Messaging\InvocationContext;
use Composer\Autoload\ClassLoader;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBus;

/** Production FrameworkBundle/Messenger compilation with a physical listener inventory. */
final class CompilationKernel extends Kernel
{
    private readonly string $directory;
    private readonly string $module;
    private readonly ClassLoader $loader;
    /** @var list<class-string> */
    private array $listeners = [];

    public function __construct(private readonly string $scenario)
    {
        parent::__construct('test', false);
        $token = bin2hex(random_bytes(10));
        $this->directory = sys_get_temp_dir().'/event-compilation-'.$token;
        $this->module = 'Event'.$token.'Compiling';
        $this->loader = new ClassLoader();
    }

    /** @param callable(ContainerBuilder): void $test */
    public static function run(string $scenario, callable $test): void
    {
        $kernel = new self($scenario);
        try {
            $kernel->fixtures();
            $kernel->initializeBundles();
            $test($kernel->buildContainer());
        } finally {
            $kernel->shutdown();
            $kernel->loader->unregister();
            new Filesystem()->remove($kernel->directory);
        }
    }

    /** @param callable(ContainerInterface, list<class-string>): void $test */
    public static function withBooted(callable $test): void
    {
        $kernel = new self('construction-failure');
        try {
            $kernel->fixtures();
            $kernel->boot();
            $test($kernel->getContainer(), $kernel->listeners);
        } finally {
            $kernel->shutdown();
            $kernel->loader->unregister();
            new Filesystem()->remove($kernel->directory);
        }
    }

    private function fixtures(): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory.'/src/Module');
        $filesystem->symlink($this->getProjectDir().'/src/Module/TaskTracking', $this->directory.'/src/Module/TaskTracking');
        if ('zero' === $this->scenario) {
            return;
        }
        $root = $this->directory.'/src/Module/'.$this->module;
        $this->loader->addPsr4('App\\Module\\'.$this->module.'\\', $root);
        $this->loader->register(true);
        $names = 'construction-failure' === $this->scenario ? ['FirstListener', 'SecondListener', 'ThirdListener'] : ['FirstListener', 'SecondListener'];
        foreach ($names as $index => $name) {
            $source = str_replace(['EventCompiling', 'FirstListener'], [$this->module, $name], $filesystem->readFile(__DIR__.'/Listener.php.fixture'));
            if ('construction-failure' === $this->scenario) {
                $label = ['A', 'B', 'C'][$index];
                $source = str_replace('priority: 10', 'priority: '.(30 - 10 * $index), $source);
                $source = str_replace("    public function __invoke(TaskCreatedEvent \$event): void\n    {\n    }", "    public function __invoke(TaskCreatedEvent \$event): void\n    {\n        \$this->commands->dispatch(new \\App\\Module\\".$this->module."\\Application\\Probe\\ProbeCommand('".$label."'));\n    }", $source);
                if ('B' === $label) {
                    $source = str_replace("    public function __construct(private CommandBus \$commands)\n    {\n    }", "    public function __construct(private CommandBus \$commands)\n    {\n        \\App\\Tests\\Fixtures\\EventCompilation\\RuntimeEvidence::\$actions[] = 'construct-B';\n        throw new \\RuntimeException('SECRET constructor payload');\n    }", $source);
                }
            }
            if ('FirstListener' === $name) {
                $source = match ($this->scenario) {
                    'return' => str_replace('): void', '): ?string', $source),
                    'union' => str_replace('TaskCreatedEvent $event', 'TaskCreatedEvent|\\stdClass $event', $source),
                    'interface' => str_replace('TaskCreatedEvent $event', '\\Stringable $event', $source),
                    'category' => str_replace('TaskCreatedEvent $event', '\\App\\Platform\\Event\\ApplicationEvent $event', $source),
                    'batch' => str_replace(['class FirstListener', '    public static function create'], ['class FirstListener implements \\Symfony\\Component\\Messenger\\Handler\\BatchHandlerInterface', "    public function flush(bool \$force): void {}\n\n    public static function create"], $source),
                    'attribute' => str_replace("bus: 'application.event.bus', priority: 10", "bus: 'application.event.bus', method: '__invoke'", $source),
                    'method-attribute' => str_replace('    public function __invoke', "    #[AsMessageHandler(bus: 'application.event.bus')]\n    public function __invoke", $source),
                    default => $source,
                };
            }
            $filesystem->dumpFile($root.'/Infrastructure/EventListener/'.$name.'.php', $source);
            /** @var class-string $class */
            $class = 'App\\Module\\'.$this->module.'\\Infrastructure\\EventListener\\'.$name;
            $this->listeners[] = $class;
        }
        if ('construction-failure' === $this->scenario) {
            foreach (['ProbeCommand', 'ProbeHandler'] as $name) {
                $filesystem->dumpFile($root.'/Application/Probe/'.$name.'.php', str_replace('EventCompiling', $this->module, $filesystem->readFile(__DIR__.'/'.$name.'.php.fixture')));
            }
        }
    }

    protected function build(ContainerBuilder $container): void
    {
        // This fixture isolates CqrsPass. Source/DI ownership has its own generated
        // module fixtures; all runtime services/configuration below are production.
        foreach ($this->listeners as $listener) {
            $container->register($listener, $listener)->setAutowired(true)->setAutoconfigured(true);
        }
        if ('construction-failure' === $this->scenario) {
            $handler = 'App\\Module\\'.$this->module.'\\Application\\Probe\\ProbeHandler';
            $container->register($handler, $handler)->setAutowired(true)->setAutoconfigured(true);
            $container->register('event.test.logger', RuntimeLog::class)->setPublic(true);
            $container->setAlias('event.test.dispatcher', BestEffortEventDispatcher::class)->setPublic(true);
        }
        $late = in_array($this->scenario, ['stripped-map', 'descriptor-constructor', 'locator-constructor', 'map-wildcard', 'map-duplicate', 'scope', 'unhandled'], true);
        $wrapper = str_starts_with($this->scenario, 'wrapper-');
        // KernelTrait automatically registers kernels implementing a compiler
        // pass at before-optimization -10000. Delegate instead, to run once at
        // the explicitly selected phase (especially after CqrsPass for wrappers).
        $container->addCompilerPass(new class($this) implements CompilerPassInterface {
            public function __construct(private readonly CompilationKernel $kernel)
            {
            }

            public function process(ContainerBuilder $container): void
            {
                $this->kernel->process($container);
            }
        }, $late || $wrapper ? PassConfig::TYPE_BEFORE_REMOVING : PassConfig::TYPE_BEFORE_OPTIMIZATION, $wrapper ? 5 : 20);
        $container->addCompilerPass(new CqrsPass(new ModuleMap($this->directory)), PassConfig::TYPE_BEFORE_REMOVING, 10);
        $container->addCompilerPass(new ModuleServicesPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    public function process(ContainerBuilder $container): void
    {
        $listener = $this->listeners[0] ?? '';
        switch ($this->scenario) {
            case 'construction-failure':
                $container->setAlias('logger', 'event.test.logger');
                $container->getDefinition(BestEffortEventDispatcher::class)->setArgument(2, new Reference('event.test.logger'));
                $container->getDefinition('doctrine')->setFactory([RuntimeRegistryFactory::class, 'create'])->setArguments([]);
                break;
            case 'wrapper-target':
            case 'wrapper-method':
            case 'wrapper-public':
            case 'wrapper-named':
            case 'wrapper-other-consumer':
            case 'wrapper-module-consumer':
            case 'wrapper-copy-target':
                $mapping = $container->getDefinition('application.event.bus.messenger.handlers_locator')->getArgument(0);
                if (!is_array($mapping) || !($mapping[TaskCreatedEvent::class] ?? null) instanceof IteratorArgument) {
                    throw new \LogicException('Expected the event map before wrapper mutation.');
                }
                $reference = $mapping[TaskCreatedEvent::class]->getValues()[0];
                if (!$reference instanceof Reference) {
                    throw new \LogicException('Expected a descriptor reference.');
                }
                $invoker = $container->findDefinition((string) $reference)->getArgument(0);
                if (!$invoker instanceof Definition) {
                    throw new \LogicException('Expected the compiler-generated inline wrapper.');
                }
                if ('wrapper-copy-target' === $this->scenario) {
                    $container->setDefinition('event.listener.late_copy', clone $container->getDefinition($listener));
                    $invoker->setArgument(0, new ServiceClosureArgument(new Reference('event.listener.late_copy')));
                    break;
                }
                match ($this->scenario) {
                    'wrapper-target' => $invoker->setArgument(0, new ServiceClosureArgument(new Reference(CreateTaskHandler::class))),
                    'wrapper-method' => $invoker->addMethodCall('__construct', [$invoker->getArgument(0)]),
                    'wrapper-public' => $invoker->setPublic(true),
                    'wrapper-other-consumer' => $container->register('event.wrapper.consumer', \stdClass::class)->setProperty('invoker', $invoker),
                    'wrapper-module-consumer' => $container->getDefinition(CreateTaskHandler::class)->setArgument(0, $invoker),
                    default => $container->setDefinition('event.named.wrapper', $invoker),
                };
                break;
            case 'missing':
                $container->getDefinition($listener)->clearTag('messenger.message_handler');
                break;
            case 'omitted':
                $container->removeDefinition($listener);
                break;
            case 'duplicate':
                $container->setDefinition('event.listener.copy', clone $container->getDefinition($listener));
                break;
            case 'duplicate-tag':
                $container->getDefinition($listener)->addTag('messenger.message_handler', ['bus' => 'application.event.bus', 'priority' => 10]);
                break;
            case 'public-definition':
                $container->getDefinition($listener)->setPublic(true);
                break;
            case 'wrong-bus':
            case 'wildcard':
            case 'transport':
            case 'priority':
                $tag = match ($this->scenario) {
                    'wrong-bus' => ['bus' => 'command.bus', 'priority' => 10],
                    'wildcard' => ['bus' => 'application.event.bus', 'handles' => '*', 'priority' => 10],
                    'transport' => ['bus' => 'application.event.bus', 'from_transport' => 'async', 'priority' => 10],
                    default => ['bus' => 'application.event.bus', 'priority' => 99],
                };
                $container->getDefinition($listener)->clearTag('messenger.message_handler')->addTag('messenger.message_handler', $tag);
                break;
            case 'public-alias':
                $container->setAlias('event.listener.private', $listener);
                $container->setAlias('event.listener.public', 'event.listener.private')->setPublic(true);
                break;
            case 'factory':
                $container->getDefinition($listener)->setFactory([$listener, 'create']);
                break;
            case 'unshared':
                $container->getDefinition(EventDeliveryContext::class)->setShared(false);
                break;
            case 'recorder':
                $container->register('event.context.copy', InvocationContext::class);
                $container->getDefinition(ApplicationEventRecorder::class)->setArgument(0, new Reference('event.context.copy'));
                break;
            case 'dispatcher':
                $container->getDefinition(BestEffortEventDispatcher::class)->setArgument(1, new Reference('command.bus'));
                break;
            case 'policy':
                $container->register('event.delivery.copy', EventDeliveryContext::class);
                $container->getDefinition(EventPolicyMiddleware::class)->setArgument(0, new Reference('event.delivery.copy'));
                break;
            case 'scope':
                $bus = $container->getDefinition('application.event.bus');
                $middleware = $bus->getArgument(0);
                if (!$middleware instanceof IteratorArgument) {
                    throw new \LogicException('Expected the compiled event middleware iterator.');
                }
                $bus->setArgument(0, new IteratorArgument([new Reference('app.command_scope'), ...$middleware->getValues()]));
                break;
            case 'unhandled':
                $container->getDefinition('application.event.bus.middleware.handle_message')->setArgument(1, false);
                break;
            case 'extra-bus':
                $container->register('extra.bus', MessageBus::class)->setArguments([[]])->addTag('messenger.bus');
                $container->setParameter('extra.bus.middleware', []);
                break;
            case 'public-bus':
                $container->setAlias('event.bus.public', 'application.event.bus')->setPublic(true);
                break;
            case 'bus-constructor':
                $container->getDefinition('application.event.bus')->addMethodCall('__construct', [[]]);
                break;
            case 'recorder-constructor':
                $container->getDefinition(ApplicationEventRecorder::class)->addMethodCall('__construct', [new Reference(InvocationContext::class), []]);
                break;
            case 'locator-constructor':
                $container->getDefinition('application.event.bus.messenger.handlers_locator')->addMethodCall('__construct', [[]]);
                break;
            case 'stripped-map':
                $container->getDefinition('application.event.bus.messenger.handlers_locator')->setArgument(0, []);
                break;
            case 'descriptor-constructor':
            case 'map-wildcard':
            case 'map-duplicate':
                $mapping = $container->getDefinition('application.event.bus.messenger.handlers_locator')->getArgument(0);
                if (!is_array($mapping) || !($mapping[TaskCreatedEvent::class] ?? null) instanceof IteratorArgument) {
                    throw new \LogicException('Expected the compiled event handler map.');
                }
                $descriptor = $mapping[TaskCreatedEvent::class]->getValues()[0];
                if (!$descriptor instanceof Reference) {
                    throw new \LogicException('Expected the event handler descriptor reference.');
                }
                if ('map-wildcard' === $this->scenario) {
                    $mapping['*'] = $mapping[TaskCreatedEvent::class];
                    $container->getDefinition('application.event.bus.messenger.handlers_locator')->setArgument(0, $mapping);
                } elseif ('map-duplicate' === $this->scenario) {
                    $mapping[TaskCreatedEvent::class] = new IteratorArgument([$descriptor, $descriptor]);
                    $container->getDefinition('application.event.bus.messenger.handlers_locator')->setArgument(0, $mapping);
                } else {
                    $container->findDefinition((string) $descriptor)->addMethodCall('__construct', [new Reference($listener)]);
                }
                break;
        }
    }

    public function getCacheDir(): string
    {
        return $this->directory.'/cache';
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
