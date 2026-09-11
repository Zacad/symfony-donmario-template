<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Persistence;

use App\Platform\Architecture\ModuleMap;
use Composer\Autoload\ClassLoader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;

/** Real attribute metadata, with fixture namespaces loaded only for these tests. */
final class MetadataFixture
{
    public readonly ModuleMap $moduleMap;
    public readonly EntityManager $entityManager;
    private readonly ClassLoader $loader;

    public function __construct(string $scenario = 'Valid', ?Connection $connection = null)
    {
        $this->moduleMap = new ModuleMap(__DIR__.'/'.$scenario);
        $this->loader = new ClassLoader();
        $this->loader->addPsr4('App\\Module\\', [__DIR__.'/'.$scenario.'/src/Module', __DIR__.'/Valid/src/Module']);
        $this->loader->register(true);
        $configuration = ORMSetup::createAttributeMetadataConfig([__DIR__.'/'.$scenario.'/src/Module'], true);
        $configuration->enableNativeLazyObjects(true);
        $this->entityManager = new EntityManager($connection ?? DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => '127.0.0.1',
            'port' => 1,
            'dbname' => 'offline_metadata',
            'user' => 'offline',
            'serverVersion' => '18',
        ]), $configuration);
    }

    /**
     * @param class-string ...$classes
     *
     * @return list<ClassMetadata<object>>
     */
    public function metadata(string ...$classes): array
    {
        if ([] === $classes) {
            return $this->entityManager->getMetadataFactory()->getAllMetadata();
        }

        return array_values(array_map($this->entityManager->getClassMetadata(...), $classes));
    }

    public function unregister(): void
    {
        $this->loader->unregister();
    }
}
