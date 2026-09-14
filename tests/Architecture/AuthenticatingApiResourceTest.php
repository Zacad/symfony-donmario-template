<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Module\Authenticating\Application\GetAccountIdentity\GetAccountIdentityHandler;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use App\Module\Authenticating\UI\Api\AccountIdentityProvider;
use App\Module\Authenticating\UI\Api\AccountIdentityResource;
use App\Platform\Architecture\ContractTypes;
use App\Platform\Architecture\ModuleInventoryPass;
use App\Platform\Architecture\ModuleMap;
use App\Platform\Architecture\ModuleServicesPass;
use App\Tests\Fixtures\Inventory\InventoryFixture;
use App\Tests\Fixtures\Inventory\InventoryKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class AuthenticatingApiResourceTest extends TestCase
{
    public function testResourceClassificationIsExactAndKeepsPrincipalExceptionSeparate(): void
    {
        self::assertTrue(ContractTypes::isApiResource(AccountIdentityResource::class));
        self::assertSame('Authenticating', ModuleMap::owner(AccountIdentityResource::class));
        self::assertSame('UI', ModuleMap::layer(AccountIdentityResource::class));
        self::assertFalse(ContractTypes::isPublic(AccountIdentityResource::class));
        self::assertFalse(ContractTypes::isDataCandidate(AccountIdentityResource::class));
        self::assertFalse(ContractTypes::isAuthenticationPrincipal(AccountIdentityResource::class));
        self::assertNull(ContractTypes::messageKind(AccountIdentityResource::class));
        self::assertTrue(ContractTypes::isAuthenticationPrincipal(AccountPrincipal::class));
        foreach ([
            AccountPrincipal::class,
            AccountIdentityProvider::class,
            AccountIdentityResource::class.'Child',
            strtolower(AccountIdentityResource::class),
            '\\'.AccountIdentityResource::class,
            str_replace('Authenticating', 'Authorizing', AccountIdentityResource::class),
            str_replace('Api', 'Http', AccountIdentityResource::class),
            'ApiPlatform\\Metadata\\ApiResource',
        ] as $class) {
            self::assertFalse(ContractTypes::isApiResource($class), $class);
        }
    }

    public function testApplicationCompilesWithResourceExcludedAndProviderHandlerRegistered(): void
    {
        $container = new InventoryKernel(\dirname(__DIR__, 2))->createContainer();
        $resource = $container->getDefinition(AccountIdentityResource::class);
        self::assertTrue($resource->isAbstract());
        self::assertTrue($resource->hasTag('container.excluded'));
        foreach ([AccountIdentityProvider::class, GetAccountIdentityHandler::class] as $class) {
            $service = $container->getDefinition($class);
            self::assertFalse($service->isPublic());
            self::assertTrue($service->isAutowired());
            self::assertTrue($service->isAutoconfigured());
        }
        $container->compile();
        self::assertFalse($container->has(AccountIdentityResource::class));
    }

    public function testProviderStillRequiredByInventory(): void
    {
        $container = new InventoryKernel(\dirname(__DIR__, 2))->createContainer();
        $container->removeDefinition(AccountIdentityProvider::class);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('module.inventory.service: '.AccountIdentityProvider::class.' was not registered.');
        $container->compile();
    }

    public function testOtherMetadataBearingUiClassesDoNotGainAnInventoryExemption(): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture): void {
            $fixture->addModule($fixture->module);
            $class = 'App\\Module\\'.$fixture->module.'\\UI\\Api\\OtherResource';
            $fixture->write('src/Module/'.$fixture->module.'/UI/Api/OtherResource.php', '<?php namespace App\\Module\\'.$fixture->module.'\\UI\\Api; '
                .'#[\\ApiPlatform\\Metadata\\ApiResource] final readonly class OtherResource {}');
            $fixture->replace($fixture->configurationPath($fixture->module), "        exclude:\n", "        exclude:\n            - '../../UI/Api/OtherResource.php'\n");
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('module.inventory.service: '.$class.' was not registered.');
            $fixture->container()->compile();
        });
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
    public function testResourceCannotBecomeAService(string $wiring, bool $early): void
    {
        self::assertTrue(class_exists(AccountIdentityResource::class));
        $container = new ContainerBuilder();
        $definition = new Definition(str_contains($wiring, 'noncanonical') ? '\\'.strtolower(AccountIdentityResource::class) : AccountIdentityResource::class);
        $id = 'fake diagnostic' === $wiring ? '.errored..service_locator.fake.'.AccountIdentityResource::class : 'resource.named';
        if (str_starts_with($wiring, 'inline')) {
            $container->register('vendor.root', \stdClass::class)->setProperty('resource', new ServiceClosureArgument($definition));
        } else {
            $container->setDefinition($id, $definition);
            if ('alias' === $wiring) {
                $container->setAlias('resource.alias', $id)->setPublic(true);
                $container->register('vendor.root', \stdClass::class)->setProperty('resource', new Reference('resource.alias'));
            } elseif ('abstract' === $wiring) {
                $definition->setAbstract(true);
            } elseif ('concrete excluded' === $wiring) {
                $definition->addTag('container.excluded');
            } elseif ('fake diagnostic' === $wiring) {
                $definition->addError('Resource is not injectable.');
            }
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($early
            ? 'is API resource data and must be excluded from service registration.'
            : 'is API resource data, not a service.');
        if ($early) {
            new ModuleInventoryPass(new ModuleMap('/tmp/unused-api-resource-inventory'))->process($container);
        } else {
            new ModuleServicesPass()->process($container);
        }
    }

    public function testExcludedPlaceholderIsAcceptedByBothPasses(): void
    {
        $container = new ContainerBuilder();
        $container->register(AccountIdentityResource::class)->setAbstract(true)->addTag('container.excluded');
        new ModuleInventoryPass(new ModuleMap('/tmp/unused-api-resource-inventory'))->process($container);
        new ModuleServicesPass()->process($container);
        $container->compile();
        self::assertFalse($container->has(AccountIdentityResource::class));
    }
}
