<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\Authorizing\Resources\migrations\Version20260915010000;
use App\Module\TaskTracking\Domain\Task;
use App\Module\TaskTracking\Resources\migrations\Version20260916010000;
use App\Module\TaskTracking\Resources\migrations\Version20260920010000;
use App\Platform\Persistence\MigrationCommandListener;
use App\Platform\Persistence\MigrationPreflight;
use App\Platform\Persistence\TimestampComparator;
use App\Tests\Fixtures\Migrations\MigrationFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application as FrameworkApplication;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Uuid;

final class MigrationsTest extends RepositoryTestCase
{
    public function testAuthorizingBaselineCreatesOnlyTheCleanSubjectSchema(): void
    {
        $connection = $this->database();
        MigrationPreflight::assertTestDatabase($connection);
        $history = $this->history($connection);

        $connection->beginTransaction();
        try {
            foreach (['authorizing_permission_grant', 'authorizing_role_assignment', 'authorizing_role_permission', 'authorizing_role'] as $table) {
                $connection->executeStatement('DROP TABLE public.'.$table);
            }

            $migration = new Version20260915010000($connection, new NullLogger());
            $migration->up(new Schema());
            $statements = $migration->getSql();
            foreach ($statements as $sql) {
                self::assertSame([], $sql->getParameters());
                self::assertSame([], $sql->getTypes());
                $connection->executeStatement($sql->getStatement());
            }

            self::assertSame([
                ['table_name' => 'authorizing_permission_grant', 'columns' => 'subject_id,permission_key'],
                ['table_name' => 'authorizing_role', 'columns' => 'role_key,label,revision,retired_at'],
                ['table_name' => 'authorizing_role_assignment', 'columns' => 'subject_id,role_key'],
                ['table_name' => 'authorizing_role_permission', 'columns' => 'role_key,permission_key'],
            ], $connection->fetchAllAssociative("SELECT table_name, string_agg(column_name, ',' ORDER BY ordinal_position) AS columns FROM information_schema.columns WHERE table_schema = 'public' AND table_name LIKE 'authorizing_%' GROUP BY table_name ORDER BY table_name"));
            self::assertSame([
                ['table_name' => 'authorizing_permission_grant', 'definition' => 'PRIMARY KEY (subject_id, permission_key)'],
                ['table_name' => 'authorizing_role', 'definition' => 'PRIMARY KEY (role_key)'],
                ['table_name' => 'authorizing_role_assignment', 'definition' => 'PRIMARY KEY (subject_id, role_key)'],
                ['table_name' => 'authorizing_role_permission', 'definition' => 'PRIMARY KEY (role_key, permission_key)'],
            ], $connection->fetchAllAssociative("SELECT c.conrelid::regclass::text AS table_name, pg_get_constraintdef(c.oid) AS definition FROM pg_catalog.pg_constraint c WHERE c.contype = 'p' AND c.conrelid IN ('public.authorizing_role'::regclass, 'public.authorizing_role_permission'::regclass, 'public.authorizing_role_assignment'::regclass, 'public.authorizing_permission_grant'::regclass) ORDER BY table_name"));
            self::assertSame([
                ['table_name' => 'authorizing_role_assignment', 'definition' => 'FOREIGN KEY (role_key) REFERENCES authorizing_role(role_key)'],
                ['table_name' => 'authorizing_role_permission', 'definition' => 'FOREIGN KEY (role_key) REFERENCES authorizing_role(role_key) ON DELETE CASCADE'],
            ], $connection->fetchAllAssociative("SELECT c.conrelid::regclass::text AS table_name, pg_get_constraintdef(c.oid) AS definition FROM pg_catalog.pg_constraint c WHERE c.contype = 'f' AND c.conrelid IN ('public.authorizing_role_permission'::regclass, 'public.authorizing_role_assignment'::regclass) ORDER BY table_name"));
            self::assertSame($history, $this->history($connection));
        } finally {
            $connection->rollBack();
        }
        self::assertSame($history, $this->history($connection));
    }

    public function testTaskOwnershipMigrationsPreserveLegacyRowsAndAddTheOwnerListIndex(): void
    {
        $connection = $this->database();
        MigrationPreflight::assertTestDatabase($connection);
        $history = $this->history($connection);
        $tasks = $this->tasks($connection);
        $saved = 'task10_saved_'.bin2hex(random_bytes(6));
        // PostgreSQL transactional DDL restores the real table, indexes and rows
        // even on assertion failure. No migration history or development data changes.
        $connection->beginTransaction();
        try {
            $connection->executeStatement('ALTER TABLE public.task_tracking_task RENAME TO '.$saved);
            $connection->executeStatement('ALTER INDEX public.task_tracking_task_owner_id_idx RENAME TO '.$saved.'_owner_id_idx');
            $connection->executeStatement('CREATE TABLE public.task_tracking_task (id UUID NOT NULL, title VARCHAR(200) NOT NULL, CONSTRAINT '.$saved.'_legacy_pkey PRIMARY KEY (id))');
            $id = Uuid::v7()->toRfc4122();
            $connection->executeStatement('INSERT INTO public.task_tracking_task (id, title) VALUES (?, ?)', [$id, 'legacy populated task 雪']);
            foreach ([new Version20260916010000($connection, new NullLogger()), new Version20260920010000($connection, new NullLogger())] as $migration) {
                $migration->up(new Schema());
                foreach ($migration->getSql() as $sql) {
                    self::assertSame([], $sql->getParameters());
                    self::assertSame([], $sql->getTypes());
                    $connection->executeStatement($sql->getStatement());
                }
            }
            self::assertSame([['id' => $id, 'title' => 'legacy populated task 雪', 'owner_account_id' => null, 'completed_at' => null]], $connection->fetchAllAssociative('SELECT id, title, owner_account_id, completed_at FROM public.task_tracking_task'));
            self::assertSame('timestamp(0) without time zone', $connection->fetchOne("SELECT format_type(atttypid, atttypmod) FROM pg_catalog.pg_attribute WHERE attrelid = 'public.task_tracking_task'::regclass AND attname = 'completed_at'"));
            self::assertSame(0, $connection->fetchOne("SELECT count(*) FROM pg_catalog.pg_constraint WHERE conrelid = 'public.task_tracking_task'::regclass AND contype = 'f'"));
            $index = $connection->fetchOne("SELECT indexdef FROM pg_catalog.pg_indexes WHERE schemaname = 'public' AND indexname = 'task_tracking_task_owner_id_idx'");
            self::assertIsString($index);
            self::assertStringContainsString('(owner_account_id, id)', $index);
            self::assertSame($history, $this->history($connection));
        } finally {
            $connection->rollBack();
        }
        self::assertSame($history, $this->history($connection));
        self::assertSame($tasks, $this->tasks($connection));
    }

    /** @return iterable<string, array{string, string}> */
    public static function configurationOverrides(): iterable
    {
        yield 'configuration file' => ['--configuration', '/tmp/untrusted-migrations.php'];
        yield 'connection' => ['--conn', 'readiness'];
        yield 'entity manager' => ['--em', 'other'];
    }

    #[DataProvider('configurationOverrides')]
    public function testConsoleOverridesCannotChangeTheValidatedMigrationTarget(string $option, string $value): void
    {
        $connection = $this->database();
        $history = $this->history($connection);
        $tasks = $this->tasks($connection);
        self::assertNotNull(self::$kernel);
        $application = new FrameworkApplication(self::$kernel);
        $application->setAutoExit(false);
        $runner = new ApplicationTester($application);
        self::assertSame(Command::FAILURE, $runner->run([
            'command' => 'doctrine:migrations:migrate', '--no-interaction' => true, $option => $value,
        ]));
        self::assertStringContainsString('migration.configuration:', $runner->getDisplay());
        self::assertSame($history, $this->history($connection));
        self::assertSame($tasks, $this->tasks($connection));
    }

    public function testProductionMigrateIsANoOpPreservingRealHistoryAndATask(): void
    {
        $connection = $this->database();
        MigrationPreflight::assertTestDatabase($connection);
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');
        self::assertInstanceOf(DependencyFactory::class, $factory);
        self::assertTrue($connection === $factory->getConnection(), 'Migrations must use the verified default connection.');
        self::assertInstanceOf(TimestampComparator::class, $factory->getVersionComparator());
        self::assertFalse($factory->getConfiguration()->isAllOrNothing());
        self::assertTrue($factory->getConfiguration()->isTransactional());
        self::assertSame([], $this->pendingVersions($factory), 'Main must apply application migrations before this test.');
        $history = $this->history($connection);
        self::assertNotEmpty($history);
        $tasks = $this->tasks($connection);
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $owner = Uuid::v7();
        $task = new Task('migration-no-op-marker', $owner);
        $task->complete(new \DateTimeImmutable('2026-09-16T12:34:56Z'));
        $id = $task->id();

        try {
            $this->repository()->add($task);
            $entityManager->flush();
            self::assertFalse($connection->isTransactionActive());
            $withMarker = $this->tasks($connection);
            self::assertNotNull(self::$kernel);
            $application = new FrameworkApplication(self::$kernel);
            $application->setAutoExit(false);
            $runner = new ApplicationTester($application);

            self::assertSame(Command::SUCCESS, $runner->run([
                'command' => 'doctrine:migrations:migrate',
                '--no-interaction' => true,
            ]), $runner->getDisplay());
            self::assertStringContainsString('Already at the latest version', $runner->getDisplay());
            self::assertSame([], $this->pendingVersions($factory));
            self::assertSame($history, $this->history($connection));
            self::assertSame($withMarker, $this->tasks($connection));

            $entityManager->clear();
            $reloaded = $this->repository()->find($id);
            self::assertInstanceOf(Task::class, $reloaded);
            self::assertNotSame($task, $reloaded);
            self::assertSame($id->toRfc4122(), $reloaded->id()->toRfc4122());
            self::assertSame('migration-no-op-marker', $reloaded->title());
            self::assertTrue($owner->equals($reloaded->ownerAccountId()));
            self::assertSame('2026-09-16 12:34:56', $reloaded->completedAt()?->format('Y-m-d H:i:s'));
        } finally {
            $entityManager->clear();
            $connection->close();
            MigrationPreflight::assertTestDatabase($connection);
            $connection->delete('public.task_tracking_task', ['id' => $id->toRfc4122()]);
        }

        self::assertSame($history, $this->history($connection));
        self::assertSame($tasks, $this->tasks($connection));
    }

    public function testChronologicalExecutionCommitsPredecessorRollsBackFailureAndRecovers(): void
    {
        $connection = $this->database();
        MigrationPreflight::assertTestDatabase($connection);
        self::assertFalse($connection->isTransactionActive());
        $mainHistory = $this->history($connection);
        $mainTasks = $this->tasks($connection);
        $fixture = new MigrationFixture();
        $schema = $fixture->schema;
        $created = false;

        try {
            $earlier = $fixture->addMigration('ZPreparing', 'Version20410101000000', [
                'CREATE TABLE '.$schema.'.predecessor (id integer PRIMARY KEY, value text NOT NULL)',
                "INSERT INTO $schema.predecessor VALUES (1, 'committed-predecessor')",
            ]);
            $correctedSql = [
                'CREATE TABLE '.$schema.'.later_result (id integer PRIMARY KEY, value text NOT NULL)',
                "INSERT INTO $schema.later_result SELECT id, 'consumed:' || value FROM $schema.predecessor",
                "UPDATE $schema.predecessor SET value = 'changed-by-later' WHERE id = 1",
            ];
            $failed = $fixture->addMigration('AFailing', 'Version20410102000000', [
                ...$correctedSql,
                // The diagnostic contains data read from the predecessor, not just a static sentinel.
                'DO $$ DECLARE observed text; BEGIN SELECT value INTO STRICT observed FROM '.$schema.'.later_result WHERE id = 1; RAISE EXCEPTION \'migration.fixture.deliberate_failure after %\', observed; END; $$',
            ]);
            $factory = $fixture->factory($connection);
            (new MigrationPreflight($fixture->inventory($factory), $factory, 'test'))->assertSafe();
            $connection->executeStatement('CREATE SCHEMA '.$connection->getDatabasePlatform()->quoteSingleIdentifier($schema));
            $created = true;
            self::assertSame([$earlier, $failed], $this->pendingVersions($factory));
            $runner = $this->runner($fixture, $factory);

            self::assertNotSame(Command::SUCCESS, $runner->run([
                'command' => 'doctrine:migrations:migrate',
                '--no-interaction' => true,
            ]), 'The real migration command must report failure.');
            $failure = (string) preg_replace('/\s+/', '', $runner->getDisplay());
            self::assertStringContainsString('SQLSTATE[P0001]', $failure);
            self::assertStringContainsString('migration.fixture.deliberate_failureafterconsumed:committed-predecessor', $failure);
            self::assertFalse($connection->isTransactionActive());

            // Reconnect: these effects/history must be committed, not visible only to the migrator session.
            $connection->close();
            MigrationPreflight::assertTestDatabase($connection);
            self::assertSame('committed-predecessor', $connection->fetchOne('SELECT value FROM '.$schema.'.predecessor WHERE id = 1'));
            self::assertNull($connection->fetchOne('SELECT to_regclass(?)', [$schema.'.later_result']));
            $preservedHistory = $this->history($connection, $schema.'.history');
            self::assertSame([$earlier], array_column($preservedHistory, 'version'));
            self::assertNotNull($preservedHistory[0]['executed_at']);
            self::assertSame([$failed], $this->pendingVersions($factory));

            // PHP cannot unload the failed class. Replace only its temporary file with a fresh corrected version.
            $fixture->removeMigration($failed);
            $recovered = $fixture->addMigration('ARecovering', 'Version20410103000000', $correctedSql);
            $recoveryFactory = $fixture->factory($connection);
            self::assertSame([$recovered], $this->pendingVersions($recoveryFactory));
            $recovery = $this->runner($fixture, $recoveryFactory);
            self::assertSame(Command::SUCCESS, $recovery->run([
                'command' => 'doctrine:migrations:migrate',
                '--no-interaction' => true,
            ]), $recovery->getDisplay());
            self::assertFalse($connection->isTransactionActive());
            $connection->close();
            MigrationPreflight::assertTestDatabase($connection);
            self::assertSame('consumed:committed-predecessor', $connection->fetchOne('SELECT value FROM '.$schema.'.later_result WHERE id = 1'));
            self::assertSame('changed-by-later', $connection->fetchOne('SELECT value FROM '.$schema.'.predecessor WHERE id = 1'));
            $recoveredHistory = $this->history($connection, $schema.'.history');
            self::assertCount(2, $recoveredHistory);
            self::assertEqualsCanonicalizing([$earlier, $recovered], array_column($recoveredHistory, 'version'));
            self::assertSame($preservedHistory, array_values(array_filter(
                $recoveredHistory,
                static fn (array $row): bool => $row['version'] === $earlier,
            )));
            self::assertSame([], $this->pendingVersions($recoveryFactory));

            $repeat = $this->runner($fixture, $fixture->factory($connection));
            self::assertSame(Command::SUCCESS, $repeat->run([
                'command' => 'doctrine:migrations:migrate',
                '--no-interaction' => true,
            ]), $repeat->getDisplay());
            self::assertStringContainsString('Already at the latest version', $repeat->getDisplay());
            self::assertSame($recoveredHistory, $this->history($connection, $schema.'.history'));
            self::assertSame('consumed:committed-predecessor', $connection->fetchOne('SELECT value FROM '.$schema.'.later_result WHERE id = 1'));
        } finally {
            try {
                // Also recover a leaked/aborted transaction on an assertion failure before dropping our schema.
                $connection->close();
                if ($created) {
                    MigrationPreflight::assertTestDatabase($connection);
                    $connection->executeStatement('DROP SCHEMA '.$connection->getDatabasePlatform()->quoteSingleIdentifier($schema).' CASCADE');
                }
            } finally {
                $fixture->cleanup();
            }
        }

        self::assertNull($connection->fetchOne('SELECT to_regnamespace(?)', [$schema]));
        self::assertNull($connection->fetchOne('SELECT to_regclass(?)', [$schema.'.history']));
        self::assertSame($mainHistory, $this->history($connection));
        self::assertSame($mainTasks, $this->tasks($connection));
    }

    public function testActualWrongDatabaseIsRefusedBeforeHistoryOrMigrationSql(): void
    {
        $connection = $this->database();
        MigrationPreflight::assertTestDatabase($connection);
        $mainHistory = $this->history($connection);
        $mainTasks = $this->tasks($connection);
        // Same verified disposable server and app credentials, never a development endpoint.
        $wrongDatabase = DriverManager::getConnection(array_replace($connection->getParams(), ['dbname' => 'postgres']));
        $fixture = new MigrationFixture();

        try {
            // A read-only transaction protects postgres even if the guard regresses.
            $wrongDatabase->beginTransaction();
            $wrongDatabase->executeStatement('SET TRANSACTION READ ONLY');
            self::assertSame('on', $wrongDatabase->fetchOne('SHOW transaction_read_only'));
            self::assertSame('postgres', $wrongDatabase->fetchOne('SELECT current_database()'));
            self::assertSame('app', $wrongDatabase->fetchOne('SELECT current_user'));
            self::assertNull($wrongDatabase->fetchOne('SELECT to_regnamespace(?)', [$fixture->schema]));
            $fixture->addMigration('Refusing', 'Version20420101000000', [
                'CREATE SCHEMA '.$fixture->schema,
                'CREATE TABLE '.$fixture->schema.'.should_not_exist (id integer)',
            ]);

            try {
                MigrationPreflight::assertTestDatabase($wrongDatabase);
                self::fail('The actual postgres database must be rejected, even on the isolated host as app.');
            } catch (\LogicException $exception) {
                self::assertSame('migration.target: tests require the isolated database/app_test connection as app.', $exception->getMessage());
            }

            $runner = $this->runner($fixture, $fixture->factory($wrongDatabase));
            self::assertSame(Command::FAILURE, $runner->run([
                'command' => 'doctrine:migrations:migrate',
                '--no-interaction' => true,
            ]));
            self::assertStringContainsString('migration.target:', $runner->getDisplay());
            // This query also proves the read-only transaction was not aborted by attempted DDL/history writes.
            self::assertNull($wrongDatabase->fetchOne('SELECT to_regnamespace(?)', [$fixture->schema]));
            self::assertNull($wrongDatabase->fetchOne('SELECT to_regclass(?)', [$fixture->schema.'.history']));
        } finally {
            $wrongDatabase->close();
            $fixture->cleanup();
        }

        self::assertNull($connection->fetchOne('SELECT to_regnamespace(?)', [$fixture->schema]));
        self::assertSame($mainHistory, $this->history($connection));
        self::assertSame($mainTasks, $this->tasks($connection));
    }

    private function runner(MigrationFixture $fixture, DependencyFactory $factory): ApplicationTester
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new MigrateCommand($factory, 'doctrine:migrations:migrate'));
        $listener = new MigrationCommandListener(new MigrationPreflight($fixture->inventory($factory), $factory, 'test'));
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ConsoleEvents::COMMAND, $listener->onCommand(...));
        $application->setDispatcher($dispatcher);

        return new ApplicationTester($application);
    }

    /** @return list<string> */
    private function pendingVersions(DependencyFactory $factory): array
    {
        $latest = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');

        return array_values(array_map(
            static fn (MigrationPlan $migration): string => (string) $migration->getVersion(),
            $factory->getMigrationPlanCalculator()->getPlanUntilVersion($latest)->getItems(),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function history(Connection $connection, string $table = 'public.doctrine_migration_versions'): array
    {
        $quoted = implode('.', array_map($connection->getDatabasePlatform()->quoteSingleIdentifier(...), explode('.', $table)));

        return $connection->fetchAllAssociative('SELECT version, executed_at, execution_time FROM '.$quoted.' ORDER BY version');
    }

    /** @return list<array<string, mixed>> */
    private function tasks(Connection $connection): array
    {
        return $connection->fetchAllAssociative('SELECT id, title, owner_account_id, completed_at FROM public.task_tracking_task ORDER BY id');
    }
}
