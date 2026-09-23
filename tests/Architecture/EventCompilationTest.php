<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Module\TaskTracking\Application\CreateTask\TaskCreatedEvent;
use App\Platform\Authorization\AuthorizationMiddleware;
use App\Platform\Authorization\ExecutionContext;
use App\Platform\Messaging\EventBus;
use App\Platform\Messaging\EventPolicyMiddleware;
use App\Tests\Fixtures\EventCompilation\CompilationKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class EventCompilationTest extends TestCase
{
    public function testNativeSyncAndDoctrineBusesCompileWithInstalledAndAdditionalOrdinaryListeners(): void
    {
        foreach (['zero', 'normal', 'async'] as $scenario) {
            CompilationKernel::run($scenario, static function (ContainerBuilder $container) use ($scenario): void {
                $container->addCompilerPass(new class($scenario) implements CompilerPassInterface {
                    public function __construct(private readonly string $scenario)
                    {
                    }

                    public function process(ContainerBuilder $container): void
                    {
                        $events = $container->findDefinition(EventPolicyMiddleware::class)->getArgument(0);
                        TestCase::assertIsArray($events);
                        TestCase::assertSame('event', $events[TaskCreatedEvent::class] ?? null);
                        $eventContext = $container->findDefinition(EventPolicyMiddleware::class)->getArgument(1);
                        $authorizationContext = $container->findDefinition(AuthorizationMiddleware::class)->getArgument(1);
                        TestCase::assertInstanceOf(Reference::class, $eventContext);
                        TestCase::assertInstanceOf(Reference::class, $authorizationContext);
                        TestCase::assertSame($container->findDefinition((string) $authorizationContext), $container->findDefinition((string) $eventContext));
                        TestCase::assertTrue($container->findDefinition((string) $eventContext)->isShared());
                        $bus = $container->findDefinition(EventBus::class)->getArgument(0);
                        TestCase::assertInstanceOf(Reference::class, $bus);
                        TestCase::assertSame('application.event.bus', (string) $bus);
                        $mapping = $container->findDefinition('application.event.bus.messenger.handlers_locator')->getArgument(0);
                        TestCase::assertIsArray($mapping);
                        // Installed business listeners coexist with this fixture's
                        // zero or two additional listeners.
                        $handlers = $mapping[TaskCreatedEvent::class] ?? new IteratorArgument([]);
                        TestCase::assertInstanceOf(IteratorArgument::class, $handlers);
                        $additional = [];
                        foreach ($handlers->getValues() as $reference) {
                            TestCase::assertInstanceOf(Reference::class, $reference);
                            $handler = $container->findDefinition((string) $reference)->getArgument(0);
                            TestCase::assertInstanceOf(Reference::class, $handler);
                            $class = $container->findDefinition((string) $handler)->getClass() ?? '';
                            TestCase::assertStringContainsString('\\Infrastructure\\EventListener\\', $class);
                            if (str_starts_with($class, 'App\\Module\\Event')) {
                                $additional[] = $class;
                            }
                        }
                        TestCase::assertCount('zero' === $this->scenario ? 0 : 2, $additional);
                    }
                }, PassConfig::TYPE_BEFORE_REMOVING, 5);
                $container->compile();
                self::assertTrue($container->isCompiled());
            });
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidExecutionContexts(): iterable
    {
        foreach (['event copy', 'authorization copy', 'unshared context', 'inline context', 'missing reference'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidExecutionContexts')]
    public function testEventsAndAuthorizationMustUseTheSameSharedExecutionContext(string $case): void
    {
        CompilationKernel::run('normal', static function (ContainerBuilder $container) use ($case): void {
            $container->addCompilerPass(new class($case) implements CompilerPassInterface {
                public function __construct(private readonly string $case)
                {
                }

                public function process(ContainerBuilder $container): void
                {
                    $context = $container->findDefinition(ExecutionContext::class);
                    $events = $container->findDefinition(EventPolicyMiddleware::class);
                    if ('unshared context' === $this->case) {
                        $context->setShared(false);
                    } elseif ('inline context' === $this->case) {
                        $events->setArgument(1, clone $context);
                    } elseif ('missing reference' === $this->case) {
                        $events->setArgument(1, null);
                    } else {
                        $container->setDefinition('execution.context.copy', clone $context);
                        $consumer = 'event copy' === $this->case ? $events : $container->findDefinition(AuthorizationMiddleware::class);
                        $consumer->setArgument(1, new Reference('execution.context.copy'));
                    }
                }
            }, PassConfig::TYPE_BEFORE_REMOVING, 20);
            try {
                $container->compile();
            } catch (\LogicException $failure) {
                self::assertStringStartsWith('cqrs.wiring:', $failure->getMessage());

                return;
            }
            self::fail('Expected event/authorization context wiring to be rejected.');
        });
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidWiring(): iterable
    {
        foreach (['missing', 'wrong-bus', 'wildcard', 'transport', 'attribute', 'method-attribute'] as $scenario) {
            yield $scenario => [$scenario, 'registration'];
        }
        foreach (['omitted', 'duplicate', 'stripped-map'] as $scenario) {
            yield $scenario => [$scenario, 'listener_inventory'];
        }
        foreach (['facade-context', 'facade-bus', 'unhandled'] as $scenario) {
            yield $scenario => [$scenario, 'wiring'];
        }
        foreach (['public-alias', 'public-definition', 'public-bus'] as $scenario) {
            yield $scenario => [$scenario, 'handler_visibility'];
        }
        yield 'factory listener' => ['factory', 'handler'];
        foreach (['return', 'union', 'category'] as $scenario) {
            yield $scenario => [$scenario, 'signature'];
        }
        foreach (['scope', 'policy'] as $scenario) {
            yield $scenario => [$scenario, 'middleware'];
        }
        foreach (['routing', 'transport-dsn', 'queue-table'] as $scenario) {
            yield $scenario => [$scenario, 'transport'];
        }
    }

    #[DataProvider('invalidWiring')]
    public function testActualMessengerCompilationRejectsEventBoundaryViolations(string $scenario, string $rule): void
    {
        CompilationKernel::run($scenario, static function (ContainerBuilder $container) use ($rule): void {
            try {
                $container->compile();
            } catch (\LogicException $failure) {
                self::assertStringStartsWith('cqrs.'.$rule.':', $failure->getMessage());

                return;
            }
            self::fail('Expected event compilation to reject the wiring.');
        });
    }
}
