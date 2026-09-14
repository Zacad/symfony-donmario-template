<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Platform\Architecture\CheckPersistenceCommand;
use App\Platform\Architecture\ModuleMap;
use App\Platform\Architecture\PersistenceBoundaries;
use App\Tests\Fixtures\Persistence\MetadataFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

final class PersistenceBoundariesTest extends DatabaseTestCase
{
    public function testActualMigratedSchemaAndDatabaseCommandPassEvenWithAHideAllAssetFilter(): void
    {
        $connection = $this->database();
        $guard = $this->guard($connection);
        $configuration = $connection->getConfiguration();
        $originalFilter = $configuration->getSchemaAssetsFilter();
        $hidden = static fn (): bool => false;
        $configuration->setSchemaAssetsFilter($hidden);
        try {
            $command = new CommandTester(new CheckPersistenceCommand($guard));
            self::assertSame(Command::SUCCESS, $command->execute(['--database' => true]));
            self::assertStringContainsString('Persistence metadata, inventory and public database schema passed.', $command->getDisplay());
            self::assertSame($hidden, $configuration->getSchemaAssetsFilter());
        } finally {
            $configuration->setSchemaAssetsFilter($originalFilter);
        }
    }

    /** @param list<string> $statements */
    #[DataProvider('invalidSchemaChanges')]
    public function testUnfilteredSchemaViolationsAreDiagnosticAndTransactionallyCleanedUp(array $statements, string $diagnostic): void
    {
        $connection = $this->database();
        $guard = $this->guard($connection);
        $guard->assertDatabase();
        $configuration = $connection->getConfiguration();
        $originalFilter = $configuration->getSchemaAssetsFilter();
        $hidden = static fn (): bool => false;

        $connection->beginTransaction();
        try {
            foreach ($statements as $statement) {
                $connection->executeStatement($statement);
            }
            $configuration->setSchemaAssetsFilter($hidden);
            $this->assertViolation($guard->assertDatabase(...), $diagnostic);
            self::assertSame($hidden, $configuration->getSchemaAssetsFilter());
        } finally {
            $configuration->setSchemaAssetsFilter($originalFilter);
            $connection->rollBack();
        }

        self::assertFalse($connection->isTransactionActive());
        $guard->assertDatabase();
        self::assertNull($connection->fetchOne("SELECT to_regnamespace('boundary_other')"));
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidSchemaChanges(): iterable
    {
        yield 'unowned table hidden by asset filter' => [
            ['CREATE TABLE public.boundary_unowned (id integer PRIMARY KEY)'],
            'persistence.database.unexpected_table: public.boundary_unowned has no mapped owner.',
        ];
        yield 'module prefix alone does not confer ownership' => [
            ['CREATE TABLE public.task_tracking_unmapped (id integer PRIMARY KEY)'],
            'persistence.database.unexpected_table: public.task_tracking_unmapped has no mapped owner.',
        ];
        yield 'history exemption is exact' => [
            ['CREATE TABLE public.doctrine_migration_versions_extra (id integer PRIMARY KEY)'],
            'persistence.database.unexpected_table: public.doctrine_migration_versions_extra has no mapped owner.',
        ];
        yield 'technical prefix alone does not confer ownership' => [
            ['CREATE TABLE public.platform_messaging_message_extra (id integer PRIMARY KEY)'],
            'persistence.database.unexpected_table: public.platform_messaging_message_extra has no mapped owner.',
        ];
        yield 'transport table is required' => [
            ['DROP TABLE public.platform_messaging_message'],
            'persistence.database.missing_table: public.platform_messaging_message owned by Platform Messaging is missing.',
        ];
        yield 'unlogged transport table cannot retain durable obligations after a crash' => [
            ['ALTER TABLE public.platform_messaging_message SET UNLOGGED'],
            'persistence.database.transport_durability: public.platform_messaging_message must be a permanent logged table.',
        ];
        foreach ([
            'missing body' => 'DROP COLUMN body',
            'changed body type' => 'ALTER COLUMN body TYPE VARCHAR(100000)',
            'changed queue length' => 'ALTER COLUMN queue_name TYPE VARCHAR(191)',
            'changed nullability' => 'ALTER COLUMN headers DROP NOT NULL',
            'changed timestamp precision' => 'ALTER COLUMN created_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE',
            'changed timestamp timezone' => 'ALTER COLUMN available_at TYPE TIMESTAMP(0) WITH TIME ZONE',
            'unexpected default' => "ALTER COLUMN queue_name SET DEFAULT 'boundary-secret-default'",
            'extra column' => 'ADD COLUMN boundary_extra integer',
            'missing identity' => 'ALTER COLUMN id DROP IDENTITY',
            'changed identity mode' => 'ALTER COLUMN id SET GENERATED ALWAYS',
        ] as $case => $change) {
            yield 'transport '.$case => [
                ['ALTER TABLE public.platform_messaging_message '.$change],
                'persistence.database.transport_columns: public.platform_messaging_message differs from the approved transport columns.',
            ];
        }
        yield 'missing transport primary key' => [
            ['ALTER TABLE public.platform_messaging_message DROP CONSTRAINT platform_messaging_message_pkey'],
            'persistence.database.transport_indexes: public.platform_messaging_message differs from the approved transport indexes.',
        ];
        yield 'missing transport queue index' => [
            ['DROP INDEX public.platform_messaging_message_queue_idx'],
            'persistence.database.transport_indexes: public.platform_messaging_message differs from the approved transport indexes.',
        ];
        foreach ([
            'missing queue index column' => '(queue_name, available_at, delivered_at)',
            'changed queue index order' => '(available_at, queue_name, delivered_at, id)',
            'partial queue index' => '(queue_name, available_at, delivered_at, id) WHERE delivered_at IS NULL',
            'expression queue index' => '(lower(queue_name), available_at, delivered_at, id)',
        ] as $case => $definition) {
            yield 'transport '.$case => [
                [
                    'DROP INDEX public.platform_messaging_message_queue_idx',
                    'CREATE INDEX platform_messaging_message_queue_idx ON public.platform_messaging_message '.$definition,
                ],
                'persistence.database.transport_indexes: public.platform_messaging_message differs from the approved transport indexes.',
            ];
        }
        foreach ([
            'business to transport' => ['task_tracking_task', 'BIGINT', 'platform_messaging_message', 'id'],
            'transport to business' => ['platform_messaging_message', 'UUID', 'task_tracking_task', 'id'],
            'history to transport' => ['doctrine_migration_versions', 'BIGINT', 'platform_messaging_message', 'id'],
            'transport to history' => ['platform_messaging_message', 'VARCHAR(191)', 'doctrine_migration_versions', 'version'],
        ] as $case => [$source, $type, $target, $column]) {
            yield 'technical foreign key '.$case => [
                [
                    'ALTER TABLE public.'.$source.' ADD COLUMN boundary_foreign_id '.$type,
                    'ALTER TABLE public.'.$source.' ADD CONSTRAINT boundary_technical_fk FOREIGN KEY (boundary_foreign_id) REFERENCES public.'.$target.' ('.$column.') NOT VALID',
                ],
                'persistence.database.foreign_key.unowned: boundary_technical_fk links public.'.$source.' to public.'.$target.' without two mapped owners.',
            ];
        }
        yield 'partitioned table is inventoried' => [
            ['CREATE TABLE public.boundary_partitioned (id integer) PARTITION BY RANGE (id)'],
            'persistence.database.unexpected_table: public.boundary_partitioned has no mapped owner.',
        ];
        yield 'unsupported view' => [
            ['CREATE VIEW public.boundary_view AS SELECT 1 AS id'],
            'persistence.database.unsupported: public.boundary_view has unsupported relation kind v.',
        ];
        yield 'unsupported materialized view' => [
            ['CREATE MATERIALIZED VIEW public.boundary_materialized AS SELECT 1 AS id'],
            'persistence.database.unsupported: public.boundary_materialized has unsupported relation kind m.',
        ];
        yield 'missing mapped table' => [
            ['DROP TABLE public.task_tracking_task'],
            'persistence.database.missing_table: public.task_tracking_task owned by TaskTracking is missing.',
        ];
        yield 'same name in another schema does not satisfy mapping' => [
            ['CREATE SCHEMA boundary_other', 'ALTER TABLE public.task_tracking_task SET SCHEMA boundary_other'],
            'persistence.database.missing_table: public.task_tracking_task owned by TaskTracking is missing.',
        ];
        yield 'missing mapped column' => [
            ['ALTER TABLE public.task_tracking_task DROP COLUMN title'],
            'persistence.database.missing_column: public.task_tracking_task.title owned by TaskTracking is missing.',
        ];
        yield 'extra column is Doctrine schema drift' => [
            ['ALTER TABLE public.task_tracking_task ADD COLUMN boundary_extra integer'],
            'persistence.database.schema_drift: public.task_tracking_task differs from Doctrine metadata.',
        ];
        yield 'nullability is Doctrine schema drift' => [
            ['ALTER TABLE public.task_tracking_task ALTER COLUMN title DROP NOT NULL'],
            'persistence.database.schema_drift: public.task_tracking_task differs from Doctrine metadata.',
        ];
        yield 'extra index is Doctrine schema drift' => [
            ['CREATE INDEX boundary_extra_index ON public.task_tracking_task (title)'],
            'persistence.database.schema_drift: public.task_tracking_task differs from Doctrine metadata.',
        ];
    }

    public function testRawCrossModuleForeignKeyIsRejectedBetweenTwoKnownOwnedTables(): void
    {
        $connection = $this->database();
        $guard = $this->guard($connection);
        $guard->assertDatabase();
        $fixture = new MetadataFixture('Live', $connection);
        $configuration = $connection->getConfiguration();
        $originalFilter = $configuration->getSchemaAssetsFilter();
        $hidden = static fn (): bool => false;

        $filesystem = new Filesystem();
        $inventory = new ModuleMap(sys_get_temp_dir().'/persistence-boundaries-'.bin2hex(random_bytes(12)));
        $connection->beginTransaction();
        try {
            // Real metadata and synthetic probes need one physical inventory.
            // Symlink individual files to merge shared module namespaces while
            // preserving the guard's reflected source-path checks.
            foreach ([new ModuleMap(\dirname(__DIR__, 2)), $fixture->moduleMap] as $modules) {
                foreach ($modules->modules() as $module) {
                    $filesystem->mkdir($inventory->path($module));
                    $domain = $modules->path($module).'/Domain';
                    if (!is_dir($domain)) {
                        continue;
                    }
                    foreach (new Finder()->files()->in($domain)->name('*.php') as $file) {
                        $target = $inventory->path($module).'/Domain/'.$file->getRelativePathname();
                        self::assertFileDoesNotExist($target, 'Installed and synthetic entity sources must not overwrite each other.');
                        $filesystem->mkdir(\dirname($target));
                        $filesystem->symlink($file->getPathname(), $target);
                    }
                }
            }
            $connection->executeStatement('CREATE TABLE public.task_tracking_boundary_probe (id integer PRIMARY KEY, foreign_id integer DEFAULT NULL)');
            $connection->executeStatement('CREATE TABLE public.authorizing_boundary_probe (id integer PRIMARY KEY)');
            $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $metadata = array_merge($entityManager->getMetadataFactory()->getAllMetadata(), $fixture->metadata());
            $fixtureGuard = new PersistenceBoundaries($fixture->entityManager, $inventory);
            // Prove these are known, compatible tables before introducing the raw FK.
            $fixtureGuard->assertDatabase($metadata);

            $connection->executeStatement('ALTER TABLE public.task_tracking_boundary_probe ADD CONSTRAINT boundary_cross_module FOREIGN KEY (foreign_id) REFERENCES public.authorizing_boundary_probe (id)');
            $configuration->setSchemaAssetsFilter($hidden);
            $this->assertViolation(
                static fn () => $fixtureGuard->assertDatabase($metadata),
                'persistence.database.foreign_key.cross_module: boundary_cross_module links public.task_tracking_boundary_probe (TaskTracking) to public.authorizing_boundary_probe (Authorizing).',
            );
            self::assertSame($hidden, $configuration->getSchemaAssetsFilter());
        } finally {
            $configuration->setSchemaAssetsFilter($originalFilter);
            $connection->rollBack();
            $fixture->unregister();
            $filesystem->remove($inventory->projectDir);
        }

        self::assertFalse($connection->isTransactionActive());
        self::assertNull($connection->fetchOne("SELECT to_regclass('public.task_tracking_boundary_probe')"));
        self::assertNull($connection->fetchOne("SELECT to_regclass('public.authorizing_boundary_probe')"));
        self::assertSame(0, $connection->fetchOne("SELECT count(*) FROM pg_catalog.pg_constraint WHERE conname = 'boundary_cross_module'"));
        $guard->assertDatabase();
    }

    public function testForeignKeyToAnExcludedSchemaIsNotMistakenForASameModuleTable(): void
    {
        $connection = $this->database();
        $guard = $this->guard($connection);
        $guard->assertDatabase();
        $connection->beginTransaction();
        try {
            $connection->executeStatement('CREATE SCHEMA boundary_other');
            $connection->executeStatement('CREATE TABLE boundary_other.task_tracking_task (id UUID PRIMARY KEY)');
            $connection->executeStatement('ALTER TABLE public.task_tracking_task ADD CONSTRAINT boundary_foreign_schema FOREIGN KEY (id) REFERENCES boundary_other.task_tracking_task (id) NOT VALID');
            $this->assertViolation(
                $guard->assertDatabase(...),
                'persistence.database.foreign_key.unowned: boundary_foreign_schema links public.task_tracking_task to boundary_other.task_tracking_task without two mapped owners.',
            );
        } finally {
            $connection->rollBack();
        }

        self::assertNull($connection->fetchOne("SELECT to_regnamespace('boundary_other')"));
        $guard->assertDatabase();
    }

    private function guard(Connection $connection): PersistenceBoundaries
    {
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertSame($connection, $entityManager->getConnection());

        return new PersistenceBoundaries($entityManager, new ModuleMap(\dirname(__DIR__, 2)));
    }

    /** @param callable(): void $check */
    private function assertViolation(callable $check, string $diagnostic): void
    {
        try {
            $check();
        } catch (\LogicException $exception) {
            self::assertSame($diagnostic, $exception->getMessage());

            return;
        }

        self::fail('Expected ownership violation: '.$diagnostic);
    }
}
