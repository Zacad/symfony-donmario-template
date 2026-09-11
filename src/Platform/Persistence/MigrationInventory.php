<?php

declare(strict_types=1);

namespace App\Platform\Persistence;

use App\Platform\Architecture\ModuleMap;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MigrationInventory
{
    public function __construct(
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private DependencyFactory $factory,
        private ModuleMap $modules,
    ) {
    }

    /** Validate before any history initialization or DDL; requires no database connection. */
    public function assertValid(): void
    {
        $files = [];
        $timestamps = [];
        foreach ($this->modules->modules() as $module) {
            $directory = $this->modules->path($module).'/Resources/migrations';
            if (!is_dir($directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if (!$entry instanceof \SplFileInfo || !$entry->isFile() || 'php' !== $entry->getExtension()) {
                    continue;
                }
                $file = $entry->getPathname();
                if (dirname($file) !== $directory) {
                    throw new \LogicException('migration.layout: '.$file.' must be directly inside its module migration directory.');
                }
                $class = 'App\\Module\\'.$module.'\\Resources\\migrations\\'.basename($file, '.php');
                $timestamp = TimestampComparator::timestamp($class);
                if (isset($timestamps[$timestamp])) {
                    throw new \LogicException('migration.duplicate: '.$class.' shares a timestamp with '.$timestamps[$timestamp].'.');
                }
                $timestamps[$timestamp] = $class;
                $files[$class] = realpath($file);
            }
        }
        if ([] === $files) {
            throw new \LogicException('migration.inventory.empty: no module migrations found.');
        }
        $registered = [];
        foreach ($this->factory->getMigrationRepository()->getMigrations()->getItems() as $migration) {
            $class = (string) $migration->getVersion();
            if (!isset($files[$class]) || (new \ReflectionObject($migration->getMigration()))->getFileName() !== $files[$class]) {
                throw new \LogicException('migration.inventory.unowned: '.$class.' does not match a module migration file.');
            }
            if (!$migration->getMigration()->isTransactional()) {
                throw new \LogicException('migration.transaction: '.$class.' must be transactional.');
            }
            $registered[$class] = true;
        }
        foreach ($files as $class => $file) {
            if (!isset($registered[$class])) {
                throw new \LogicException('migration.inventory.unregistered: '.$class.' is absent from configured migration paths.');
            }
        }
    }
}
