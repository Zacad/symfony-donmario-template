<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Application\GetTask\GetTaskQuery;
use App\Module\TaskTracking\Application\GetTask\GetTaskResult;
use App\Module\TaskTracking\Domain\Task;
use App\Module\TaskTracking\Domain\TaskRepository;
use App\Module\TaskTracking\Infrastructure\Testing\FaultTaskRepository;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\QueryBus;
use App\Tests\Fixtures\Authenticating\Browser;
use App\Tests\Fixtures\Cqrs\CqrsKernel;
use App\Tests\Fixtures\Cqrs\FaultControl;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CqrsTest extends DatabaseTestCase
{
    private Connection $connection;
    private Connection $observer;
    private EntityManagerInterface $manager;
    private CommandBus $commands;
    private QueryBus $queries;
    private TaskRepository $repository;
    private FaultControl $fault;
    private string $prefix;
    private Uuid $actorId;
    private RequestStack $requests;
    private TokenStorageInterface $tokens;

    protected static function getKernelClass(): string
    {
        return CqrsKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->database();
        $this->observer = DriverManager::getConnection($this->connection->getParams());
        self::assertSame('app_test', $this->observer->fetchOne('SELECT current_database()'));
        $container = self::getContainer();
        $manager = $container->get('doctrine.orm.default_entity_manager');
        $commands = $container->get('test.cqrs.commands');
        $queries = $container->get('test.cqrs.queries');
        $repository = $container->get('test.cqrs.repository');
        $fault = $container->get('test.cqrs.fault');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertInstanceOf(CommandBus::class, $commands);
        self::assertInstanceOf(QueryBus::class, $queries);
        self::assertInstanceOf(TaskRepository::class, $repository);
        self::assertInstanceOf(FaultTaskRepository::class, $repository);
        self::assertInstanceOf(FaultControl::class, $fault);
        $this->manager = $manager;
        $this->commands = $commands;
        $this->queries = $queries;
        $this->repository = $repository;
        $this->fault = $fault;
        $this->prefix = 'cqrs-'.bin2hex(random_bytes(8)).'-';
        $this->actorId = Uuid::v7();
        $this->observer->executeStatement('INSERT INTO authenticating_account (id, email, password_hash) VALUES (?, ?, ?)', [$this->actorId->toRfc4122(), $this->prefix.'@example.test', 'unused-cqrs-fixture']);
        foreach (['task_tracking.task.create', 'task_tracking.task.view'] as $permission) {
            $this->observer->executeStatement('INSERT INTO authorizing_permission_grant (subject_id, permission_key) VALUES (?, ?)', [$this->actorId->toRfc4122(), $permission]);
        }
        $requests = $container->get('request_stack');
        $tokens = $container->get('security.token_storage');
        self::assertInstanceOf(RequestStack::class, $requests);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $this->requests = $requests;
        $this->tokens = $tokens;
        // Direct bus verification uses the same native identity sources as HTTP.
        // Permissions still come from the real voter and isolated PostgreSQL rows.
        $this->requests->push(Request::create('/_demo/tasks'));
        $this->tokens->setToken(new UsernamePasswordToken(new AccountPrincipal($this->actorId, $this->prefix.'@example.test', 'unused-cqrs-fixture'), 'main', []));
    }

    protected function tearDown(): void
    {
        if (isset($this->tokens)) {
            $this->tokens->setToken(null);
            $this->requests->pop();
        }
        if (isset($this->observer)) {
            $this->observer->executeStatement('DELETE FROM task_tracking_task WHERE title LIKE ?', [$this->prefix.'%']);
            if (isset($this->actorId)) {
                $this->observer->executeStatement('DELETE FROM authorizing_permission_grant WHERE subject_id = ?', [$this->actorId->toRfc4122()]);
                $this->observer->executeStatement('DELETE FROM authenticating_account WHERE id = ?', [$this->actorId->toRfc4122()]);
            }
            $this->observer->close();
        }
        parent::tearDown();
    }

    public function testHttpAndCliShareUseCasesAndCommittedResults(): void
    {
        $http = $this->authenticatedHttp();
        $title = $this->prefix."<error>title</error>\n\x1b[31m";
        $response = $http->request('POST', '/_demo/tasks', ['json' => ['title' => $title]]);
        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $id = $response->toArray()['id'];
        self::assertIsString($id);
        self::assertTrue(Uuid::isValid($id));
        self::assertSame('/_demo/tasks/'.$id, $response->getHeaders()['location'][0]);
        self::assertStringContainsString('no-store', $response->getHeaders()['cache-control'][0]);
        self::assertSame($title, $this->observer->fetchOne('SELECT title FROM task_tracking_task WHERE id = ?', [$id]));
        $show = $this->cli(['app:task:show', $id]);
        self::assertSame(0, $show->getExitCode(), $show->getErrorOutput());
        self::assertSame(['id' => $id, 'title' => $title, 'ownerAccountId' => $this->actorId->toRfc4122(), 'completedAt' => null], json_decode($show->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString("\x1b", $show->getOutput());

        $create = $this->cli(['app:task:create', $this->prefix.'from-cli']);
        self::assertSame(0, $create->getExitCode(), $create->getErrorOutput());
        $cliId = trim($create->getOutput());
        self::assertTrue(Uuid::isValid($cliId));
        self::assertSame(403, $http->request('GET', '/_demo/tasks/'.$cliId)->getStatusCode(), 'An account cannot view an operator-created unowned task.');
        self::assertSame(403, $http->request('GET', '/_demo/tasks/'.Uuid::v7())->getStatusCode(), 'Missing tasks deny before the handler to avoid disclosing existence.');
        self::assertSame(1, $this->cli(['app:task:show', Uuid::v7()->toRfc4122()])->getExitCode());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTitles(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [" \t\n"];
        yield 'over limit' => [str_repeat('é', 201)];
        yield 'invalid UTF-8' => ["invalid\xff"];
        yield 'NUL' => ["invalid\0title"];
    }

    #[DataProvider('invalidTitles')]
    public function testInvalidTitleFailsWithoutCallingPersistence(string $title): void
    {
        $this->fault->afterAdd = static function (): never {
            self::fail('Validation must prevent persistence.');
        };
        try {
            $this->commands->dispatch(new CreateTaskCommand($title, $this->actorId));
            self::fail('Expected validation failure.');
        } catch (ValidationFailedException $failure) {
            self::assertGreaterThan(0, count($failure->getViolations()));
            $violation = $failure->getViolations()[0];
            self::assertNotNull($violation);
            self::assertSame('title', $violation->getPropertyPath());
        }
        self::assertFalse($this->connection->isTransactionActive());
        if (!str_contains($title, "\0")) {
            $cli = $this->cli(['app:task:create', $title]);
            self::assertSame(2, $cli->getExitCode(), $cli->getOutput().$cli->getErrorOutput());
        }
    }

    public function testValidationBoundariesAndUnmodifiedTitle(): void
    {
        foreach (['0', str_repeat('é', 200), '  preserved  '] as $title) {
            $id = $this->commands->dispatch(new CreateTaskCommand($title, $this->actorId));
            self::assertInstanceOf(Uuid::class, $id);
            $result = $this->queries->ask(new GetTaskQuery($id->toRfc4122()));
            self::assertInstanceOf(GetTaskResult::class, $result);
            self::assertSame($title, $result->title);
            $this->observer->executeStatement('DELETE FROM task_tracking_task WHERE id = ?', [$id->toRfc4122()]);
        }
        self::assertSame(2, $this->cli(['app:task:show', 'bad-id'])->getExitCode());
    }

    public function testHttpInputErrorsAreBoundedAndSafe(): void
    {
        $http = $this->authenticatedHttp();
        foreach (['{', '{}', '[]', '{"title":3}', '{"title":null}', '{"title":"valid","admin":true}'] as $body) {
            self::assertSame(400, $http->request('POST', '/_demo/tasks', ['headers' => ['Content-Type' => 'application/json'], 'body' => $body])->getStatusCode());
        }
        self::assertSame(415, $http->request('POST', '/_demo/tasks', ['body' => 'title=test'])->getStatusCode());
        self::assertSame(413, $http->request('POST', '/_demo/tasks', ['headers' => ['Content-Type' => 'application/json'], 'body' => str_repeat(' ', 4097)])->getStatusCode());
        $chunks = static function (): \Generator {
            yield str_repeat(' ', 2048);
            yield str_repeat(' ', 2049);
        };
        self::assertSame(413, $http->request('POST', '/_demo/tasks', ['headers' => ['Content-Type' => 'application/json'], 'body' => $chunks()])->getStatusCode());
        foreach (['', " \n", str_repeat('x', 201), "NUL\0value"] as $title) {
            $response = $http->request('POST', '/_demo/tasks', ['json' => ['title' => $title]]);
            self::assertSame(422, $response->getStatusCode());
            self::assertSame('validation_failed', $response->toArray(false)['error']);
            $violations = $response->toArray(false)['violations'];
            self::assertIsArray($violations);
            self::assertIsArray($violations[0]);
            self::assertArrayNotHasKey('value', $violations[0]);
        }
        self::assertSame(422, $http->request('GET', '/_demo/tasks/not-a-uuid')->getStatusCode());
    }

    /** @return iterable<string, array{bool}> */
    public static function failureStages(): iterable
    {
        yield 'after persistence scheduling' => [false];
        yield 'after actual SQL flush' => [true];
    }

    #[DataProvider('failureStages')]
    public function testRollbackAndSameProcessRecovery(bool $flush): void
    {
        $managerIdentity = spl_object_id($this->manager);
        $this->fault->afterAdd = function (Task $task) use ($flush): never {
            if ($flush) {
                $this->manager->flush();
                self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM task_tracking_task WHERE id = ?', [$task->id()->toRfc4122()]));
            }
            self::assertSame(0, $this->countRows());
            throw new \RuntimeException('synthetic handler failure');
        };
        $this->assertFails(fn () => $this->commands->dispatch(new CreateTaskCommand($this->prefix.'failed', $this->actorId)), 'synthetic handler failure');
        self::assertSame(0, $this->countRows());
        self::assertFalse($this->connection->isTransactionActive());
        $this->fault->afterAdd = null;
        $id = $this->commands->dispatch(new CreateTaskCommand($this->prefix.'recovered', $this->actorId));
        self::assertInstanceOf(Uuid::class, $id);
        self::assertSame($managerIdentity, spl_object_id($this->manager));
        self::assertTrue($this->manager->isOpen());
        self::assertSame(1, $this->countRows());
        self::assertSame($this->prefix.'recovered', $this->repository->find($id)?->title());
        self::assertInstanceOf(GetTaskResult::class, $this->queries->ask(new GetTaskQuery($id->toRfc4122())));
    }

    public function testNestedCommandsCommitTogether(): void
    {
        $this->fault->afterAdd = function (): void {
            $this->fault->afterAdd = null;
            self::assertInstanceOf(Uuid::class, $this->commands->dispatch(new CreateTaskCommand($this->prefix.'inner', $this->actorId)));
            self::assertTrue($this->connection->isTransactionActive());
            self::assertSame(0, $this->countRows(), 'Nested results must not commit independently.');
        };
        $this->commands->dispatch(new CreateTaskCommand($this->prefix.'outer', $this->actorId));
        self::assertSame(2, $this->countRows());
    }

    public function testExternalTransactionIsRejectedAndNotCommitted(): void
    {
        $this->connection->beginTransaction();
        try {
            $this->assertFails(fn () => $this->commands->dispatch(new CreateTaskCommand($this->prefix.'external', $this->actorId)), 'cqrs.transaction');
            self::assertTrue($this->connection->isTransactionActive());
            self::assertSame(0, $this->countRows());
        } finally {
            $this->connection->rollBack();
        }
        $this->commands->dispatch(new CreateTaskCommand($this->prefix.'normal', $this->actorId));
        self::assertSame(1, $this->countRows());
    }

    public function testTransactionStartupFailureResetsDbalStateForNextDispatch(): void
    {
        // Deliberately desynchronize native PDO and DBAL to force beginTransaction
        // itself to fail against the real server, before ORM's cleanup callback.
        $native = $this->connection->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        $native->beginTransaction();
        try {
            $this->assertFails(fn () => $this->commands->dispatch(new CreateTaskCommand($this->prefix.'begin-failure', $this->actorId)), 'already an active transaction');
            self::assertFalse($this->connection->isTransactionActive());
            self::assertFalse($this->connection->isConnected());
        } finally {
            if ($native->inTransaction()) {
                $native->rollBack();
            }
        }
        $this->commands->dispatch(new CreateTaskCommand($this->prefix.'reconnected', $this->actorId));
        self::assertSame(1, $this->countRows());
    }

    /** @return iterable<string, array{string}> */
    public static function nestedFailures(): iterable
    {
        yield 'caught validation failure' => ['validation'];
        yield 'caught handler failure' => ['handler'];
        yield 'outer failure after inner success' => ['outer'];
        yield 'caught query failure' => ['query'];
    }

    #[DataProvider('nestedFailures')]
    public function testNestedFailuresCannotBeSwallowedToCommit(string $mode): void
    {
        $this->fault->afterAdd = function () use ($mode): void {
            $this->fault->afterAdd = 'handler' === $mode ? static function (): never { throw new \RuntimeException('nested failure'); } : null;
            try {
                if ('query' === $mode) {
                    $this->queries->ask(new GetTaskQuery('invalid'));
                } else {
                    $this->commands->dispatch(new CreateTaskCommand('validation' === $mode ? '' : $this->prefix.'inner', $this->actorId));
                }
            } catch (\Throwable) {
                // Intentionally swallowed to establish the rollback-only contract.
            }
            if ('outer' === $mode) {
                throw new \RuntimeException('outer failure');
            }
        };
        $failure = null;
        try {
            $this->commands->dispatch(new CreateTaskCommand($this->prefix.'outer', $this->actorId));
        } catch (\Throwable $caught) {
            $failure = $caught;
        }
        self::assertNotNull($failure, 'A failed nested invocation must prevent commit.');
        if (in_array($mode, ['validation', 'query'], true)) {
            self::assertInstanceOf(ValidationFailedException::class, $failure);
        } else {
            self::assertSame(('outer' === $mode ? 'outer' : 'nested').' failure', $failure->getMessage());
        }
        self::assertSame(0, $this->countRows());
        $this->fault->afterAdd = null;
        $this->commands->dispatch(new CreateTaskCommand($this->prefix.'recovery', $this->actorId));
        self::assertSame(1, $this->countRows());
    }

    public function testQueryCannotDispatchCommandsAndCannotLeakScheduledChanges(): void
    {
        $taskId = $this->commands->dispatch(new CreateTaskCommand($this->prefix.'target', $this->actorId));
        self::assertInstanceOf(Uuid::class, $taskId);
        $calls = 0;
        $this->fault->afterFind = function () use (&$calls): void {
            if (2 === ++$calls) {
                $this->commands->dispatch(new CreateTaskCommand($this->prefix.'forbidden', $this->actorId));
            }
        };
        $this->assertFails(fn () => $this->queries->ask(new GetTaskQuery($taskId->toRfc4122())), 'cqrs.query_write');
        self::assertSame(1, $this->countRows());
        $calls = 0;
        $this->fault->afterFind = function () use (&$calls): void {
            if (2 === ++$calls) {
                $this->manager->persist(new Task($this->prefix.'unflushed'));
            }
        };
        self::assertInstanceOf(GetTaskResult::class, $this->queries->ask(new GetTaskQuery($taskId->toRfc4122())));
        self::assertSame(1, $this->countRows());
        $this->fault->afterFind = null;
        $this->commands->dispatch(new CreateTaskCommand($this->prefix.'valid', $this->actorId));
        self::assertSame(2, $this->countRows(), 'The query must not flush or retain scheduled entities.');
    }

    public function testNestedQueryDoesNotClearTheParentUnitOfWork(): void
    {
        $this->fault->afterAdd = function (Task $task): void {
            self::assertInstanceOf(GetTaskResult::class, $this->queries->ask(new GetTaskQuery($task->id()->toRfc4122())));
            self::assertTrue($this->connection->isTransactionActive());
        };
        $this->commands->dispatch(new CreateTaskCommand($this->prefix.'retained', $this->actorId));
        self::assertSame(1, $this->countRows());
    }

    public function testDeferredPostgresqlFailureRollsBackAndAdaptersRedactIt(): void
    {
        $title = $this->prefix.'commit-failure';
        $this->connection->executeStatement("CREATE FUNCTION public.cqrs_test_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.title = TG_ARGV[0] THEN RAISE EXCEPTION ''synthetic private SQL detail''; END IF; RETURN NEW; END'");
        try {
            $this->connection->executeStatement('CREATE CONSTRAINT TRIGGER cqrs_test_failure AFTER INSERT ON task_tracking_task DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.cqrs_test_failure('.$this->connection->quote($title).')');
            $this->assertFails(fn () => $this->commands->dispatch(new CreateTaskCommand($title, $this->actorId)), 'synthetic private SQL detail');
            self::assertSame(0, $this->countRows());
            $response = $this->authenticatedHttp()->request('POST', '/_demo/tasks', ['json' => ['title' => $title]]);
            self::assertSame(500, $response->getStatusCode());
            self::assertSame(['error' => 'operation_failed'], $response->toArray(false));
            $cli = $this->cli(['app:task:create', $title]);
            self::assertSame(1, $cli->getExitCode());
            self::assertStringNotContainsString('synthetic private SQL detail', $cli->getOutput().$cli->getErrorOutput());
            self::assertSame(0, $this->countRows());
        } finally {
            $this->connection->executeStatement('DROP TRIGGER IF EXISTS cqrs_test_failure ON task_tracking_task');
            $this->connection->executeStatement('DROP FUNCTION public.cqrs_test_failure()');
        }
        $this->commands->dispatch(new CreateTaskCommand($this->prefix.'recovered', $this->actorId));
        self::assertSame(1, $this->countRows());
    }

    private function authenticatedHttp(): HttpClientInterface
    {
        $password = Browser::secret();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->observer->executeStatement('UPDATE authenticating_account SET password_hash = ? WHERE id = ?', [$hash, $this->actorId->toRfc4122()]);
        $browser = Browser::create();
        Browser::login($browser, $this->prefix.'@example.test', $password);
        self::assertTrue(Browser::redirectedTo($browser, '/account'), 'CQRS HTTP verification requires a successful native web login.');
        $browser->request('GET', '/account');
        self::assertSame(200, $browser->getResponse()->getStatusCode());

        return HttpClient::createForBaseUri('http://app:8080', [
            'max_duration' => 5,
            'headers' => ['Cookie' => Browser::cookieName().'='.Browser::session($browser)],
        ]);
    }

    private function countRows(): int
    {
        $count = $this->observer->fetchOne('SELECT count(*) FROM task_tracking_task WHERE title LIKE ?', [$this->prefix.'%']);
        self::assertIsInt($count);

        return $count;
    }

    /** @param list<string> $arguments */
    private function cli(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, 'bin/console', '--env=test', '--no-debug', '--no-interaction', '--no-ansi', ...$arguments], dirname(__DIR__, 2), timeout: 15);
        $process->run();

        return $process;
    }

    /** @param callable(): mixed $operation */
    private function assertFails(callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (\Throwable $failure) {
            self::assertStringContainsString($message, $failure->getMessage());

            return;
        }
        self::fail('Expected operation failure: '.$message);
    }
}
