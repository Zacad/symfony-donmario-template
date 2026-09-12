<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Application\CreateTask\TaskCreatedEvent;
use App\Module\TaskTracking\Application\GetTask\GetTaskQuery;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\QueryBus;
use App\Tests\Fixtures\Cqrs\CompilationKernel;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ValidationStamp;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CqrsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function invalidWiring(): iterable
    {
        yield 'missing handler' => ['missing', 'handler_count'];
        yield 'duplicate handler' => ['duplicate', 'handler_count'];
        yield 'wrong bus' => ['wrong-bus', 'bus'];
        yield 'implicit bus' => ['implicit-bus', 'registration'];
        yield 'wildcard' => ['wildcard', 'message'];
        yield 'transport handler' => ['transport', 'registration'];
        yield 'foreign handler' => ['foreign', 'handler'];
        yield 'entity result' => ['entity-result', 'signature'];
        yield 'union argument' => ['union', 'signature'];
        yield 'missing validation middleware' => ['missing-validation', 'middleware'];
        yield 'miswired facade' => ['facade-bypass', 'wiring'];
        yield 'unshared invocation context' => ['unshared-context', 'wiring'];
        yield 'bus constructor reinitialization' => ['bus-constructor', 'wiring'];
        yield 'locator constructor reinitialization' => ['locator-constructor', 'wiring'];
        yield 'descriptor constructor reinitialization' => ['descriptor-constructor', 'wiring'];
        yield 'public handler definition' => ['public-handler-definition', 'handler_visibility'];
        yield 'public handler alias' => ['public-handler', 'handler_visibility'];
        yield 'public handler alias chain' => ['public-handler-chain', 'handler_visibility'];
        yield 'public alias to an untagged handler copy' => ['public-handler-copy', 'handler_visibility'];
    }

    #[DataProvider('invalidWiring')]
    public function testRealMessengerCompilationRejectsInvalidWiring(string $scenario, string $rule): void
    {
        CompilationKernel::run($scenario, static function (ContainerBuilder $container) use ($rule): void {
            try {
                $container->compile();
            } catch (\LogicException $failure) {
                self::assertStringStartsWith('cqrs.'.$rule.':', $failure->getMessage());

                return;
            }
            self::fail('Expected CQRS compilation failure.');
        });
    }

    public function testCompiledBusesRejectInvalidInputAndStampsWithoutDatabaseAccess(): void
    {
        CompilationKernel::withBooted('normal', static function (ContainerInterface $container): void {
            $commands = $container->get('test.cqrs.commands');
            $queries = $container->get('test.cqrs.queries');
            self::assertInstanceOf(CommandBus::class, $commands);
            self::assertInstanceOf(QueryBus::class, $queries);
            foreach ([new CreateTaskCommand(''), new CreateTaskCommand("\xff")] as $command) {
                try {
                    $commands->dispatch($command);
                    self::fail('Expected validation before database access.');
                } catch (ValidationFailedException $failure) {
                    $violation = $failure->getViolations()[0];
                    self::assertNotNull($violation);
                    self::assertSame('title', $violation->getPropertyPath());
                }
            }
            foreach ([new \stdClass(), new TaskCreatedEvent(Uuid::v7()), new GetTaskQuery('invalid'), new Envelope(new CreateTaskCommand(''), [new ValidationStamp(['bypass'])]), new Envelope(new CreateTaskCommand('valid'), [new HandledStamp('forged', 'fake')]), new RedispatchMessage(new CreateTaskCommand('valid'))] as $message) {
                try {
                    $commands->dispatch($message);
                    self::fail('Expected message policy rejection.');
                } catch (\LogicException $failure) {
                    self::assertStringStartsWith('cqrs.message:', $failure->getMessage());
                }
            }
            foreach ([new CreateTaskCommand('valid'), new TaskCreatedEvent(Uuid::v7())] as $message) {
                try {
                    $queries->ask($message);
                    self::fail('The query bus must reject commands and events.');
                } catch (\LogicException $failure) {
                    self::assertStringStartsWith('cqrs.message:', $failure->getMessage());
                }
            }
            $doctrine = $container->get('doctrine');
            self::assertInstanceOf(ManagerRegistry::class, $doctrine);
            $connection = $doctrine->getConnection();
            self::assertInstanceOf(Connection::class, $connection);
            self::assertFalse($connection->isConnected());
        });
    }

    public function testModuleMappingIsNecessaryAndActuallyLoaded(): void
    {
        foreach (['normal' => 1, 'missing-mapping' => 0] as $scenario => $expected) {
            CompilationKernel::withBooted($scenario, static function (ContainerInterface $container) use ($expected): void {
                $validator = $container->get('test.cqrs.validator');
                self::assertInstanceOf(ValidatorInterface::class, $validator);
                self::assertCount($expected, $validator->validate(new CreateTaskCommand('')));
            });
        }
    }

    public function testDemoRoutesAreAbsentFromProduction(): void
    {
        $kernel = new CompilationKernel('normal', 'prod');
        try {
            $kernel->boot();
            $router = $kernel->getContainer()->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);
            self::assertNull($router->getRouteCollection()->get('demo_task_create'));
            self::assertNull($router->getRouteCollection()->get('demo_task_show'));
            self::assertNotNull($router->getRouteCollection()->get('home'));
        } finally {
            $kernel->cleanup();
        }
    }
}
