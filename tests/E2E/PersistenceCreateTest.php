<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Domain\Task;
use App\Module\TaskTracking\Infrastructure\Persistence\DoctrineTaskRepository;
use App\Platform\Event\Recording\RecordsDomainEvents;
use App\Platform\Messaging\CommandBus;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

final class PersistenceCreateTest extends RepositoryTestCase
{
    public function testCommandBusCommitsBeforeDatabaseRecreation(): void
    {
        $this->database();
        $commands = self::getContainer()->get(CommandBus::class);
        self::assertInstanceOf(CommandBus::class, $commands);
        $id = $commands->dispatch(new CreateTaskCommand('cqrs-before-recreation'));
        self::assertInstanceOf(Uuid::class, $id);
        new Filesystem()->dumpFile('var/e2e-cqrs-id', $id->toRfc4122());
    }

    public function testApplicationRoleCommitsAndReloadsAModuleOwnedTask(): void
    {
        $connection = $this->database();
        self::assertFalse($connection->fetchOne('SELECT rolsuper OR rolcreatedb OR rolcreaterole FROM pg_roles WHERE rolname = current_user'));
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertSame($connection, $manager->getConnection());
        $repository = $this->repository();
        self::assertInstanceOf(DoctrineTaskRepository::class, $repository);
        self::assertNull($manager->getClassMetadata(Task::class)->customRepositoryClassName);
        self::assertNull($repository->find(Uuid::v7()));
        $task = new Task('committed-before-recreation');
        self::assertInstanceOf(RecordsDomainEvents::class, $task);
        self::assertCount(1, $task->releaseEvents());
        self::assertFalse($manager->getClassMetadata(Task::class)->hasField('recordedDomainEvents'));
        $repository->add($task);
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM task_tracking_task WHERE id = ?', [$task->id()->toRfc4122()]), 'add() must not flush or commit.');
        $manager->flush();
        self::assertFalse($connection->isTransactionActive());
        $manager->clear();
        $reloaded = $repository->find($task->id());
        self::assertInstanceOf(Task::class, $reloaded);
        self::assertNotSame($task, $reloaded);
        self::assertSame($task->id()->toRfc4122(), $reloaded->id()->toRfc4122());
        self::assertSame('committed-before-recreation', $reloaded->title());
        self::assertSame([], $reloaded->releaseEvents(), 'Hydration neither replays creation nor persists transient recorded facts.');
        $manager->clear();
        $reference = $manager->getReference(Task::class, $task->id());
        self::assertInstanceOf(Task::class, $reference);
        self::assertSame('committed-before-recreation', $reference->title());
        self::assertSame([], $reference->releaseEvents(), 'Native-lazy initialization leaves the opt-in buffer empty.');
        (new Filesystem())->dumpFile('var/e2e-task-id', $task->id()->toRfc4122());
    }

    public function testReadinessConnectionSupportsTheConfiguredTimeouts(): void
    {
        $connection = $this->database('readiness');
        self::assertSame(2, $connection->getParams()['driverOptions'][\PDO::ATTR_TIMEOUT] ?? null);
        $connection->executeStatement('SET statement_timeout = 1000');
        $started = microtime(true);
        try {
            $connection->executeQuery('SELECT pg_sleep(3)');
            self::fail('PostgreSQL did not enforce the statement timeout.');
        } catch (Exception $exception) {
            self::assertStringContainsString('statement timeout', $exception->getMessage());
            self::assertLessThan(2.5, microtime(true) - $started);
        } finally {
            $connection->close();
        }
    }
}
