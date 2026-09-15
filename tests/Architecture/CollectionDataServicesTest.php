<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Platform\Architecture\ModuleInventoryPass;
use App\Platform\Architecture\ModuleMap;
use App\Platform\Architecture\ModuleServicesPass;
use App\Tests\Fixtures\CollectionContracts\CollectionFixture;
use App\Tests\Fixtures\Inventory\InventoryFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class CollectionDataServicesTest extends TestCase
{
    public function testModulePrototypeExcludesInputsWhileKeepingNeighboringHandlers(): void
    {
        InventoryFixture::run(function (InventoryFixture $fixture): void {
            $fixture->addModule($fixture->module);
            $handler = $fixture->addUseCase($fixture->module);
            $class = 'App\\Module\\'.$fixture->module.'\\Application\\Lookup\\LookupInput';
            $fixture->write('src/Module/'.$fixture->module.'/Application/Lookup/LookupInput.php', '<?php namespace App\\Module\\'.$fixture->module.'\\Application\\Lookup; final readonly class LookupInput { /** @param list<string> $labels */ public function __construct(public array $labels) {} }');
            $container = $fixture->container();
            self::assertTrue($container->getDefinition($class)->hasTag('container.excluded'));
            self::assertTrue($container->getDefinition($class)->isAbstract());
            self::assertFalse($container->getDefinition($handler)->isPublic());
            self::assertTrue($container->getDefinition($handler)->isAutowired());
            $container->compile();
            self::assertFalse($container->has($class));
            self::assertTrue(class_exists($class));
        });
    }

    /** @return iterable<string, array{string, bool}> */
    public static function registrations(): iterable
    {
        foreach (['explicit', 'inline', 'alias', 'noncanonical', 'inline noncanonical', 'abstract', 'concrete excluded'] as $wiring) {
            foreach ([true, false] as $early) {
                yield $wiring.($early ? ' inventory' : ' services') => [$wiring, $early];
            }
        }
    }

    #[DataProvider('registrations')]
    public function testInputsCannotBeRegisteredAsServices(string $wiring, bool $early): void
    {
        $fixture = new CollectionFixture();
        try {
            $fixture->load();
            $class = $fixture->name('LeafInput');
            self::assertTrue(class_exists($class));
            $container = new ContainerBuilder();
            $definition = new Definition(str_contains($wiring, 'noncanonical') ? '\\'.strtolower($class) : $class);
            if (str_starts_with($wiring, 'inline')) {
                $container->register('vendor.root', \stdClass::class)->setProperty('input', new ServiceClosureArgument($definition));
            } else {
                $container->setDefinition('input.named', $definition);
                if ('alias' === $wiring) {
                    $container->setAlias('input.alias', 'input.named')->setPublic(true);
                    $container->register('vendor.root', \stdClass::class)->setProperty('input', new Reference('input.alias'));
                } elseif ('abstract' === $wiring) {
                    $definition->setAbstract(true);
                } elseif ('concrete excluded' === $wiring) {
                    $definition->addTag('container.excluded');
                }
            }
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage($early ? 'module.inventory.data:' : 'module.services.data:');
            if ($early) {
                new ModuleInventoryPass(new ModuleMap($fixture->root))->process($container);
            } else {
                new ModuleServicesPass()->process($container);
            }
        } finally {
            $fixture->close();
        }
    }
}
