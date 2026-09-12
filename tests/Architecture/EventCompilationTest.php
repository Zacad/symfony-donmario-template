<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Module\TaskTracking\Application\CreateTask\TaskCreatedEvent;
use App\Platform\Messaging\BestEffortEventDispatcher;
use App\Tests\Fixtures\EventCompilation\CompilationKernel;
use App\Tests\Fixtures\EventCompilation\RuntimeEvidence;
use App\Tests\Fixtures\EventCompilation\RuntimeLog;
use App\Tests\Fixtures\EventCompilation\RuntimeRegistryFactory;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Uid\Uuid;

final class EventCompilationTest extends TestCase
{
    public function testZeroAndMultipleInventoriedListenersCompileWithTheProductionBusStacks(): void
    {
        foreach (['zero', 'normal'] as $scenario) {
            CompilationKernel::run($scenario, static function (ContainerBuilder $container): void {
                $container->compile();
                self::assertTrue($container->isCompiled());
            });
        }
    }

    public function testConstructorFailureIsCaughtPerListenerAndLaterListenersRunIndependentCommandRoots(): void
    {
        RuntimeEvidence::$actions = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $transaction = 0;
        $manager->expects(self::exactly(2))->method('wrapInTransaction')->willReturnCallback(static function (callable $handler) use (&$transaction): Envelope {
            $root = ++$transaction;
            RuntimeEvidence::$actions[] = 'begin-'.$root;
            $result = $handler();
            self::assertInstanceOf(Envelope::class, $result);
            RuntimeEvidence::$actions[] = 'commit-'.$root;

            return $result;
        });
        $registry = $this->createMock(Registry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->expects(self::exactly(2))->method('resetManager')->willReturnCallback(static function () use ($manager): ObjectManager {
            RuntimeEvidence::$actions[] = 'reset';

            return $manager;
        });
        // The compiled production buses and coordinator run normally; only
        // the database boundary is doubled in this offline regression test.
        RuntimeRegistryFactory::$registry = $registry;
        try {
            CompilationKernel::withBooted(static function (ContainerInterface $container, array $listeners): void {
                $dispatcher = $container->get('event.test.dispatcher');
                self::assertInstanceOf(BestEffortEventDispatcher::class, $dispatcher);
                $dispatcher->deliver([new TaskCreatedEvent(Uuid::v7())]);
                self::assertSame(['begin-1', 'command-A', 'commit-1', 'reset', 'construct-B', 'begin-2', 'command-C', 'commit-2', 'reset'], RuntimeEvidence::$actions);
                $logger = $container->get('event.test.logger');
                self::assertInstanceOf(RuntimeLog::class, $logger);
                self::assertSame([['event.delivery_failed', [
                    'event_class' => TaskCreatedEvent::class,
                    'listener' => $listeners[1].'::__invoke',
                    'exception_class' => \RuntimeException::class,
                ]]], array_values(array_filter($logger->entries, static fn (array $entry): bool => 'event.delivery_failed' === $entry[0])));
            });
        } finally {
            RuntimeRegistryFactory::$registry = null;
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidWrappers(): iterable
    {
        foreach (['wrapper-target', 'wrapper-method', 'wrapper-public', 'wrapper-named', 'wrapper-other-consumer', 'wrapper-module-consumer', 'wrapper-copy-target'] as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    #[DataProvider('invalidWrappers')]
    public function testDeferredWrapperExceptionDoesNotPermitArbitraryPlatformToModuleEdges(string $scenario): void
    {
        CompilationKernel::run($scenario, static function (ContainerBuilder $container) use ($scenario): void {
            try {
                $container->compile();
            } catch (\LogicException $failure) {
                self::assertStringStartsWith('wrapper-module-consumer' === $scenario ? 'module.services.platform:' : 'module.services.event_invoker:', $failure->getMessage());

                return;
            }
            self::fail('Expected rejection of an altered or exposed deferred wrapper.');
        });
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidWiring(): iterable
    {
        foreach (['missing', 'wrong-bus', 'wildcard', 'transport', 'priority', 'attribute', 'method-attribute', 'duplicate-tag', 'map-duplicate'] as $scenario) {
            yield $scenario => [$scenario, 'registration'];
        }
        foreach (['omitted', 'duplicate', 'stripped-map'] as $scenario) {
            yield $scenario => [$scenario, 'listener_inventory'];
        }
        foreach (['unshared', 'recorder', 'dispatcher', 'policy', 'unhandled', 'public-bus', 'bus-constructor', 'recorder-constructor', 'locator-constructor', 'descriptor-constructor'] as $scenario) {
            yield $scenario => [$scenario, 'wiring'];
        }
        yield 'public alias chain' => ['public-alias', 'handler_visibility'];
        yield 'public definition' => ['public-definition', 'handler_visibility'];
        yield 'factory listener' => ['factory', 'handler'];
        foreach (['return', 'union', 'interface', 'category', 'batch'] as $scenario) {
            yield $scenario => [$scenario, 'signature'];
        }
        yield 'effective wildcard' => ['map-wildcard', 'message'];
        yield 'event invocation scope' => ['scope', 'middleware'];
        yield 'extra bus' => ['extra-bus', 'bus'];
    }

    #[DataProvider('invalidWiring')]
    public function testActualMessengerCompilationRejectsEventWiringByRule(string $scenario, string $rule): void
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
