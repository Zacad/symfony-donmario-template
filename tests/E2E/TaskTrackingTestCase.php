<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use App\Module\TaskTracking\Application\CompleteTask\CompleteTaskCommand;
use App\Module\TaskTracking\Application\CompleteTask\CompleteTaskResult;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Application\ListTasks\ListTasksQuery;
use App\Module\TaskTracking\Application\ListTasks\ListTasksResult;
use App\Module\TaskTracking\Application\ListTasks\TaskListItemResult;
use App\Platform\Authorization\AuthorizationDenied;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\QueryBus;
use App\Tests\Fixtures\Authorizing\AuthorizingKernel;
use App\Tests\Fixtures\Authorizing\SqlLog;
use App\Tests\Fixtures\Cqrs\FaultControl;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

abstract class TaskTrackingTestCase extends DatabaseTestCase
{
    protected const string VIEW = 'task_tracking.task.view';
    protected const string COMPLETE = 'task_tracking.task.complete';
    protected const string CREATE = 'task_tracking.task.create';
    protected const array TABLES = ['authorizing_role_assignment', 'authorizing_permission_grant'];
    protected Connection $connection;
    protected Connection $observer;
    protected EntityManagerInterface $manager;
    protected CommandBus $commands;
    protected QueryBus $queries;
    protected FaultControl $fault;
    protected SqlLog $sql;
    protected string $prefix;
    /** @var list<Uuid> */
    protected array $accounts = [];

    protected static function getKernelClass(): string
    {
        return AuthorizingKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->database();
        $this->observer = DriverManager::getConnection($this->connection->getParams());
        self::assertSame('app_test', $this->observer->fetchOne('SELECT current_database()'));
        self::assertSame('app', $this->observer->fetchOne('SELECT current_user'));
        $container = self::getContainer();
        $manager = $container->get('doctrine.orm.default_entity_manager');
        $commands = $container->get('test.authorizing.commands');
        $queries = $container->get('test.authorizing.queries');
        $fault = $container->get('test.authorizing.fault');
        $sql = $container->get('test.authorizing.sql');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertInstanceOf(CommandBus::class, $commands);
        self::assertInstanceOf(QueryBus::class, $queries);
        self::assertInstanceOf(FaultControl::class, $fault);
        self::assertInstanceOf(SqlLog::class, $sql);
        $this->manager = $manager;
        $this->commands = $commands;
        $this->queries = $queries;
        $this->fault = $fault;
        $this->sql = $sql;
        $this->prefix = 'task10-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (isset($this->fault)) {
            $this->fault->afterAdd = $this->fault->afterFind = $this->fault->afterFindForCompletion = null;
            $this->sql->stop();
        }
        if (isset($this->observer)) {
            $this->connection->close();
            if ($this->observer->isTransactionActive()) {
                $this->observer->rollBack();
            }
            $this->observer->executeStatement('DELETE FROM platform_messaging_message WHERE body LIKE ?', ['%'.$this->prefix.'%']);
            $this->observer->executeStatement('DELETE FROM platform_messaging_message WHERE EXISTS (SELECT 1 FROM task_tracking_task t WHERE t.title LIKE ? AND body LIKE \'%\' || t.id::text || \'%\')', [$this->prefix.'%']);
            $this->observer->executeStatement('DELETE FROM task_tracking_task WHERE title LIKE ?', [$this->prefix.'%']);
            foreach ($this->accounts as $account) {
                foreach (self::TABLES as $table) {
                    $this->observer->executeStatement('DELETE FROM '.$table.' WHERE subject_id = ?', [$account->toRfc4122()]);
                }
                $this->observer->executeStatement('DELETE FROM authenticating_account WHERE id = ?', [$account->toRfc4122()]);
            }
            $this->observer->close();
        }
        parent::tearDown();
    }

    protected function account(): Uuid
    {
        $id = Uuid::v7();
        $this->accounts[] = $id;
        $this->observer->executeStatement('INSERT INTO authenticating_account (id, email, password_hash) VALUES (?, ?, ?)', [$id->toRfc4122(), $this->email($id), 'unused-task10-fixture']);

        return $id;
    }

    protected function email(Uuid $account): string
    {
        return $this->prefix.'-'.$account->toRfc4122().'@example.test';
    }

    protected function task(?Uuid $id = null, ?Uuid $ownerAccountId = null): Uuid
    {
        $id ??= Uuid::v7();
        $this->observer->executeStatement('INSERT INTO task_tracking_task (id, title, owner_account_id) VALUES (?, ?, ?)', [$id->toRfc4122(), $this->prefix.'-'.$id->toRfc4122(), $ownerAccountId?->toRfc4122()]);

        return $id;
    }

    protected function grant(Uuid $account, string $permission): void
    {
        $this->observer->executeStatement('INSERT INTO authorizing_permission_grant (subject_id, permission_key) VALUES (?, ?)', [$account->toRfc4122(), $permission]);
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    protected function asAccount(?Uuid $account, callable $operation): mixed
    {
        $requests = self::getContainer()->get('request_stack');
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(RequestStack::class, $requests);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        self::assertNull($requests->getMainRequest());
        $previous = $tokens->getToken();
        $requests->push(Request::create('/_demo/tasks'));
        $tokens->setToken(null === $account ? null : new UsernamePasswordToken(new AccountPrincipal($account, $this->email($account), 'unused-task10-fixture'), 'main', []));
        try {
            return $operation();
        } finally {
            $tokens->setToken($previous);
            $requests->pop();
        }
    }

    protected function create(Uuid $account, string $suffix = 'owned'): Uuid
    {
        $result = $this->asAccount($account, fn () => $this->commands->dispatch(new CreateTaskCommand($this->prefix.'-'.$suffix, $account)));
        self::assertInstanceOf(Uuid::class, $result);

        return $result;
    }

    protected function page(Uuid $account, int $limit = 50, ?string $after = null): ListTasksResult
    {
        $result = $this->asAccount($account, fn () => $this->queries->ask(new ListTasksQuery($account, $limit, $after)));
        self::assertInstanceOf(ListTasksResult::class, $result);

        return $result;
    }

    /** @return list<string> */
    protected function ids(ListTasksResult $result): array
    {
        return array_map(static fn (TaskListItemResult $task): string => $task->id->toRfc4122(), $result->tasks);
    }

    protected function complete(Uuid $account, Uuid $task): ?CompleteTaskResult
    {
        $result = $this->asAccount($account, fn () => $this->commands->dispatch(new CompleteTaskCommand($task->toRfc4122())));
        self::assertTrue(null === $result || $result instanceof CompleteTaskResult);

        return $result;
    }

    /** @param class-string<\Throwable> $type */
    protected function fails(callable $operation, string $type = AuthorizationDenied::class): \Throwable
    {
        try {
            $operation();
        } catch (\Throwable $failure) {
            if ($failure instanceof AssertionFailedError) {
                throw $failure;
            }
            self::assertInstanceOf($type, $failure);

            return $failure;
        }
        self::fail('Expected protected operation to fail.');
    }

    /** @param list<string> $arguments */
    protected function cli(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, 'bin/console', '--env=test', '--no-debug', '--no-interaction', '--no-ansi', ...$arguments], dirname(__DIR__, 2), timeout: 25);
        $process->run();

        return $process;
    }

    /** @return array<string, mixed> */
    protected function json(Process $process): array
    {
        self::assertSame(0, $process->getExitCode(), 'Task CLI must succeed (diagnostics intentionally not dumped).');
        self::assertSame('', $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $object = [];
        foreach ($result as $key => $value) {
            self::assertIsString($key);
            $object[$key] = $value;
        }

        return $object;
    }

    protected function fixedFailure(Process $process, int $code, string $message = 'Invalid task input.'): void
    {
        self::assertSame($code, $process->getExitCode());
        self::assertSame($message."\n", $process->getOutput());
        self::assertStringNotContainsString('task10-private-canary', $process->getErrorOutput());
    }
}
