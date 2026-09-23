<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Module\DiConsuming\Application\Consumer;
use App\Module\DiConsuming\Application\Lookup\LookupHandler;
use App\Module\DiConsuming\Domain\RepositoryPort;
use App\Module\DiConsuming\Infrastructure\Framework\Symfony\Security\DiConsumingVoter;
use App\Module\DiConsuming\Infrastructure\RepositoryAdapter;
use App\Platform\Architecture\ContractTypes;
use App\Platform\Architecture\ModuleInventoryPass;
use App\Platform\Architecture\ModuleMap;
use App\Platform\Architecture\ModuleServicesPass;
use App\Platform\Authorization\OperatorExecution;
use App\Platform\Authorization\PublicAccessVoter;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\EventBus;
use App\Platform\Messaging\QueryBus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\MessageBus;

require_once __DIR__.'/../Fixtures/Services/ModuleServices.php';

final class AuthorizationServicesPassTest extends TestCase
{
    public function testVoterRetainsQueryBusAndItsOwnDomainPortAfterAutowiringAndAliasResolution(): void
    {
        $container = new ContainerBuilder();
        $container->addCompilerPass(new ModuleServicesPass(), PassConfig::TYPE_BEFORE_REMOVING);
        $container->register('repository', RepositoryAdapter::class);
        $container->setAlias(RepositoryPort::class, 'repository');
        $container->register('query.bus', MessageBus::class)->setArgument(0, []);
        $container->register(QueryBus::class)->setArgument(0, new Reference('query.bus'));
        $container->register('voter', DiConsumingVoter::class)->setAutowired(true)->setArgument(0, new Reference(QueryBus::class));
        $container->register('test.root', \stdClass::class)->setPublic(true)->setProperty('voter', new Reference('voter'));
        $container->compile();

        $root = $container->get('test.root');
        self::assertInstanceOf(\stdClass::class, $root);
        self::assertInstanceOf(DiConsumingVoter::class, $root->voter);
        self::assertInstanceOf(QueryBus::class, $root->voter->queries);
        self::assertInstanceOf(RepositoryPort::class, $root->voter->repository);
        self::assertSame('bound-domain-port', $root->voter->repository->label());
        self::assertFalse($container->has('voter'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function voterDependencies(): iterable
    {
        foreach ([CommandBus::class, EventBus::class] as $class) {
            yield $class => [$class, 'module.services.platform:'];
        }
        foreach ([Consumer::class, RepositoryAdapter::class, \stdClass::class, \Doctrine\DBAL\Connection::class, \Symfony\Component\HttpFoundation\RequestStack::class, ServiceLocator::class] as $class) {
            yield $class => [$class, 'module.services.voter_dependency:'];
        }
        yield 'handler proxy' => [LookupHandler::class, 'module.services.handler_target:'];
        yield 'another voter' => [DiConsumingVoter::class, 'module.services.voter_target:'];
    }

    #[DataProvider('voterDependencies')]
    public function testVoterCannotInjectAServiceProxyOrRuntimeDependency(string $target, string $diagnostic): void
    {
        $container = new ContainerBuilder();
        $container->register('voter', DiConsumingVoter::class)->setArgument(0, new Reference('friendly.alias'));
        $id = in_array($target, [CommandBus::class, EventBus::class], true) ? $target : 'target';
        $container->register($id, $target);
        $container->setAlias('friendly.alias', $id);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($diagnostic);
        new ModuleServicesPass()->process($container);
    }

    /** @return iterable<string, array{string, string}> */
    public static function directUseCaseWiring(): iterable
    {
        foreach ([LookupHandler::class, DiConsumingVoter::class, PublicAccessVoter::class] as $class) {
            foreach (['alias', 'closure', 'locator', 'inline', 'factory', 'property', 'method'] as $wiring) {
                yield $class.' '.$wiring => [$class, $wiring];
            }
        }
    }

    #[DataProvider('directUseCaseWiring')]
    public function testModuleCannotInjectHandlersOrVotersThroughAlternateWiring(string $target, string $wiring): void
    {
        $container = new ContainerBuilder();
        $container->register('target', $target);
        $container->setAlias('friendly.alias', 'target');
        $reference = new Reference('friendly.alias');
        $consumer = $container->register('consumer', Consumer::class);
        match ($wiring) {
            'closure' => $consumer->setArgument(0, new ServiceClosureArgument($reference)),
            'locator' => $consumer->setArgument(0, ServiceLocatorTagPass::register($container, ['target' => $reference])),
            'inline' => $consumer->setArgument(0, new Definition($target)),
            'factory' => $consumer->setFactory([$reference, 'create']),
            'property' => $consumer->setProperty('configured', $reference),
            'method' => $consumer->addMethodCall('setDependency', [$reference]),
            default => $consumer->setArgument(0, $reference),
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(LookupHandler::class === $target ? 'module.services.handler_target:' : 'module.services.voter_target:');
        new ModuleServicesPass()->process($container);
    }

    public function testNativeMessengerHandlerDescriptorRetainsItsWiringException(): void
    {
        $container = new ContainerBuilder();
        $container->register('handler', LookupHandler::class)->addTag('messenger.message_handler');
        $container->register('.messenger.handler_descriptor.fixture', HandlerDescriptor::class)->setArgument(0, new Reference('handler'));
        new ModuleServicesPass()->process($container);
        $reference = $container->getDefinition('.messenger.handler_descriptor.fixture')->getArgument(0);
        self::assertInstanceOf(Reference::class, $reference);
        self::assertSame('handler', (string) $reference);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function authorizationDataRegistrations(): iterable
    {
        foreach (ContractTypes::authorizationData() as $class) {
            foreach (['named', 'inline', 'abstract', 'concrete excluded', 'case alias'] as $wiring) {
                foreach ([true, false] as $early) {
                    yield $class.' '.$wiring.($early ? ' early' : ' late') => [$class, $wiring, $early];
                }
            }
        }
    }

    #[DataProvider('authorizationDataRegistrations')]
    public function testAuthorizationDataCannotBeRegisteredAsServices(string $class, string $wiring, bool $early): void
    {
        self::assertTrue(class_exists($class));
        $container = new ContainerBuilder();
        $definition = new Definition('case alias' === $wiring ? '\\'.strtolower($class) : $class);
        if ('inline' === $wiring) {
            $container->register('vendor.root', \stdClass::class)->setProperty('data', new ServiceClosureArgument($definition));
        } else {
            $container->setDefinition('data', $definition);
            if ('abstract' === $wiring) {
                $definition->setAbstract(true);
            } elseif ('concrete excluded' === $wiring) {
                $definition->addTag('container.excluded');
            } elseif ('case alias' === $wiring) {
                $container->setAlias('alias', 'data');
            }
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($early ? 'module.inventory.data:' : 'module.services.data:');
        if ($early) {
            new ModuleInventoryPass(new ModuleMap('/tmp/unused-authorization-inventory'))->process($container);
        } else {
            new ModuleServicesPass()->process($container);
        }
    }

    public function testExactAuthorizationDataPlaceholdersAreExcludedAndNeverPublicData(): void
    {
        $container = new ContainerBuilder();
        foreach (ContractTypes::authorizationData() as $class) {
            $container->register($class)->setAbstract(true)->addTag('container.excluded');
            self::assertFalse(ContractTypes::isPublic($class));
            self::assertNull(ContractTypes::messageKind($class));
            self::assertFalse(ContractTypes::isAuthorizationData($class.'Child'));
        }
        new ModuleInventoryPass(new ModuleMap('/tmp/unused-authorization-inventory'))->process($container);
        new ModuleServicesPass()->process($container);
    }

    public function testTrustedExecutionFacadeEdgesAreExact(): void
    {
        foreach (['ListTasksConsoleCommand', 'CompleteTaskConsoleCommand'] as $name) {
            $consumer = 'App\\Module\\TaskTracking\\UI\\Console\\'.$name;
            self::assertTrue(ContractTypes::mayUseExecutionFacade($consumer, OperatorExecution::class));
            self::assertFalse(ContractTypes::mayUseExecutionFacade($consumer.'Child', OperatorExecution::class));
            self::assertFalse(ContractTypes::mayUseExecutionFacade(str_replace('Console', 'Http', $consumer), OperatorExecution::class));
            self::assertFalse(ContractTypes::mayUseExecutionFacade($consumer, \App\Platform\Authorization\AuthenticationExecution::class));
        }
        $container = new ContainerBuilder();
        foreach (ContractTypes::executionFacadeConsumers() as $facade => $consumers) {
            $container->register($facade);
            foreach ($consumers as $consumer) {
                $container->register($consumer)->setArgument(0, new Reference($facade));
            }
        }
        new ModuleServicesPass()->process($container);
        self::assertCount(2, ContractTypes::executionFacadeConsumers());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFacadeWiring(): iterable
    {
        foreach (['untrusted consumer', 'noncanonical id', 'public service', 'public alias', 'private context public alias', 'inline facade'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidFacadeWiring')]
    public function testExecutionFacadeAuthorityCannotBeReexported(string $case): void
    {
        $container = new ContainerBuilder();
        $id = 'noncanonical id' === $case ? 'duplicate.facade' : OperatorExecution::class;
        $definition = $container->register($id, OperatorExecution::class);
        $diagnostic = 'module.services.authorization_private:';
        if ('public service' === $case) {
            $definition->setPublic(true);
        } elseif ('public alias' === $case) {
            $container->setAlias('first.alias', $id);
            $container->setAlias('public.alias', 'first.alias')->setPublic(true);
        } elseif ('private context public alias' === $case) {
            $container->register(\App\Platform\Authorization\ExecutionContext::class, \App\Platform\Authorization\ExecutionContext::class);
            $container->setAlias('public.context', \App\Platform\Authorization\ExecutionContext::class)->setPublic(true);
        } elseif ('inline facade' === $case) {
            $container->register('consumer', \App\Module\TaskTracking\UI\Console\CreateTaskConsoleCommand::class)->setArgument(0, new Definition(OperatorExecution::class));
            $diagnostic = 'module.services.authorization_id:';
        } elseif ('untrusted consumer' === $case) {
            $container->register('consumer', Consumer::class)->setArgument(0, new Reference($id));
            $diagnostic = 'module.services.platform:';
        } else {
            $diagnostic = 'module.services.authorization_id:';
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($diagnostic);
        new ModuleServicesPass()->process($container);
    }
}
