<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Module\DiConsuming\Application\AutowiredConsumer;
use App\Module\DiConsuming\Application\ComplexDefaultsConsumer;
use App\Module\DiConsuming\Application\ConfiguredRepositoryConsumer;
use App\Module\DiConsuming\Application\Consumer;
use App\Module\DiConsuming\Application\Factory;
use App\Module\DiConsuming\Application\ForeignAutowiredConsumer;
use App\Module\DiConsuming\Application\ForeignSubscriber;
use App\Module\DiConsuming\Application\Lookup\LookupCommand;
use App\Module\DiConsuming\Application\Lookup\LookupQuery;
use App\Module\DiConsuming\Application\Lookup\LookupResult;
use App\Module\DiConsuming\Application\RepositoryConsumer;
use App\Module\DiConsuming\Application\Subscriber;
use App\Module\DiConsuming\Domain\LocalDependency;
use App\Module\DiConsuming\Domain\Record;
use App\Module\DiConsuming\Domain\RepositoryPolicy;
use App\Module\DiConsuming\Domain\RepositoryPort;
use App\Module\DiConsuming\Infrastructure\DoctrineConsumer;
use App\Module\DiConsuming\Infrastructure\Repository;
use App\Module\DiConsuming\Infrastructure\RepositoryAdapter;
use App\Module\DiConsuming\Resources\migrations\Version20990101000000;
use App\Module\DiProviding\Application\Lookup\LookupHandler;
use App\Module\DiProviding\Application\UnusedConsumer;
use App\Module\DiProviding\Domain\Record as ForeignRecord;
use App\Module\DiProviding\Infrastructure\Factory as ForeignFactory;
use App\Module\DiProviding\Infrastructure\Repository as ForeignRepository;
use App\Module\EventChecking\Application\Observe\ObservedEvent;
use App\Module\EventChecking\Domain\Event\ChangedEvent;
use App\Module\EventChecking\Infrastructure\Event\ReceivedEvent;
use App\Module\EventChecking\Infrastructure\EventListener\ObservedListener;
use App\Platform\Architecture\ContractTypes;
use App\Platform\Architecture\ModuleInventoryPass;
use App\Platform\Architecture\ModuleMap;
use App\Platform\Architecture\ModuleServicesPass;
use App\Platform\DiFixture\Wrapper;
use App\Platform\Event\Recording\RecordsDomainEvents;
use App\Platform\Event\Recording\RecordsDomainEventsTrait;
use App\Platform\Messaging\CommandBus;
use App\Tests\Fixtures\Inventory\InventoryFixture;
use App\Tests\Fixtures\Services\ContainerFacade;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\ServiceRepositoryCompilerPass;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\Bundle\DoctrineBundle\Repository\ContainerRepositoryFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\MessageBus;

require_once __DIR__.'/../Fixtures/Services/ModuleServices.php';
require_once __DIR__.'/../Fixtures/Inventory/EventBoundaryServices.php';

final class ModuleServicesPassTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function inlineConsumers(): iterable
    {
        yield 'infrastructure root' => ['infrastructure'];
        yield 'transparent vendor root' => ['vendor'];
        yield 'locator beneath infrastructure' => ['locator'];
    }

    #[DataProvider('inlineConsumers')]
    public function testInlineApplicationServicesRetainTheirOwnLayer(string $root): void
    {
        $container = $this->container();
        $container->register('adapter.named', RepositoryAdapter::class);
        $inline = new Definition(Consumer::class, [new Reference('adapter.named')]);
        if ('vendor' === $root) {
            $container->register('outer', \stdClass::class)->setPublic(true)->setProperty('service', $inline);
            $source = 'outer (inline '.Consumer::class.')';
        } elseif ('locator' === $root) {
            $locator = new Definition(ServiceLocator::class, [['service' => new ServiceClosureArgument($inline)]]);
            $locator->addTag('container.service_locator');
            $container->register('outer', DoctrineConsumer::class)->setArguments([$locator]);
            $source = 'outer';
        } else {
            $container->register('outer', DoctrineConsumer::class)->setArguments([$inline]);
            $source = 'outer';
        }
        $this->compileFails($container, sprintf('module.services.layer: "%s" (DiConsuming/Application) -> "adapter.named" [App\\Module\\DiConsuming\\Infrastructure\\RepositoryAdapter]; inject a module-owned Domain interface instead of an outward implementation or persistence service.', $source));
    }

    public function testInlineDomainServiceCanUseItsOwnDeclaredRepositoryPort(): void
    {
        $container = $this->container();
        $container->register('adapter.named', RepositoryAdapter::class);
        $container->setAlias(RepositoryPort::class, 'adapter.named');
        $policy = new Definition(RepositoryPolicy::class, [new Reference(RepositoryPort::class)]);
        $container->register('consumer', DoctrineConsumer::class)->setArguments([$policy]);
        $this->expose($container, 'consumer');
        $container->compile();
        $consumer = $this->service($container);
        self::assertInstanceOf(DoctrineConsumer::class, $consumer);
        self::assertInstanceOf(RepositoryPolicy::class, $consumer->dependency);
        self::assertSame('bound-domain-port', $consumer->dependency->repository->label());
    }

    /** @return iterable<string, array{bool}> */
    public static function complexDefaults(): iterable
    {
        yield 'constructor' => [false];
        yield 'configured method' => [true];
    }

    #[DataProvider('complexDefaults')]
    public function testAutowiringNamedPortsAfterComplexDefaults(bool $method): void
    {
        $container = $this->container();
        $container->register('adapter.named', RepositoryAdapter::class);
        $container->setAlias(RepositoryPort::class, 'adapter.named');
        $definition = $container->register('consumer', $method ? ConfiguredRepositoryConsumer::class : ComplexDefaultsConsumer::class)->setAutowired(true)->setPublic(true);
        if ($method) {
            $definition->addMethodCall('configure', []);
        }
        $container->compile();
        $consumer = $container->get('consumer');
        self::assertTrue($consumer instanceof ComplexDefaultsConsumer || $consumer instanceof ConfiguredRepositoryConsumer);
        self::assertSame(['mode' => 'safe'], $consumer->options);
        self::assertInstanceOf(RepositoryPort::class, $consumer->repository);
        self::assertSame('bound-domain-port', $consumer->repository->label());
    }

    /** @return iterable<string, array{class-string}> */
    public static function domainPortConsumers(): iterable
    {
        yield 'Application handler' => [RepositoryConsumer::class];
        yield 'Domain service' => [RepositoryPolicy::class];
    }

    /** @param class-string $class */
    #[DataProvider('domainPortConsumers')]
    public function testAutowiringADomainInterfaceAllowsItsInfrastructureImplementation(string $class): void
    {
        $container = $this->container();
        $container->register('adapter.named', RepositoryAdapter::class);
        $container->setAlias(RepositoryPort::class, 'adapter.named');
        $container->register('consumer', $class)->setAutowired(true);
        $this->expose($container, 'consumer');
        $container->compile();
        $consumer = $this->service($container);
        self::assertTrue($consumer instanceof RepositoryConsumer || $consumer instanceof RepositoryPolicy);
        self::assertInstanceOf(RepositoryAdapter::class, $consumer->repository);
        self::assertSame('bound-domain-port', $consumer->repository->label());
    }

    public function testNamedAliasCannotHideDirectApplicationToInfrastructureInjection(): void
    {
        $container = $this->container();
        $container->register('adapter.named', RepositoryAdapter::class);
        $container->setAlias('friendly.alias', 'adapter.named');
        $container->register('consumer', Consumer::class)->setArgument(0, new Reference('friendly.alias'));
        $this->compileFails($container, 'module.services.layer: "consumer" (DiConsuming/Application) -> "adapter.named" [App\\Module\\DiConsuming\\Infrastructure\\RepositoryAdapter]; inject a module-owned Domain interface instead of an outward implementation or persistence service.');
    }

    /** @return iterable<string, array{string}> */
    public static function explicitPortInjections(): iterable
    {
        yield 'explicit constructor argument' => ['constructor'];
        yield 'typed property' => ['property'];
        yield 'configured setter' => ['method'];
    }

    #[DataProvider('explicitPortInjections')]
    public function testExplicitWiringUsesTheDeclaredDomainPortAfterAliasResolution(string $wiring): void
    {
        $container = $this->container();
        $container->register('adapter.named', RepositoryAdapter::class);
        $container->setAlias(RepositoryPort::class, 'adapter.named');
        $reference = new Reference(RepositoryPort::class);
        $consumer = $container->register('consumer', 'constructor' === $wiring ? RepositoryConsumer::class : ConfiguredRepositoryConsumer::class);
        match ($wiring) {
            'constructor' => $consumer->setArgument(0, $reference),
            'property' => $consumer->setProperty('repository', $reference),
            default => $consumer->addMethodCall('setRepository', [$reference]),
        };
        $this->expose($container, 'consumer');
        $container->compile();
        $service = $this->service($container);
        self::assertTrue($service instanceof RepositoryConsumer || $service instanceof ConfiguredRepositoryConsumer);
        self::assertSame('bound-domain-port', $service->repository->label());
    }

    public function testApplicationCannotInjectTheSharedEntityManagerDirectly(): void
    {
        $container = $this->doctrineContainer();
        $container->register('consumer', Consumer::class)->setArgument(0, new Reference(EntityManagerInterface::class));
        $this->compileFails($container, 'module.services.layer: "consumer" (DiConsuming/Application) -> "doctrine.orm.default_entity_manager" [Doctrine\\ORM\\EntityManager]; inject a module-owned Domain interface instead of an outward implementation or persistence service.');
    }
    private const string FOREIGN_EDGE = 'module.services.foreign: "consumer" (DiConsuming) -> "foreign.named" [App\Module\DiProviding\Infrastructure\Repository] (DiProviding); cross-module service dependencies are forbidden.';

    public function testPlatformTechnicalServicesCanUseOrdinaryVendorServices(): void
    {
        $container = $this->container();
        $container->register('technical.clock', \DateTimeImmutable::class);
        $container->register('consumer', Wrapper::class)->setArgument(0, new Reference('technical.clock'));
        $this->expose($container, 'consumer');
        $container->compile();
        $wrapper = $this->service($container);
        self::assertInstanceOf(Wrapper::class, $wrapper);
        self::assertInstanceOf(\DateTimeImmutable::class, $wrapper->dependency);
    }

    public function testStandardParameterBagExposesParametersRatherThanServices(): void
    {
        $container = $this->container();
        $container->setParameter('fixture.label', 'configured');
        $container->register('parameter_bag', ContainerBag::class)->setArguments([new Reference('service_container')]);
        $container->register('consumer', Consumer::class)->setArgument(0, new Reference('parameter_bag'));
        $this->expose($container, 'consumer');
        $container->compile();
        $consumer = $this->service($container);
        self::assertInstanceOf(Consumer::class, $consumer);
        self::assertInstanceOf(ContainerBag::class, $consumer->dependency);
        self::assertSame('configured', $consumer->dependency->get('fixture.label'));
        self::assertTrue($container->has('doctrine'));
        self::assertFalse($consumer->dependency->has('doctrine'));
    }

    /** @return iterable<string, array{bool}> */
    public static function platformReferences(): iterable
    {
        yield 'alias' => [false];
        yield 'bounded locator' => [true];
    }

    #[DataProvider('platformReferences')]
    public function testPlatformCannotRelayForeignServicesThroughAliasesOrLocators(bool $locator): void
    {
        $container = $this->container();
        $this->foreignRepository($container);
        $container->setAlias('foreign.alias', 'foreign.named');
        $reference = new Reference('foreign.alias');
        $container->register('platform.wrapper', Wrapper::class)->setArgument(0, $locator ? $this->locator($container, $reference) : $reference);
        $container->setAlias('friendly.wrapper', 'platform.wrapper');
        $container->register('consumer', Consumer::class)->setArgument(0, new Reference('friendly.wrapper'));

        $this->compileFails($container, 'module.services.foreign: "platform.wrapper" (Platform) -> "foreign.named" [App\Module\DiProviding\Infrastructure\Repository] (DiProviding); cross-module service dependencies are forbidden.');
    }

    public function testModuleCannotInjectPlatformFacadeEvenWithoutAPhpImport(): void
    {
        $container = $this->container();
        $container->register('platform.wrapper', Wrapper::class);
        $container->setAlias('friendly.wrapper', 'platform.wrapper');
        $container->register('consumer', Consumer::class)->setArgument(0, new Reference('friendly.wrapper'));
        $this->compileFails($container, 'module.services.platform: "consumer" (DiConsuming) -> "platform.wrapper" [App\Platform\DiFixture\Wrapper]; Platform is not a module-facing facade.');
    }

    public function testRawMessengerBusCannotHideBehindAnAlias(): void
    {
        $container = $this->container();
        $container->register('raw.bus', MessageBus::class)->setArgument(0, []);
        $container->setAlias('friendly.bus', 'raw.bus');
        $container->register('consumer', Consumer::class)->setArgument(0, new Reference('friendly.bus'));
        $this->compileFails($container, 'module.services.messenger: "consumer" (DiConsuming) -> "raw.bus"; use the approved command/query facade instead of raw Messenger services.');
    }

    public function testDomainCannotConsumeTheCommandFacade(): void
    {
        $container = $this->container();
        $container->register('raw.bus', MessageBus::class)->setArgument(0, []);
        $container->register(CommandBus::class)->setArgument(0, new Reference('raw.bus'));
        $container->register('consumer', RepositoryPolicy::class)->setArgument(0, new Reference(CommandBus::class));
        $this->compileFails($container, sprintf('module.services.platform: "consumer" (DiConsuming) -> "%s" [%s]; Platform is not a module-facing facade.', CommandBus::class, CommandBus::class));
    }

    public function testInlineFacadeCannotUseTheNamedServiceException(): void
    {
        $container = $this->container();
        $container->register('raw.bus', MessageBus::class)->setArgument(0, []);
        $container->register('consumer', Consumer::class)->setArgument(0, new Definition(CommandBus::class, [new Reference('raw.bus')]));
        $this->compileFails($container, sprintf('module.services.platform: "consumer" (DiConsuming) -> "consumer (inline %s)" [%s]; Platform is not a module-facing facade.', CommandBus::class, CommandBus::class));
    }

    public function testSameModuleAutowiringUsesTheDefinitionClassAndSurvivesCompilation(): void
    {
        $container = $this->container();
        // Deliberately misleading IDs must neither hide nor invent ownership.
        $id = 'App\Module\DiProviding\Infrastructure\MisleadingId';
        $container->register($id, LocalDependency::class)->setAutowired(true);
        $container->setAlias(LocalDependency::class, $id);
        $container->register('consumer', AutowiredConsumer::class)->setAutowired(true);
        $this->expose($container, 'consumer');

        $container->compile();

        $consumer = $this->service($container);
        self::assertInstanceOf(AutowiredConsumer::class, $consumer);
        self::assertInstanceOf(LocalDependency::class, $consumer->dependency);
        self::assertFalse($container->has('consumer'), 'The module service remains private.');
    }

    public function testSameModuleStaticFactoryAndConfiguratorAreSupported(): void
    {
        $container = $this->container();
        $container->register('local', LocalDependency::class)->setAutowired(true);
        $container->register('consumer', Consumer::class)
            ->setAutowired(true)
            ->setFactory([Factory::class, 'create'])
            ->setArguments([new Reference('local')])
            ->setConfigurator([Factory::class, 'configure']);
        $this->expose($container, 'consumer');

        $container->compile();

        $consumer = $this->service($container);
        self::assertInstanceOf(Consumer::class, $consumer);
        self::assertInstanceOf(LocalDependency::class, $consumer->dependency);
        self::assertSame('configured', $consumer->configured);
    }

    #[DataProvider('referenceWiring')]
    public function testForeignNamedServiceCannotHideInsideResolvedWiring(string $wiring): void
    {
        $container = $this->container();
        $this->foreignRepository($container);
        $container->setAlias('friendly.alias', 'foreign.named');
        $container->setAlias('another.alias', 'friendly.alias');
        $reference = new Reference('another.alias');
        $consumer = $container->register('consumer', Consumer::class)->setAutowired(true);

        match ($wiring) {
            'alias' => $consumer->setArgument(0, $reference),
            'nested argument' => $consumer->setArgument(0, ['outer' => ['inner' => $reference]]),
            'property' => $consumer->setProperty('configured', ['repository' => $reference]),
            'method call' => $consumer->addMethodCall('setDependency', [['repository' => $reference]]),
            'closure' => $consumer->setArgument(0, new ServiceClosureArgument($reference)),
            'iterator' => $consumer->setArgument(0, new IteratorArgument([$reference])),
            'tagged iterator' => $consumer->setArgument(0, new TaggedIteratorArgument('fixture.repository')),
            'locator argument' => $consumer->setArgument(0, new ServiceLocatorArgument(['repository' => $reference])),
            'named locator' => $consumer->setArgument(0, $this->locator($container, $reference)),
            'inline infrastructure' => $consumer->setArgument(0, (new Definition(\stdClass::class))->setProperty('repository', $reference)),
            'factory argument' => $consumer->setFactory([Factory::class, 'create'])->setArgument(0, ['repository' => $reference]),
            default => self::fail('Unknown wiring fixture.'),
        };

        $this->compileFails($container, self::FOREIGN_EDGE);
    }

    /** @return iterable<string, array{string}> */
    public static function referenceWiring(): iterable
    {
        foreach (['alias', 'nested argument', 'property', 'method call', 'closure', 'iterator', 'tagged iterator', 'locator argument', 'named locator', 'inline infrastructure', 'factory argument'] as $wiring) {
            yield $wiring => [$wiring];
        }
    }

    public function testAutowiringCannotHideAForeignNamedRepository(): void
    {
        $container = $this->container();
        $this->foreignRepository($container);
        $container->register('consumer', ForeignAutowiredConsumer::class)->setAutowired(true);

        $this->compileFails($container, self::FOREIGN_EDGE);
    }

    public function testAnInlineForeignServiceDefinitionHasAnOwnershipEdge(): void
    {
        $container = $this->container();
        $repository = (new Definition(ForeignRepository::class))->setAutowired(true);
        $container->register('consumer', Consumer::class)->setAutowired(true)->setArgument(0, $repository);

        $this->compileFails($container, 'module.services.foreign: "consumer" (DiConsuming) -> "consumer (inline App\Module\DiProviding\Infrastructure\Repository)" [App\Module\DiProviding\Infrastructure\Repository] (DiProviding); cross-module service dependencies are forbidden.');
    }

    #[DataProvider('platformReferences')]
    public function testForeignCoLocatedHandlerCannotBeExposedThroughAliasesOrLocators(bool $locator): void
    {
        $container = $this->container();
        $container->register('private.handler', LookupHandler::class);
        $container->setAlias('friendly.handler', 'private.handler');
        $reference = new Reference('friendly.handler');
        $container->register('consumer', Consumer::class)->setArgument(0, $locator ? $this->locator($container, $reference) : $reference);

        $this->compileFails($container, 'module.services.foreign: "consumer" (DiConsuming) -> "private.handler" [App\Module\DiProviding\Application\Lookup\LookupHandler] (DiProviding); cross-module service dependencies are forbidden.');
    }

    public function testUnusedPrivateServicesAreCheckedBeforeRemoval(): void
    {
        $container = $this->container();
        $container->register('local', LocalDependency::class)->setAutowired(true);
        $container->setAlias(LocalDependency::class, 'local');
        $unused = $container->register('unused', UnusedConsumer::class)->setAutowired(true);
        self::assertFalse($unused->isPublic());

        $this->compileFails($container, 'module.services.foreign: "unused" (DiProviding) -> "local" [App\Module\DiConsuming\Domain\LocalDependency] (DiConsuming); cross-module service dependencies are forbidden.');
    }

    #[DataProvider('callableWiring')]
    public function testFactoriesAndConfiguratorsHaveModuleEdges(string $kind, string $provider): void
    {
        $container = $this->container();
        $container->register('foreign.factory', ForeignFactory::class)->setAutowired(true);
        $consumer = $container->register('consumer', Consumer::class)->setAutowired(true);
        $target = match ($provider) {
            'reference' => new Reference('foreign.factory'),
            'inline' => new Definition(ForeignFactory::class),
            'static' => ForeignFactory::class,
            default => self::fail('Unknown callable fixture.'),
        };
        if ('factory' === $kind) {
            $consumer->setFactory([$target, 'create']);
        } else {
            $consumer->setConfigurator([$target, 'configure']);
        }
        $targetId = match ($provider) {
            'reference' => 'foreign.factory',
            'inline' => 'consumer (inline '.ForeignFactory::class.')',
            default => ForeignFactory::class,
        };

        $this->compileFails($container, sprintf('module.services.foreign: "consumer" (DiConsuming) -> "%s" [App\Module\DiProviding\Infrastructure\Factory] (DiProviding); cross-module service dependencies are forbidden.', $targetId));
    }

    /** @return iterable<string, array{string, string}> */
    public static function callableWiring(): iterable
    {
        foreach (['factory', 'configurator'] as $kind) {
            foreach (['reference', 'inline', 'static'] as $provider) {
                yield $kind.' '.$provider => [$kind, $provider];
            }
        }
    }

    public function testServiceSubscriberGetsAWorkingBoundedLocator(): void
    {
        $container = $this->container();
        $container->register(LocalDependency::class)->setAutowired(true);
        $container->register('consumer', Subscriber::class)->setAutowired(true)->addTag('container.service_subscriber');
        $this->expose($container, 'consumer');

        $container->compile();

        $subscriber = $this->service($container);
        self::assertInstanceOf(Subscriber::class, $subscriber);
        self::assertInstanceOf(LocalDependency::class, $subscriber->locator->get('dependency'));
        self::assertFalse($subscriber->locator->has('service_container'));
    }

    public function testServiceSubscriberLocatorIsCheckedInItsModulesContext(): void
    {
        $container = $this->container();
        $this->foreignRepository($container);
        $container->register('consumer', ForeignSubscriber::class)->setAutowired(true)->addTag('container.service_subscriber');

        $this->compileFails($container, self::FOREIGN_EDGE);
    }

    public function testASubscriberContextCannotBeReusedByAnotherModuleConsumer(): void
    {
        $container = $this->container();
        $container->register(LocalDependency::class)->setAutowired(true);
        $container->register('subscriber', Subscriber::class)->setAutowired(true)->addTag('container.service_subscriber');
        $container->register('consumer', Consumer::class)->setAutowired(true);
        $reuse = new class implements CompilerPassInterface {
            public string $locatorId = '';

            public function process(ContainerBuilder $container): void
            {
                $reference = $container->getDefinition('subscriber')->getArgument(0);
                if (!$reference instanceof Reference) {
                    throw new \LogicException('Expected the real subscriber compiler pass to produce a locator Reference.');
                }
                $this->locatorId = (string) $reference;
                $container->getDefinition('consumer')->setArgument(0, $reference);
            }
        };
        $container->addCompilerPass($reuse, PassConfig::TYPE_BEFORE_REMOVING, 1);

        try {
            $container->compile();
        } catch (\LogicException $exception) {
            self::assertNotSame('', $reuse->locatorId);
            self::assertSame(sprintf('module.services.locator: "consumer" (DiConsuming) -> "%s"; only statically declared Symfony locator maps and their generated consumer context are supported.', $reuse->locatorId), $exception->getMessage());

            return;
        }
        self::fail('Reusing the diagnostic-container exception must fail compilation.');
    }

    public function testModuleCannotReuseTheContextFactoryException(): void
    {
        $container = $this->container();
        $container->register(LocalDependency::class)->setAutowired(true);
        $locator = ServiceLocatorTagPass::register($container, ['local' => new Reference(LocalDependency::class)]);
        $container->register('consumer', Consumer::class)
            ->setAutowired(true)
            ->setFactory([$locator, 'withContext'])
            ->setArguments(['consumer', new Reference('service_container')])
            ->addTag('container.service_locator_context', ['id' => 'consumer']);

        $this->compileFails($container, 'module.services.lookup_factory: "consumer" (DiConsuming) uses a service locator as a factory; runtime service identifiers are not supported.');
    }

    #[DataProvider('dataServices')]
    public function testDataCannotBeRegisteredAsServices(string $class, string $kind): void
    {
        $container = $this->container();
        $container->register('data.service', $class);

        $this->compileFails($container, sprintf('module.services.data: "data.service" [%s] is %s data, not a service.', $class, $kind));
    }

    /** @return iterable<string, array{string, string}> */
    public static function dataServices(): iterable
    {
        yield 'Application command' => [LookupCommand::class, 'contract'];
        yield 'Application query' => [LookupQuery::class, 'contract'];
        yield 'Application result' => [LookupResult::class, 'contract'];
        yield 'public event' => [ObservedEvent::class, 'contract'];
        yield 'domain event' => [ChangedEvent::class, 'contract'];
        yield 'infrastructure event' => [ReceivedEvent::class, 'contract'];
        yield 'misplaced subclass' => [\App\Module\EventChecking\Application\Misplaced::class, 'contract'];
        foreach (ContractTypes::eventPrimitives() as $class) {
            yield $class => [$class, 'contract'];
        }
        yield 'ORM entity' => [Record::class, 'entity'];
        yield 'migration' => [Version20990101000000::class, 'migration'];
    }

    public function testExcludedDataPlaceholdersAreNotServices(): void
    {
        $container = $this->container();
        $container->register(LookupResult::class)->setAbstract(true)->addTag('container.excluded');
        $container->register(Record::class)->setAbstract(true)->addTag('container.excluded');
        foreach (ContractTypes::eventPrimitives() as $class) {
            $container->register($class)->setAbstract(true)->addTag('container.excluded');
        }
        $container->register('consumer', Consumer::class)->setAutowired(true);
        $this->expose($container, 'consumer');

        $container->compile();

        self::assertInstanceOf(Consumer::class, $this->service($container));
    }

    public function testInlineApplicationDataIsRejectedEvenUnderVendorWiring(): void
    {
        $container = $this->container();
        $container->register('vendor.root', \stdClass::class)->setPublic(true)->setProperty('data', new Definition(LookupResult::class));
        $id = 'vendor.root (inline '.LookupResult::class.')';

        $this->compileFails($container, sprintf('module.services.data: "%s" [%s] is contract data, not a service.', $id, LookupResult::class));
    }

    public function testNamedAliasAndNoncanonicalClassCannotHideApplicationData(): void
    {
        $container = $this->container();
        $container->register('data.named', '\\'.strtolower(LookupResult::class));
        $container->setAlias('data.alias', 'data.named');
        $container->register('consumer', Consumer::class)->setArgument(0, new Reference('data.alias'));

        $this->compileFails($container, sprintf('module.services.data: "data.named" [%s] is contract data, not a service.', LookupResult::class));
    }

    #[DataProvider('dataServices')]
    public function testInlineDataIsRejectedUnderVendorWiring(string $class, string $kind): void
    {
        $container = $this->container();
        $container->register('vendor.root', \stdClass::class)->setPublic(true)->setProperty('data', new Definition($class));
        $this->compileFails($container, sprintf('module.services.data: "vendor.root (inline %s)" [%s] is %s data, not a service.', $class, $class, $kind));
    }

    public function testOwnListenerUsesPrivateExactBusService(): void
    {
        $container = $this->container();
        $container->register('raw.bus', MessageBus::class)->setArgument(0, []);
        $container->register(CommandBus::class)->setArgument(0, new Reference('raw.bus'));
        $container->register('consumer', ObservedListener::class)->setArgument(0, new Reference(CommandBus::class));
        $this->expose($container, 'consumer');
        $container->compile();
        $listener = $this->service($container);
        self::assertInstanceOf(ObservedListener::class, $listener);
        self::assertInstanceOf(CommandBus::class, $listener->dependency);
        self::assertFalse($container->has('consumer'));
    }

    /** @return iterable<string, array{string}> */
    public static function listenerWiring(): iterable
    {
        foreach (['alias', 'inline', 'locator', 'port setter', 'property', 'nested argument'] as $wiring) {
            yield $wiring => [$wiring];
        }
    }

    #[DataProvider('listenerWiring')]
    public function testListenerCannotReachOwnRepositoryEvenThroughAPort(string $wiring): void
    {
        $container = $this->container();
        $repository = \App\Module\EventChecking\Infrastructure\Repository::class;
        $container->register('repository', $repository);
        $container->setAlias('friendly.repository', 'repository');
        $reference = new Reference('friendly.repository');
        $listener = $container->register('consumer', ObservedListener::class);
        $target = 'repository';
        $class = $repository;
        match ($wiring) {
            'inline' => $listener->setArgument(0, new Definition($repository)),
            'locator' => $listener->setArgument(0, $this->locator($container, $reference)),
            'port setter' => $listener->addMethodCall('setRepository', [$reference]),
            'property' => $listener->setProperty('dependency', $reference),
            'nested argument' => $listener->setArgument(0, ['repository' => $reference]),
            default => $listener->setArgument(0, $reference),
        };
        if ('inline' === $wiring) {
            $target = 'consumer (inline '.$repository.')';
        } elseif ('locator' === $wiring) {
            $target = 'technical.locator';
            $class = ServiceLocator::class;
        }
        $this->compileFails($container, sprintf('module.services.listener_dependency: "consumer" -> "%s" [%s]; own event listeners may inject only exact command/query helpers or immutable values.', $target, $class));
    }

    public function testListenerCannotBeExposedByPublicAliasChain(): void
    {
        $container = $this->container();
        $container->register('consumer', ObservedListener::class);
        $container->setAlias('private.alias', 'consumer');
        $container->setAlias('public.alias', 'private.alias')->setPublic(true);
        $this->compileFails($container, 'module.services.listener_private: public.alias exposes consumer.');
    }

    public function testExplicitAbstractPrimitiveRegistrationIsStillData(): void
    {
        $container = $this->container();
        $class = \App\Platform\Event\BaseEvent::class;
        $container->register('primitive', $class)->setAbstract(true);
        // Exercise the pass before Symfony removes abstract definitions.
        $this->expectExceptionMessage('module.services.data: "primitive" ['.$class.'] is contract data, not a service.');
        new ModuleServicesPass()->process($container);
    }

    public function testEarlyInventoryRejectsInlineEventBeforeAutowiring(): void
    {
        $container = new ContainerBuilder();
        $container->register('vendor.root', \stdClass::class)->setProperty('data', new Definition(ChangedEvent::class));
        $this->expectExceptionMessage('module.inventory.data: '.ChangedEvent::class.' is message/result/event data and must be excluded from service registration.');
        new ModuleInventoryPass(new ModuleMap('/tmp/unused-event-inventory'))->process($container);
    }

    public function testRealModulePrototypeExcludesEveryEventCategory(): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture): void {
            $fixture->addModule($fixture->module);
            $fixture->addUseCase($fixture->module);
            $events = $fixture->addEvents($fixture->module);
            $container = $fixture->container();
            $container->compile();
            foreach ($events as $class) {
                self::assertTrue(class_exists($class));
                self::assertFalse($container->has($class));
            }
        });
    }

    public function testConcreteDataCannotPretendToBeAnExcludedPlaceholder(): void
    {
        $container = $this->container();
        $container->register('event', ObservedEvent::class)->addTag('container.excluded');
        $this->compileFails($container, sprintf('module.services.data: "event" [%s] is contract data, not a service.', ObservedEvent::class));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function recordingSupportRegistrations(): iterable
    {
        foreach ([RecordsDomainEvents::class, RecordsDomainEventsTrait::class] as $class) {
            foreach (['named', 'inline', 'abstract', 'alias', 'noncanonical', 'concrete excluded'] as $wiring) {
                foreach ([true, false] as $early) {
                    yield $class.' '.$wiring.($early ? ' early' : ' late') => [$class, $wiring, $early];
                }
            }
        }
    }

    #[DataProvider('recordingSupportRegistrations')]
    public function testRecordingSupportCannotBecomeAService(string $class, string $wiring, bool $early): void
    {
        if ('noncanonical' === $wiring) {
            // PHP's case-insensitive lookup applies once the declaration is loaded.
            self::assertTrue(interface_exists($class) || trait_exists($class));
        }
        $container = new ContainerBuilder();
        $definition = new Definition('noncanonical' === $wiring ? '\\'.strtolower($class) : $class);
        $id = 'support.named';
        if ('inline' === $wiring) {
            $container->register('vendor.root', \stdClass::class)->setProperty('support', new ServiceClosureArgument($definition));
            $id = 'vendor.root (inline '.$class.')';
        } else {
            $container->setDefinition($id, $definition);
            if ('abstract' === $wiring) {
                $definition->setAbstract(true);
            } elseif ('concrete excluded' === $wiring) {
                $definition->addTag('container.excluded');
            } elseif ('alias' === $wiring) {
                $container->setAlias('support.alias', $id);
                $container->register('consumer', Consumer::class)->setArgument(0, new Reference('support.alias'));
            }
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($early
            ? 'module.inventory.support: '.$class.' is Domain event recording support and must be excluded from service registration.'
            : sprintf('module.services.support: "%s" [%s] is Domain event recording support, not a service.', $id, $class));
        // Exercise each guard independently: Symfony may reject interface/trait
        // construction before the late pass in an ordinary compilation.
        if ($early) {
            new ModuleInventoryPass(new ModuleMap('/tmp/unused-recording-inventory'))->process($container);
        } else {
            new ModuleServicesPass()->process($container);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function recordingSupportInjections(): iterable
    {
        foreach ([RecordsDomainEvents::class, RecordsDomainEventsTrait::class] as $support) {
            foreach ([Consumer::class, ObservedListener::class, \App\Module\EventChecking\UI\Http\Controller::class] as $consumer) {
                yield $consumer.' '.$support => [$support, $consumer];
            }
        }
    }

    #[DataProvider('recordingSupportInjections')]
    public function testResolvedApplicationListenerAndUiWiringCannotInjectRecordingSupport(string $support, string $consumer): void
    {
        $container = $this->container();
        $container->register('consumer', $consumer);
        // Insert resolved wiring at the late boundary, after Symfony's own
        // interface/trait construction checks, so this proves our guard as well.
        $container->addCompilerPass(new class($support) implements CompilerPassInterface {
            public function __construct(private readonly string $support)
            {
            }

            public function process(ContainerBuilder $container): void
            {
                $container->register('support.named', $this->support);
                $container->setAlias('support.alias', 'support.named');
                $container->getDefinition('consumer')->setArgument(0, new Reference('support.alias'));
            }
        }, PassConfig::TYPE_BEFORE_REMOVING, 1);
        $this->compileFails($container, sprintf('module.services.support: "support.named" [%s] is Domain event recording support, not a service.', $support));
    }

    public function testRecordingSupportAndOptInEntitiesRemainSeparateFromPublicDataAndServices(): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture): void {
            $fixture->addModule($fixture->module);
            $entities = $fixture->addRecordingEntities($fixture->module);
            $container = $fixture->container();
            $container->compile();
            foreach ([RecordsDomainEvents::class, RecordsDomainEventsTrait::class, ...$entities] as $class) {
                self::assertFalse(ContractTypes::isPublic($class));
                self::assertFalse(ContractTypes::isDataCandidate($class));
                self::assertFalse(ContractTypes::isEventPrimitive($class));
                self::assertFalse(ContractTypes::isAnyEventData($class));
                self::assertNull(ContractTypes::messageKind($class));
                self::assertFalse($container->has($class));
                self::assertSame(!in_array($class, $entities, true), ContractTypes::isEventRecordingSupport($class));
            }
            foreach ($entities as $class) {
                $entity = new $class();
                self::assertInstanceOf(RecordsDomainEvents::class, $entity);
                self::assertSame([], $entity->releaseEvents());
                $explicit = $this->container();
                $explicit->register('recording.entity', $class);
                $this->compileFails($explicit, sprintf('module.services.data: "recording.entity" [%s] is entity data, not a service.', $class));
            }
        });
        foreach (['App\\Platform\\Event\\Recording\\OtherSupport', RecordsDomainEvents::class.'Child', 'App\\Module\\EventChecking\\Domain\\RecordsDomainEvents', ...ContractTypes::eventPrimitives()] as $class) {
            self::assertFalse(ContractTypes::isEventRecordingSupport($class));
        }
        self::assertCount(4, ContractTypes::eventPrimitives());
    }

    public function testRecordingSupportExcludedPlaceholdersRemainValid(): void
    {
        $container = $this->container();
        foreach ([RecordsDomainEvents::class, RecordsDomainEventsTrait::class] as $class) {
            $container->register($class)->setAbstract(true)->addTag('container.excluded');
        }
        new ModuleInventoryPass(new ModuleMap('/tmp/unused-recording-inventory'))->process($container);
        $container->compile();
        self::assertFalse($container->has(RecordsDomainEvents::class));
        self::assertFalse($container->has(RecordsDomainEventsTrait::class));
    }

    /** @return iterable<string, array{string}> */
    public static function recorderConsumers(): iterable
    {
        yield 'Domain' => [RepositoryPolicy::class];
        yield 'Infrastructure' => [DoctrineConsumer::class];
        yield 'own event listener' => [ObservedListener::class];
        yield 'UI' => [\App\Module\EventChecking\UI\Http\Controller::class];
    }

    #[DataProvider('recorderConsumers')]
    public function testRecorderIsForbiddenOutsideApplicationImplementation(string $consumer): void
    {
        $container = $this->container();
        $recorder = \App\Platform\Messaging\ApplicationEventRecorder::class;
        $container->register($recorder);
        $container->register('consumer', $consumer)->setArgument(0, new Reference($recorder));
        $module = ModuleMap::owner($consumer);
        $this->compileFails($container, sprintf('module.services.platform: "consumer" (%s) -> "%s" [%s]; Platform is not a module-facing facade.', $module, $recorder, $recorder));
    }

    public function testResolvedEventClassificationRequiresTheDirectMatchingCategory(): void
    {
        foreach ([ObservedEvent::class, ChangedEvent::class, ReceivedEvent::class] as $class) {
            self::assertTrue(ContractTypes::isConcreteEvent(new \ReflectionClass($class)));
        }
        foreach ([\App\Module\EventChecking\Application\Observe\WrongCategoryEvent::class, \App\Module\EventChecking\Application\Observe\UnrelatedEvent::class, \App\Module\EventChecking\Application\Misplaced::class, \App\Platform\Event\ApplicationEvent::class] as $class) {
            self::assertFalse(ContractTypes::isConcreteEvent(new \ReflectionClass($class)));
        }
        self::assertSame('event', ContractTypes::messageKind(ObservedEvent::class));
        self::assertNull(ContractTypes::messageKind(ChangedEvent::class));
        self::assertNull(ContractTypes::messageKind(ReceivedEvent::class));
    }

    public function testExpressionWiringHasASpecificUnsupportedDiagnostic(): void
    {
        $container = $this->container();
        $container->register('consumer', Consumer::class)->setAutowired(true)->setArgument(0, '@=service(parameter("computed_id"))');

        $this->compileFails($container, 'module.services.expression: "consumer" (DiConsuming) uses unsupported Expression wiring; declare explicit References instead.');
    }

    public function testFunctionFactoriesHaveASpecificUnsupportedDiagnostic(): void
    {
        $container = $this->container();
        $container->register('consumer', Consumer::class)->setFactory('current')->setArguments([[]]);

        $this->compileFails($container, 'module.services.callable: "consumer" (DiConsuming) uses an unsupported factory; declare a literal class/service and method.');
    }

    public function testRuntimeClosuresAreNotMistakenForCheckedServiceClosures(): void
    {
        $container = $this->container();
        $container->register('consumer', Consumer::class)->setAutowired(true)->setArgument(0, static fn (): string => 'computed');

        $this->compileFails($container, 'module.services.dynamic: "consumer" (DiConsuming) uses a runtime closure; use ServiceClosureArgument with explicit References.');
    }

    public function testComputedReferenceIdentifiersHaveASpecificUnsupportedDiagnostic(): void
    {
        $container = $this->container();
        $container->register('consumer', Consumer::class)->setAutowired(true)->setArgument(0, new Reference('%env(FIXTURE_SERVICE_ID)%'));

        $this->compileFails($container, 'module.services.dynamic: "consumer" (DiConsuming) uses a computed service identifier; declare a literal Reference.');
    }

    public function testExpressionFactoriesAreRejectedAtTheAgreedCompilerStage(): void
    {
        $container = $this->container();
        $container->register('consumer', Consumer::class)->setAutowired(true);
        // ExpressionLanguage is optional and absent from the lock. Inject its stable
        // factory representation after optimization to exercise this pass's policy,
        // independently of Symfony's earlier missing-component diagnostic.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition('consumer')->setFactory('@=service(parameter("computed_id"))');
            }
        }, PassConfig::TYPE_BEFORE_REMOVING, 1);

        $this->compileFails($container, 'module.services.expression: "consumer" (DiConsuming) uses unsupported Expression wiring; declare explicit References instead.');
    }

    #[DataProvider('containerReferences')]
    public function testWideContainerLookupIsRejectedEvenBehindAnAliasOrContextTag(string $target): void
    {
        $container = $this->container();
        $container->register('technical.container', ContainerFacade::class);
        $container->setAlias('container.alias', $target);
        $container->register('consumer', Consumer::class)
            ->setAutowired(true)
            ->setArgument(0, new Reference('container.alias'))
            ->addTag('container.service_locator_context', ['id' => 'consumer']);

        $this->compileFails($container, sprintf('module.services.container: "consumer" (DiConsuming) -> "%s"; inject explicit services or a bounded Symfony service locator, not a full container.', $target));
    }

    /** @return iterable<string, array{string}> */
    public static function containerReferences(): iterable
    {
        yield 'full framework container' => ['service_container'];
        yield 'custom container implementation' => ['technical.container'];
    }

    public function testOrdinaryDoctrineRepositoryResolutionDoesNotInheritAllRepositoryEdges(): void
    {
        $container = $this->doctrineContainer();
        $container->register('consumer', DoctrineConsumer::class)->setAutowired(true)->setArgument(0, [
            new Reference(Repository::class),
            new Reference(EntityManagerInterface::class),
            new Reference(ManagerRegistry::class),
        ]);
        $this->expose($container, 'consumer');

        $container->compile();

        $consumer = $this->service($container);
        self::assertInstanceOf(DoctrineConsumer::class, $consumer);
        self::assertIsArray($consumer->dependency);
        [$repository, $manager, $registry] = $consumer->dependency;
        self::assertInstanceOf(Repository::class, $repository);
        self::assertInstanceOf(EntityManager::class, $manager);
        self::assertInstanceOf(Registry::class, $registry);
        self::assertSame($repository, $manager->getRepository(Record::class));
        self::assertSame($repository, $registry->getRepository(Record::class));
        self::assertFalse($manager->getConnection()->isConnected(), 'Repository resolution needs metadata, not a database connection.');
    }

    public function testDoctrineRepositoryFactoryCannotBeInjectedAsABackdoor(): void
    {
        $container = $this->doctrineContainer();
        $container->setAlias('friendly.factory', 'doctrine.orm.container_repository_factory');
        $container->register('consumer', Consumer::class)->setAutowired(true)->setArgument(0, new Reference('friendly.factory'));

        $this->compileFails($container, 'module.services.repository_provider: "consumer" (DiConsuming) -> "doctrine.orm.container_repository_factory"; Doctrine repository factories and their aggregate locators are not module-facing services.');
    }

    public function testDoctrineAggregateLocatorCannotBeInjectedAsABackdoor(): void
    {
        $container = $this->doctrineContainer();
        // Use Doctrine's actual compiler pass to obtain its otherwise anonymous locator.
        new ServiceRepositoryCompilerPass()->process($container);
        $locator = $container->getDefinition('doctrine.orm.container_repository_factory')->getArgument(0);
        self::assertInstanceOf(Reference::class, $locator);
        $container->register('consumer', Consumer::class)->setAutowired(true)->setArgument(0, $locator);

        $this->compileFails($container, sprintf('module.services.repository_provider: "consumer" (DiConsuming) -> "%s"; Doctrine repository factories and their aggregate locators are not module-facing services.', $locator));
    }

    public function testDoctrineFactoryCannotSelectAForeignEntityThroughALiteralArgument(): void
    {
        $container = $this->doctrineContainer();
        $container->register('consumer', Repository::class)
            ->setFactory([new Reference(EntityManagerInterface::class), 'getRepository'])
            ->setArguments([ForeignRecord::class]);

        $this->compileFails($container, 'module.services.repository_factory: "consumer" (DiConsuming) must use getRepository() with a literal entity class owned by that module.');
    }

    public function testDoctrineFactoryCanResolveALiteralOwnedEntityRepository(): void
    {
        $container = $this->doctrineContainer();
        $container->register('consumer', Repository::class)
            ->setFactory([new Reference(EntityManagerInterface::class), 'getRepository'])
            ->setArguments([Record::class]);
        $this->expose($container, 'consumer');

        $container->compile();

        self::assertInstanceOf(Repository::class, $this->service($container));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->addCompilerPass(new ModuleServicesPass(), PassConfig::TYPE_BEFORE_REMOVING);
        $container->register('doctrine', Registry::class)->setPublic(true)->setArguments([new Reference('service_container'), [], [], 'default', 'default']);
        $container->setAlias(ManagerRegistry::class, 'doctrine');

        return $container;
    }

    private function foreignRepository(ContainerBuilder $container): void
    {
        $container->register('foreign.named', ForeignRepository::class)->setAutowired(true)->addTag('fixture.repository');
        $container->setAlias(ForeignRepository::class, 'foreign.named');
    }

    private function locator(ContainerBuilder $container, Reference $reference): Reference
    {
        $container->register('technical.locator', ServiceLocator::class)->setArguments([['repository' => $reference]])->addTag('container.service_locator');

        return new Reference('technical.locator');
    }

    private function doctrineContainer(): ContainerBuilder
    {
        $container = $this->container();
        $container->addCompilerPass(new ServiceRepositoryCompilerPass());
        $container->getDefinition('doctrine')->setArguments([
            new Reference('service_container'),
            ['default' => 'doctrine.dbal.default_connection'],
            ['default' => 'doctrine.orm.default_entity_manager'],
            'default',
            'default',
        ]);
        $container->register('doctrine.dbal.default_connection', Connection::class)
            ->setPublic(true)
            ->setFactory([DriverManager::class, 'getConnection'])
            ->setArguments([['driver' => 'pdo_pgsql', 'serverVersion' => '18.0']]);
        $container->register('doctrine.orm.default_configuration', Configuration::class)
            ->setFactory([ORMSetup::class, 'createAttributeMetadataConfig'])
            ->setArguments([[__DIR__.'/../Fixtures/Services'], true])
            ->addMethodCall('enableNativeLazyObjects', [true])
            ->addMethodCall('setRepositoryFactory', [new Reference('doctrine.orm.container_repository_factory')]);
        $container->register('doctrine.orm.default_entity_manager', EntityManager::class)
            ->setPublic(true)
            ->setArguments([new Reference('doctrine.dbal.default_connection'), new Reference('doctrine.orm.default_configuration')]);
        $container->setAlias(EntityManagerInterface::class, 'doctrine.orm.default_entity_manager');
        $container->register('doctrine.orm.container_repository_factory', ContainerRepositoryFactory::class)->setArguments([null]);
        $container->register(Repository::class)->setAutowired(true)->addTag('doctrine.repository_service');
        $this->foreignRepository($container);
        $container->getDefinition('foreign.named')->addTag('doctrine.repository_service');

        return $container;
    }

    private function expose(ContainerBuilder $container, string $id): void
    {
        $container->register('test.root', \stdClass::class)->setPublic(true)->setProperty('service', new Reference($id));
    }

    private function service(ContainerBuilder $container): mixed
    {
        $root = $container->get('test.root');
        self::assertInstanceOf(\stdClass::class, $root);

        return $root->service;
    }

    private function compileFails(ContainerBuilder $container, string $message): void
    {
        try {
            $container->compile();
        } catch (\LogicException $exception) {
            self::assertSame($message, $exception->getMessage());

            return;
        }

        self::fail('Expected the specific module-boundary diagnostic: '.$message);
    }
}
