<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\EventCompilation;

use App\Kernel;
use App\Platform\Architecture\CqrsPass;
use App\Platform\Architecture\ModuleMap;
use App\Platform\Architecture\ModuleServicesPass;
use App\Platform\Messaging\EventBus;
use App\Platform\Messaging\EventPolicyMiddleware;
use App\Platform\Messaging\InvocationContext;
use Composer\Autoload\ClassLoader;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;

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

    private function fixtures(): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory.'/src/Module');
        // The real kernel imports every installed module's services. Its message
        // inventory must cover those modules alongside our synthetic listeners.
        $modules = new ModuleMap($this->getProjectDir());
        foreach ($modules->modules() as $module) {
            $filesystem->symlink($modules->path($module), $this->directory.'/src/Module/'.$module);
        }
        if ('zero' === $this->scenario) {
            return;
        }
        $root = $this->directory.'/src/Module/'.$this->module;
        $this->loader->addPsr4('App\\Module\\'.$this->module.'\\', $root);
        $this->loader->register(true);
        foreach (['FirstListener', 'SecondListener'] as $name) {
            $source = str_replace(['EventCompiling', 'FirstListener'], [$this->module, $name], $filesystem->readFile(__DIR__.'/Listener.php.fixture'));
            if ('FirstListener' === $name) {
                $source = match ($this->scenario) {
                    'return' => str_replace('): void', '): ?string', $source),
                    'union' => str_replace('TaskCreatedEvent $event', 'TaskCreatedEvent|\\stdClass $event', $source),
                    'category' => str_replace('TaskCreatedEvent $event', '\\App\\Platform\\Event\\ApplicationEvent $event', $source),
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
    }

    protected function build(ContainerBuilder $container): void
    {
        foreach ($this->listeners as $listener) {
            $container->register($listener, $listener)->setAutowired(true)->setAutoconfigured(true);
        }
        $late = in_array($this->scenario, ['stripped-map', 'policy', 'scope', 'unhandled', 'routing', 'transport-dsn', 'async', 'queue-table'], true);
        $container->addCompilerPass(new class($this) implements CompilerPassInterface {
            public function __construct(private readonly CompilationKernel $kernel)
            {
            }

            public function process(ContainerBuilder $container): void
            {
                $this->kernel->alter($container);
            }
        }, $late ? PassConfig::TYPE_BEFORE_REMOVING : PassConfig::TYPE_BEFORE_OPTIMIZATION, 20);
        $container->addCompilerPass(new CqrsPass(new ModuleMap($this->directory)), PassConfig::TYPE_BEFORE_REMOVING, 10);
        $container->addCompilerPass(new ModuleServicesPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    public function alter(ContainerBuilder $container): void
    {
        $listener = $this->listeners[0] ?? '';
        switch ($this->scenario) {
            case 'missing':
                $container->getDefinition($listener)->clearTag('messenger.message_handler');
                break;
            case 'omitted':
                $container->removeDefinition($listener);
                break;
            case 'duplicate':
                $container->setDefinition('event.listener.copy', clone $container->getDefinition($listener));
                break;
            case 'public-definition':
                $container->getDefinition($listener)->setPublic(true);
                break;
            case 'wrong-bus':
            case 'wildcard':
            case 'transport':
                $tag = match ($this->scenario) {
                    'wrong-bus' => ['bus' => 'command.bus', 'priority' => 10],
                    'wildcard' => ['bus' => 'application.event.bus', 'handles' => '*', 'priority' => 10],
                    default => ['bus' => 'application.event.bus', 'from_transport' => 'events', 'priority' => 10],
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
            case 'facade-context':
                $container->register('event.context.copy', InvocationContext::class);
                $container->getDefinition(EventBus::class)->setArgument(1, new Reference('event.context.copy'));
                break;
            case 'facade-bus':
                $container->getDefinition(EventBus::class)->setArgument(0, new Reference('command.bus'));
                break;
            case 'policy':
            case 'scope':
                $bus = $container->getDefinition('application.event.bus');
                $middleware = $bus->getArgument(0);
                if (!$middleware instanceof IteratorArgument) {
                    throw new \LogicException('Expected the compiled event middleware iterator.');
                }
                $values = array_values(array_filter($middleware->getValues(), static fn (mixed $value): bool => !$value instanceof Reference || EventPolicyMiddleware::class !== (string) $value));
                $values[] = new Reference(EventPolicyMiddleware::class);
                if ('scope' === $this->scenario) {
                    $values = [new Reference('app.command_scope'), ...$middleware->getValues()];
                }
                $bus->setArgument(0, new IteratorArgument($values));
                break;
            case 'unhandled':
                $container->getDefinition('application.event.bus.middleware.handle_message')->setArgument(1, false);
                break;
            case 'public-bus':
                $container->setAlias('event.bus.public', 'application.event.bus')->setPublic(true);
                break;
            case 'stripped-map':
                $container->getDefinition('application.event.bus.messenger.handlers_locator')->setArgument(0, []);
                break;
            case 'routing':
                $container->getDefinition('messenger.senders_locator')->setArgument(0, ['*' => ['events']]);
                break;
            case 'async':
            case 'transport-dsn':
                $container->getDefinition('messenger.transport.events')->setArgument(0, 'async' === $this->scenario ? 'doctrine://default' : 'in-memory://');
                break;
            case 'queue-table':
                $transport = $container->getDefinition('messenger.transport.events');
                $options = $transport->getArgument(1);
                if (!is_array($options)) {
                    throw new \LogicException('Expected native transport options.');
                }
                $options['table_name'] = 'other_messages';
                $transport->setArgument(1, $options);
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
