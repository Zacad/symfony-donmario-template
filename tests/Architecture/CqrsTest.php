<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Application\CreateTask\TaskCreatedEvent;
use App\Module\TaskTracking\Application\GetTask\GetTaskQuery;
use App\Platform\Authorization\AuthorizationMiddleware;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\QueryBus;
use App\Tests\Fixtures\Collections\CollectionsFixture;
use App\Tests\Fixtures\Cqrs\CompilationKernel;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
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
    public function testDisposableCollectionModuleUsesRealCompiledBusesAndNativeValidation(): void
    {
        $fixture = new CollectionsFixture();
        try {
            $fixture->initialize();
            self::assertSame([], $fixture->sourceViolations());
            $process = $fixture->process(['offline.php']);
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            self::assertSame(['invalid_inputs' => 14, 'nonservices' => true, 'values' => true, 'query_recovered' => true], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
            $deptrac = $fixture->process([\dirname(__DIR__, 2).'/vendor/bin/deptrac', 'analyse', '--config-file='.$fixture->projectDir.'/deptrac.php', '--no-progress', '--report-uncovered', '--fail-on-uncovered']);
            $deptrac->run();
            self::assertTrue($deptrac->isSuccessful(), $deptrac->getOutput().$deptrac->getErrorOutput());
        } finally {
            $fixture->remove();
        }
    }

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
        yield 'input result is not a top-level result' => ['input-result', 'signature'];
        yield 'raw array result needs a named envelope' => ['array-result', 'signature'];
        yield 'array-bearing Command is not a result envelope' => ['collection-command-result', 'signature'];
        yield 'array-bearing Query is not a result envelope' => ['collection-query-result', 'signature'];
        yield 'Command wrapping a collection Result still needs a Result name' => ['wrapped-command-result', 'signature'];
        yield 'Query wrapping Input and nullable collection Result needs a Result name' => ['wrapped-query-result', 'signature'];
        yield 'valid union members do not excuse a collection-bearing Command member' => ['collection-union-result', 'signature'];
        yield 'union argument' => ['union', 'signature'];
        yield 'missing validation middleware' => ['missing-validation', 'middleware'];
        yield 'missing command result validation' => ['missing-command-result-validation', 'middleware'];
        yield 'missing query result validation' => ['missing-query-result-validation', 'middleware'];
        yield 'result validation outside transaction' => ['result-before-transaction', 'wiring'];
        yield 'result validation before input' => ['result-before-input', 'wiring'];
        yield 'result validation after terminal handler' => ['result-after-handler', 'wiring'];
        yield 'result validation wrong validator' => ['result-validator', 'wiring'];
        yield 'result middleware constructor reinitialization' => ['result-constructor', 'wiring'];
        yield 'miswired facade' => ['facade-bypass', 'wiring'];
        yield 'unshared invocation context' => ['unshared-context', 'wiring'];
        yield 'transaction must use the invocation context' => ['transaction-context', 'wiring'];
        yield 'manager must use the default connection' => ['manager-connection', 'wiring'];
        yield 'registry must select the default connection' => ['default-connection', 'wiring'];
        yield 'connection must preserve owned transactions' => ['connection-autocommit', 'wiring'];
        yield 'bus constructor reinitialization' => ['bus-constructor', 'wiring'];
        yield 'locator constructor reinitialization' => ['locator-constructor', 'wiring'];
        yield 'descriptor constructor reinitialization' => ['descriptor-constructor', 'wiring'];
        yield 'public handler definition' => ['public-handler-definition', 'handler_visibility'];
        yield 'public handler alias' => ['public-handler', 'handler_visibility'];
        yield 'public handler alias chain' => ['public-handler-chain', 'handler_visibility'];
        yield 'public alias to an untagged handler copy' => ['public-handler-copy', 'handler_visibility'];
        foreach (['missing', 'duplicate', 'method', 'policy-missing', 'policy-mutable', 'policy-nonfinal', 'policy-foreign', 'policy-message', 'policy-context', 'policy-return', 'policy-optional', 'policy-variadic', 'policy-reference', 'policy-public', 'policy-alias', 'policy-copy', 'data-service', 'locator-map', 'locator-public', 'locator-alias'] as $scenario) {
            yield 'authorization '.$scenario => ['authorization-'.$scenario, 'authorization'];
        }
        foreach (['context', 'policy-factory', 'locator-factory', 'after-result'] as $scenario) {
            yield 'authorization '.$scenario => ['authorization-'.$scenario, 'wiring'];
        }
        yield 'missing command authorization' => ['authorization-missing-command', 'middleware'];
        yield 'missing query authorization' => ['authorization-missing-query', 'middleware'];
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

    public function testCompilationPreservesNoncollectionReturnsAndNamedCollectionResults(): void
    {
        foreach (['accepted-data-result', 'void-result'] as $scenario) {
            CompilationKernel::run($scenario, static function (ContainerBuilder $container): void {
                $container->compile();
                self::assertTrue($container->isCompiled());
            });
        }
    }

    public function testCompilationBuildsExactLazyPolicyMap(): void
    {
        CompilationKernel::run('authorization-valid', static function (ContainerBuilder $container): void {
            $inspection = new class implements CompilerPassInterface {
                public bool $inspected = false;

                public function process(ContainerBuilder $container): void
                {
                    $reference = $container->getDefinition(AuthorizationMiddleware::class)->getArgument(2);
                    TestCase::assertInstanceOf(Reference::class, $reference);
                    $locator = $container->findDefinition((string) $reference);
                    TestCase::assertSame(ServiceLocator::class, $locator->getClass());
                    TestCase::assertFalse($locator->isPublic());
                    TestCase::assertNull($locator->getFactory());
                    $map = $locator->getArgument(0);
                    TestCase::assertIsArray($map);
                    $inventory = $container->getDefinition('app.command_policy')->getArgument(1);
                    TestCase::assertIsArray($inventory);
                    $expected = array_keys(array_filter($inventory, static fn (mixed $kind): bool => 'event' !== $kind));
                    sort($expected);
                    TestCase::assertSame($expected, array_keys($map));
                    TestCase::assertArrayHasKey(CreateTaskCommand::class, $map);
                    TestCase::assertArrayHasKey(GetTaskQuery::class, $map);
                    TestCase::assertArrayNotHasKey(TaskCreatedEvent::class, $map);
                    foreach ($map as $message => $closure) {
                        TestCase::assertInstanceOf(ServiceClosureArgument::class, $closure);
                        $policy = $closure->getValues()[0];
                        TestCase::assertInstanceOf(Reference::class, $policy);
                        $definition = $container->findDefinition((string) $policy);
                        TestCase::assertFalse($definition->isPublic());
                        $invoke = new \ReflectionMethod($definition->getClass() ?? '', '__invoke');
                        TestCase::assertSame($message, (string) $invoke->getParameters()[0]->getType());
                    }
                    $this->inspected = true;
                }
            };
            $container->addCompilerPass($inspection, PassConfig::TYPE_BEFORE_REMOVING, 5);
            $container->compile();
            self::assertTrue($inspection->inspected);
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
