<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Module\TaskTracking\Application\CreateTask\TaskCreatedEvent;
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
    public function testNativeSyncAndDoctrineBusesCompileWithZeroOrMultipleOrdinaryListeners(): void
    {
        foreach (['zero', 'normal', 'async'] as $scenario) {
            CompilationKernel::run($scenario, static function (ContainerBuilder $container) use ($scenario): void {
                $container->addCompilerPass(new class($scenario) implements CompilerPassInterface {
                    public function __construct(private readonly string $scenario)
                    {
                    }

                    public function process(ContainerBuilder $container): void
                    {
                        TestCase::assertSame([TaskCreatedEvent::class => 'event'], $container->findDefinition(EventPolicyMiddleware::class)->getArgument(0));
                        $bus = $container->findDefinition(EventBus::class)->getArgument(0);
                        TestCase::assertInstanceOf(Reference::class, $bus);
                        TestCase::assertSame('application.event.bus', (string) $bus);
                        $mapping = $container->findDefinition('application.event.bus.messenger.handlers_locator')->getArgument(0);
                        TestCase::assertIsArray($mapping);
                        if ('zero' === $this->scenario) {
                            TestCase::assertArrayNotHasKey(TaskCreatedEvent::class, $mapping);

                            return;
                        }
                        $handlers = $mapping[TaskCreatedEvent::class];
                        TestCase::assertInstanceOf(IteratorArgument::class, $handlers);
                        TestCase::assertCount(2, $handlers->getValues());
                        foreach ($handlers->getValues() as $reference) {
                            TestCase::assertInstanceOf(Reference::class, $reference);
                            $handler = $container->findDefinition((string) $reference)->getArgument(0);
                            TestCase::assertInstanceOf(Reference::class, $handler);
                            TestCase::assertStringContainsString('\\Infrastructure\\EventListener\\', $container->findDefinition((string) $handler)->getClass() ?? '');
                        }
                    }
                }, PassConfig::TYPE_BEFORE_REMOVING, 5);
                $container->compile();
                self::assertTrue($container->isCompiled());
            });
        }
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
