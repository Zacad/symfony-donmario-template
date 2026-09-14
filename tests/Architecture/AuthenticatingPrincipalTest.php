<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use App\Module\Authenticating\UI\Http\Security\AccountUserProvider;
use App\Platform\Architecture\ContractTypes;
use App\Platform\Architecture\ModuleInventoryPass;
use App\Platform\Architecture\ModuleMap;
use App\Platform\Architecture\ModuleServicesPass;
use App\Platform\Messaging\EventPolicyMiddleware;
use App\Tests\Fixtures\Inventory\InventoryFixture;
use App\Tests\Fixtures\Inventory\InventoryKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class AuthenticatingPrincipalTest extends TestCase
{
    public function testPrincipalRemainsExactInternalInfrastructureData(): void
    {
        self::assertTrue(ContractTypes::isAuthenticationPrincipal(AccountPrincipal::class));
        self::assertSame('Authenticating', ModuleMap::owner(AccountPrincipal::class));
        self::assertSame('Infrastructure', ModuleMap::layer(AccountPrincipal::class));
        self::assertFalse(ContractTypes::isPublic(AccountPrincipal::class));
        self::assertFalse(ContractTypes::isDataCandidate(AccountPrincipal::class));
        self::assertNull(ContractTypes::messageKind(AccountPrincipal::class));
        foreach ([
            AccountUserProvider::class,
            AccountPrincipal::class.'Child',
            strtolower(AccountPrincipal::class),
            '\\'.AccountPrincipal::class,
            str_replace('Authenticating', 'Authorizing', AccountPrincipal::class),
            str_replace('Security', 'Other', AccountPrincipal::class),
            'Symfony\\Component\\Security\\Core\\User\\InMemoryUser',
        ] as $class) {
            self::assertFalse(ContractTypes::isAuthenticationPrincipal($class), $class);
        }
    }

    public function testActualApplicationCompilesWithExcludedPrincipalAndRequiredProvider(): void
    {
        $container = new InventoryKernel(\dirname(__DIR__, 2))->createContainer();
        $principal = $container->getDefinition(AccountPrincipal::class);
        self::assertTrue($principal->isAbstract());
        self::assertTrue($principal->hasTag('container.excluded'));
        $provider = $container->getDefinition(AccountUserProvider::class);
        self::assertFalse($provider->isPublic());
        self::assertTrue($provider->isAutowired());
        self::assertTrue($provider->isAutoconfigured());
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach (['app.command_policy' => 1, 'app.query_policy' => 1, EventPolicyMiddleware::class => 0] as $id => $argument) {
                    $inventory = $container->findDefinition($id)->getArgument($argument);
                    TestCase::assertIsArray($inventory);
                    TestCase::assertNotEmpty($inventory);
                    TestCase::assertArrayNotHasKey(AccountPrincipal::class, $inventory);
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING, 5);

        $container->compile();

        self::assertTrue($container->isCompiled());
        self::assertFalse($container->has(AccountPrincipal::class));
    }

    public function testPrincipalExceptionDoesNotAllowOmittingTheActualProvider(): void
    {
        $container = new InventoryKernel(\dirname(__DIR__, 2))->createContainer();
        $container->removeDefinition(AccountUserProvider::class);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('module.inventory.service: '.AccountUserProvider::class.' was not registered.');
        $container->compile();
    }

    /** @return iterable<string, array{string, bool}> */
    public static function registrations(): iterable
    {
        foreach (['explicit', 'inline', 'alias', 'noncanonical', 'inline noncanonical', 'abstract', 'concrete excluded', 'fake diagnostic'] as $wiring) {
            foreach ([true, false] as $early) {
                yield $wiring.($early ? ' inventory' : ' services') => [$wiring, $early];
            }
        }
    }

    #[DataProvider('registrations')]
    public function testPrincipalCannotBeRegisteredAsAService(string $wiring, bool $early): void
    {
        // Noncanonical PHP names resolve after the real declaration is loaded.
        self::assertTrue(class_exists(AccountPrincipal::class));
        $container = new ContainerBuilder();
        $definition = new Definition(str_contains($wiring, 'noncanonical') ? '\\'.strtolower(AccountPrincipal::class) : AccountPrincipal::class);
        $id = 'fake diagnostic' === $wiring ? '.errored..service_locator.fake.'.AccountPrincipal::class : 'principal.named';
        if (str_starts_with($wiring, 'inline')) {
            $container->register('vendor.root', \stdClass::class)->setProperty('principal', new ServiceClosureArgument($definition));
            $id = 'vendor.root (inline '.AccountPrincipal::class.')';
        } else {
            $container->setDefinition($id, $definition);
            if ('alias' === $wiring) {
                $container->setAlias('principal.alias', $id)->setPublic(true);
                $container->register('vendor.root', \stdClass::class)->setProperty('principal', new Reference('principal.alias'));
            } elseif ('abstract' === $wiring) {
                $definition->setAbstract(true);
            } elseif ('concrete excluded' === $wiring) {
                $definition->addTag('container.excluded');
            }
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($early
            ? 'module.inventory.data: '.AccountPrincipal::class.' is authentication principal data and must be excluded from service registration.'
            : sprintf('module.services.data: "%s" [%s] is authentication principal data, not a service.', $id, AccountPrincipal::class));
        // Exercise each boundary independently, before Symfony can reject scalar
        // constructor arguments or remove abstract definitions for other reasons.
        if ($early) {
            new ModuleInventoryPass(new ModuleMap('/tmp/unused-principal-inventory'))->process($container);
        } else {
            new ModuleServicesPass()->process($container);
        }
    }

    public function testExcludedPlaceholderIsAcceptedByBothPasses(): void
    {
        $container = new ContainerBuilder();
        $container->register(AccountPrincipal::class)->setAbstract(true)->addTag('container.excluded');
        new ModuleInventoryPass(new ModuleMap('/tmp/unused-principal-inventory'))->process($container);
        new ModuleServicesPass()->process($container);
        $container->compile();
        self::assertFalse($container->has(AccountPrincipal::class));
    }

    public function testNativeErrorPlaceholderCannotConstructAPrincipal(): void
    {
        $container = new ContainerBuilder();
        $id = '.errored..service_locator.fixture.'.AccountPrincipal::class;
        $container->register($id, AccountPrincipal::class)->addError('Principal is runtime data, unavailable as a service.');
        new ModuleServicesPass()->process($container);
        $this->expectException(\Symfony\Component\DependencyInjection\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Principal is runtime data, unavailable as a service.');
        $container->get($id);
    }

    public function testModuleCannotInjectNativeErrorPlaceholderThroughAlias(): void
    {
        $container = new ContainerBuilder();
        $id = '.errored..service_locator.fixture.'.AccountPrincipal::class;
        $container->register($id, AccountPrincipal::class)->addError('Principal is runtime data.');
        $container->setAlias('principal.alias', $id);
        $container->register(AccountUserProvider::class, AccountUserProvider::class)->setArgument(0, new Reference('principal.alias'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('module.services.data: "%s" [%s] is authentication principal data, not a service.', $id, AccountPrincipal::class));
        new ModuleServicesPass()->process($container);
    }

    public function testOtherUserInterfaceImplementationsRemainRequiredServices(): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture): void {
            $fixture->addModule($fixture->module);
            $class = 'App\\Module\\'.$fixture->module.'\\Infrastructure\\OtherPrincipal';
            $fixture->write('src/Module/'.$fixture->module.'/Infrastructure/OtherPrincipal.php', '<?php namespace App\\Module\\'.$fixture->module.'\\Infrastructure; '
                .'final class OtherPrincipal implements \\Symfony\\Component\\Security\\Core\\User\\UserInterface { '
                .'public function getUserIdentifier(): string { return "fixture"; } '
                .'public function getRoles(): array { return []; } }');
            $fixture->replace($fixture->configurationPath($fixture->module), "        exclude:\n", "        exclude:\n            - '../../Infrastructure/OtherPrincipal.php'\n");
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('module.inventory.service: '.$class.' was not registered.');
            $fixture->container()->compile();
        });
    }
}
