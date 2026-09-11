<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Migrations;

use App\Platform\Architecture\ModuleMap;
use App\Platform\Persistence\MigrationInventory;
use App\Platform\Persistence\TimestampComparator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\Version\Comparator;
use Symfony\Component\Filesystem\Filesystem;

/** Real module files outside the immutable source snapshot, unique even in one PHP process. */
final class MigrationFixture
{
    public readonly ModuleMap $modules;
    public readonly string $schema;
    private readonly Filesystem $filesystem;
    private readonly string $suffix;

    /** @var array<string, string> */
    private array $directories = [];

    /** @var array<string, string> */
    private array $files = [];

    public function __construct()
    {
        $this->suffix = bin2hex(random_bytes(8));
        $this->schema = 'migration_e2e_'.$this->suffix;
        $this->filesystem = new Filesystem();
        $this->modules = new ModuleMap(sys_get_temp_dir().'/migration-fixture-'.$this->suffix);
        $this->filesystem->mkdir($this->modules->projectDir.'/src/Module');
    }

    /** @param list<string> $statements */
    public function addMigration(string $module, string $version, array $statements = [], bool $transactional = true): string
    {
        $module .= $this->suffix.'ing';
        $namespace = 'App\\Module\\'.$module.'\\Resources\\migrations';
        $directory = $this->modules->path($module).'/Resources/migrations';
        $class = $namespace.'\\'.$version;
        $file = $directory.'/'.$version.'.php';
        $source = strtr($this->filesystem->readFile(__DIR__.'/migration.php.fixture'), [
            '{{namespace}}' => $namespace,
            '{{version}}' => $version,
            '{{transactional}}' => $transactional ? 'true' : 'false',
            '{{statements}}' => var_export($statements, true),
        ]);
        $this->filesystem->dumpFile($file, $source);
        $this->directories[$namespace] = $directory;
        $this->files[$class] = $file;

        return $class;
    }

    public function file(string $class): string
    {
        return $this->files[$class];
    }

    public function removeMigration(string $class): void
    {
        $this->filesystem->remove($this->file($class));
        unset($this->files[$class]);
    }

    /** @return array<string, string> */
    public function directories(): array
    {
        return $this->directories;
    }

    /** @param array<string, string>|null $directories */
    public function factory(?Connection $connection = null, ?array $directories = null): DependencyFactory
    {
        $connection ??= DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => 'migration-inventory.invalid',
            'dbname' => 'app_test',
            'user' => 'app',
            // AbstractMigration constructs a schema manager; this avoids server discovery/IO.
            'serverVersion' => '18',
        ]);
        $configuration = new Configuration();
        $configuration->setAllOrNothing(false);
        $configuration->setTransactional(true);
        foreach ($directories ?? $this->directories as $namespace => $directory) {
            $configuration->addMigrationsDirectory($namespace, $directory);
        }
        $storage = new TableMetadataStorageConfiguration();
        $storage->setTableName($this->schema.'.history');
        $configuration->setMetadataStorageConfiguration($storage);
        $factory = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($connection));
        $factory->setService(Comparator::class, new TimestampComparator());

        return $factory;
    }

    public function inventory(DependencyFactory $factory): MigrationInventory
    {
        return new MigrationInventory($factory, $this->modules);
    }

    public function cleanup(): void
    {
        $this->filesystem->remove($this->modules->projectDir);
    }
}
