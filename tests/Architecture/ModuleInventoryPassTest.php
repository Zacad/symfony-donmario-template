<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Fixtures\Inventory\InventoryFixture;
use App\Tests\Fixtures\Inventory\ServiceProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Response;

final class ModuleInventoryPassTest extends TestCase
{
    public function testCoLocatedDataIsExcludedWhilePrivateHandlersAndConsoleAdaptersWork(): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture): void {
            foreach ([$fixture->module, $fixture->additionalModule] as $module) {
                $fixture->addModule($module);
                $fixture->addUseCase($module);
            }
            $container = $fixture->container();
            foreach ([$fixture->module, $fixture->additionalModule] as $module) {
                $handler = 'App\\Module\\'.$module.'\\Application\\Lookup\\LookupHandler';
                $console = 'App\\Module\\'.$module.'\\UI\\Console\\ProbeCommand';
                $this->assertRegistrationDefaults($container, $handler);
                $this->assertRegistrationDefaults($container, $console);
                $container->setAlias('test.handler.'.$module, $handler)->setPublic(true);
                $container->setAlias('test.console.'.$module, $console)->setPublic(true);
            }

            $container->compile();

            foreach ([$fixture->module, $fixture->additionalModule] as $module) {
                self::assertTrue($container->findDefinition('test.handler.'.$module)->hasTag('inventory.handler'));
                $service = $container->get('test.handler.'.$module);
                self::assertInstanceOf(ServiceProbe::class, $service);
                self::assertSame('domain-service:input', $service->value());
                $console = $container->get('test.console.'.$module);
                self::assertInstanceOf(ServiceProbe::class, $console);
                self::assertSame('console-adapter', $console->value());
                foreach (['Command', 'Query', 'Result'] as $kind) {
                    $class = 'App\\Module\\'.$module.'\\Application\\Lookup\\Lookup'.$kind;
                    self::assertTrue(class_exists($class));
                    self::assertFalse($container->has($class), $class.' must be usable data, not a service.');
                }
            }
        });
    }

    /** @return iterable<string, array{string}> */
    public static function applicationDataKinds(): iterable
    {
        foreach (['Command', 'Query', 'Result'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('applicationDataKinds')]
    public function testDataExclusionCannotBeUndoneByExplicitRegistration(string $kind): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture) use ($kind): void {
            $fixture->addModule($fixture->module);
            $fixture->addUseCase($fixture->module);
            $class = 'App\\Module\\'.$fixture->module.'\\Application\\Lookup\\Lookup'.$kind;
            $path = $fixture->configurationPath($fixture->module);
            $fixture->write($path, $fixture->read($path)."\n    $class: ~\n");

            $this->compileFails($fixture->container(), 'module.inventory.data: '.$class.' is message/result/event data and must be excluded from service registration.');
        });
    }

    public function testMissingHandlerCannotHideBesideExcludedData(): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture): void {
            $fixture->addModule($fixture->module);
            $handler = $fixture->addUseCase($fixture->module);
            $fixture->replace($fixture->configurationPath($fixture->module), "        exclude:\n", "        exclude:\n            - '../../Application/Lookup/LookupHandler.php'\n");

            $this->compileFails($fixture->container(), 'module.inventory.service: '.$handler.' was not registered.');
        });
    }

    public function testExplicitDomainServiceUsesTheSameRegistrationDefaults(): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture): void {
            $fixture->addModule($fixture->module);
            $id = $fixture->addDomainService($fixture->module);
            $container = $fixture->container();
            $container->setAlias('test.domain', $id)->setPublic(true);
            $container->compile();
            $service = $container->get('test.domain');
            self::assertInstanceOf(ServiceProbe::class, $service);
            self::assertSame('domain-service', $service->value());
        });
    }

    #[DataProvider('invalidDefaults')]
    public function testExplicitDomainServicesCannotBypassDefaults(string $invalid): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture) use ($invalid): void {
            $fixture->addModule($fixture->module);
            $id = $fixture->addDomainService($fixture->module, $invalid);
            $this->compileFails($fixture->container(), 'module.inventory.defaults: '.$id.' requires private, autowired, autoconfigured registration.');
        });
    }

    /** @return iterable<string, array{string}> */
    public static function classRepresentations(): iterable
    {
        yield 'leading separator' => ['leading'];
        yield 'compile-time parameter' => ['parameter'];
        yield 'PHP class-name case' => ['case'];
    }

    #[DataProvider('classRepresentations')]
    public function testValidClassRepresentationsResolveToTheSameOwnedService(string $representation): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture) use ($representation): void {
            $id = $fixture->addModule($fixture->module);
            $container = $fixture->container();
            $container->setParameter('fixture.service_class', $id);
            $container->getDefinition($id)->setClass(match ($representation) {
                'leading' => '\\'.$id,
                'parameter' => '%fixture.service_class%',
                default => strtolower($id),
            });
            $container->setAlias('test.normalized', $id)->setPublic(true);
            $container->compile();
            $service = $container->get('test.normalized');
            self::assertInstanceOf(ServiceProbe::class, $service);
            self::assertSame('dependency:module-local', $service->value());
        });
    }

    public function testRootPlatformRegistrationPreservesImportedModuleDefaultsAndLocalBind(): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture): void {
            $id = $fixture->addModule($fixture->module);
            $container = $fixture->container();
            $this->assertRegistrationDefaults($container, $id);
            self::assertTrue($container->hasDefinition($fixture->platformService));
            $container->setAlias('test.inventory.service', $id)->setPublic(true);
            $container->setAlias('test.inventory.platform', $fixture->platformService)->setPublic(true);

            $container->compile();

            $this->assertConfiguredService($container, 'test.inventory.service', 'module-local');
            $platform = $container->get('test.inventory.platform');
            self::assertInstanceOf(ServiceProbe::class, $platform);
            self::assertSame('platform', $platform->value());
        });
    }

    public function testAnotherResponsibilityModuleIsImportedWithoutChangingCheckerConfiguration(): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture): void {
            $first = $fixture->addModule($fixture->module);
            $second = $fixture->addModule($fixture->additionalModule, 'archive-local');
            $container = $fixture->container();
            $this->assertRegistrationDefaults($container, $first);
            $this->assertRegistrationDefaults($container, $second);
            $container->setAlias('test.inventory.first', $first)->setPublic(true);
            $container->setAlias('test.inventory.second', $second)->setPublic(true);

            $container->compile();

            $this->assertConfiguredService($container, 'test.inventory.first', 'module-local');
            $this->assertConfiguredService($container, 'test.inventory.second', 'archive-local');
        });
    }

    public function testMissingModuleImportFailsEvenWhenRootYamlRegistersItsServices(): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture): void {
            $id = $fixture->addModule($fixture->module);
            $fixture->replace('config/services.yaml', "imports:\n    - { resource: '../src/Module/*/Resources/config/services.yaml' }\n", '');
            $fixture->write('config/services.yaml', $fixture->read('config/services.yaml').sprintf(
                "\n    %s: ~\n    App\\Module\\%s\\Infrastructure\\Dependency: ~\n",
                $id,
                $fixture->module,
            ));
            $container = $fixture->container();
            $this->assertRegistrationDefaults($container, $id);

            $this->compileFails($container, 'module.inventory.import: '.$fixture->module.' service configuration was not imported.');
        });
    }

    #[DataProvider('omittedServiceModules')]
    public function testImportedConfigurationCannotOmitAConcreteService(bool $additionalModule): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture) use ($additionalModule): void {
            $fixture->addModule($fixture->module);
            $module = $additionalModule ? $fixture->additionalModule : $fixture->module;
            if ($additionalModule) {
                $fixture->addModule($module);
            }
            $fixture->omitService($module);
            $container = $fixture->container();
            $id = 'App\\Module\\'.$module.'\\Infrastructure\\ConfiguredService';
            self::assertFalse($container->hasDefinition($id));
            $this->assertRegistrationDefaults($container, 'App\\Module\\'.$module.'\\Infrastructure\\Dependency');

            $this->compileFails($container, 'module.inventory.service: '.$id.' was not registered.');
        });
    }

    /** @return iterable<string, array{bool}> */
    public static function omittedServiceModules(): iterable
    {
        yield 'initial module' => [false];
        yield 'additional arbitrary responsibility' => [true];
    }

    #[DataProvider('invalidDefaults')]
    public function testInvalidModuleYamlDefaultsFailWithTheExactDiagnostic(string $invalid): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture) use ($invalid): void {
            $id = $fixture->addModule($fixture->module);
            // Override only this service, keeping its dependency's defaults valid.
            $fixture->replace($fixture->configurationPath($fixture->module), "    $id:\n", "    $id:\n        $invalid\n");
            $container = $fixture->container();
            self::assertTrue($container->hasDefinition($id));

            $this->compileFails($container, 'module.inventory.defaults: '.$id.' requires private, autowired, autoconfigured registration.');
        });
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDefaults(): iterable
    {
        yield 'public' => ['public: true'];
        yield 'not autowired' => ['autowire: false'];
        yield 'not autoconfigured' => ['autoconfigure: false'];
    }

    public function testPrivateAutoconfiguredControllerCanCompileBeforeSymfonyMakesItPublic(): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture): void {
            $fixture->addModule($fixture->module);
            $id = $fixture->addController($fixture->module);
            $container = $fixture->container();
            $this->assertRegistrationDefaults($container, $id);
            self::assertFalse($container->getDefinition($id)->hasTag('controller.service_arguments'), 'The fixture relies on real FrameworkBundle attribute autoconfiguration.');

            // Use the production Kernel's pass ordering. A late blanket private
            // check must fail this positive regression, not become an expectation.
            $container->compile();

            self::assertTrue($container->getDefinition($id)->hasTag('controller.service_arguments'));
            self::assertTrue($container->getDefinition($id)->isPublic(), 'Symfony exposes the controller after checking its declared registration.');
            $controller = $container->get($id);
            self::assertIsCallable($controller);
            $response = $controller();
            self::assertInstanceOf(Response::class, $response);
            self::assertSame('dependency:module-local', $response->getContent());
        });
    }

    private function assertRegistrationDefaults(ContainerBuilder $container, string $id): void
    {
        $definition = $container->getDefinition($id);
        self::assertFalse($definition->isPublic());
        self::assertTrue($definition->isAutowired());
        self::assertTrue($definition->isAutoconfigured());
    }

    private function assertConfiguredService(ContainerBuilder $container, string $alias, string $label): void
    {
        self::assertTrue($container->findDefinition($alias)->hasTag('inventory.fixture'), 'Autoconfiguration must actually apply the fixture attribute.');
        $service = $container->get($alias);
        self::assertInstanceOf(ServiceProbe::class, $service);
        self::assertSame('dependency:'.$label, $service->value(), 'A root App\\ catch-all would replace the module-local bind with the constructor default.');
    }

    private function compileFails(ContainerBuilder $container, string $message): void
    {
        try {
            $container->compile();
        } catch (\LogicException $exception) {
            self::assertSame($message, $exception->getMessage());

            return;
        }

        self::fail('Expected the specific inventory diagnostic: '.$message);
    }
}
