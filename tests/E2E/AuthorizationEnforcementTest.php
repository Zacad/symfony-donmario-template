<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceQuery;
use App\Module\Authenticating\Application\GetAccountIdentity\GetAccountIdentityQuery;
use App\Module\Authenticating\Application\GetAccountIdentity\GetAccountIdentityResult;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use App\Module\Authorizing\Application\ChangeAccountAssignments\AssignmentChangeInput;
use App\Module\Authorizing\Application\ChangeAccountAssignments\ChangeAccountAssignmentsCommand;
use App\Module\Authorizing\Application\ChangeAccountAssignments\ChangeAccountAssignmentsResult;
use App\Module\Authorizing\Application\EvaluatePermissions\EvaluatePermissionsQuery;
use App\Module\Authorizing\Application\EvaluatePermissions\PermissionCheckInput;
use App\Module\Authorizing\Application\ListAccountAssignments\ListAccountAssignmentsQuery;
use App\Module\Authorizing\Application\ListAccountAssignments\ListAccountAssignmentsResult;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Application\GetTask\GetTaskQuery;
use App\Module\TaskTracking\Application\GetTask\GetTaskResult;
use App\Module\TaskTracking\Domain\Task;
use App\Platform\Authorization\AuthorizationDenied;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\QueryBus;
use App\Tests\Fixtures\Authenticating\Browser;
use App\Tests\Fixtures\Authorizing\AuthorizingKernel;
use App\Tests\Fixtures\Authorizing\SqlLog;
use App\Tests\Fixtures\Cqrs\FaultControl;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DatabaseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

/** Run the three authorization-outage groups in the documented external phases. */
final class AuthorizationEnforcementTest extends DatabaseTestCase
{
    private const string VIEW = 'task_tracking.task.view';
    private const string CREATE = 'task_tracking.task.create';
    private const string TYPE = 'task_tracking.task';
    private const string STATE = '/app/var/authorization-enforcement-state.json';
    private const array TABLES = [
        'authorizing_global_role_assignment',
        'authorizing_resource_role_assignment',
        'authorizing_global_permission_grant',
        'authorizing_resource_permission_grant',
    ];

    private Connection $connection;
    private Connection $observer;
    private CommandBus $commands;
    private QueryBus $queries;
    private FaultControl $fault;
    private SqlLog $sql;
    private RequestStack $requests;
    private TokenStorageInterface $tokens;
    private string $prefix;
    /** @var list<Uuid> */
    private array $accounts = [];
    private bool $retain = false;

    protected static function getKernelClass(): string
    {
        return AuthorizingKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Deliberately no connection in setUp: the outage phase must boot and
        // reach actual policy reads with PostgreSQL unavailable.
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $container = self::getContainer();
        $connection = $container->get('doctrine.dbal.default_connection');
        $commands = $container->get('test.authorizing.commands');
        $queries = $container->get('test.authorizing.queries');
        $fault = $container->get('test.authorizing.fault');
        $sql = $container->get('test.authorizing.sql');
        $requests = $container->get('request_stack');
        $tokens = $container->get('security.token_storage');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(CommandBus::class, $commands);
        self::assertInstanceOf(QueryBus::class, $queries);
        self::assertInstanceOf(FaultControl::class, $fault);
        self::assertInstanceOf(SqlLog::class, $sql);
        self::assertInstanceOf(RequestStack::class, $requests);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $this->connection = $connection;
        $this->commands = $commands;
        $this->queries = $queries;
        $this->fault = $fault;
        $this->sql = $sql;
        $this->requests = $requests;
        $this->tokens = $tokens;
        $this->prefix = 'enforcement-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (isset($this->sql)) {
            $this->sql->stop();
            $this->fault->afterAdd = null;
            $this->fault->afterFind = null;
            $this->tokens->setToken(null);
        }
        if (isset($this->observer)) {
            if (!$this->retain) {
                foreach ($this->accounts as $account) {
                    foreach (self::TABLES as $table) {
                        $this->observer->executeStatement('DELETE FROM public.'.$table.' WHERE account_id = ?', [$account->toRfc4122()]);
                    }
                    $this->observer->executeStatement('DELETE FROM public.authenticating_account WHERE id = ?', [$account->toRfc4122()]);
                }
                $this->observer->executeStatement('DELETE FROM public.task_tracking_task WHERE title LIKE ?', [$this->prefix.'%']);
            }
            $this->observer->close();
        }
        parent::tearDown();
    }

    public function testAnonymousAndUngrantedActorsNeverReachTaskHandlers(): void
    {
        $account = $this->seedAccount();
        $task = $this->seedTask();
        $this->forbidTaskHandlers();
        foreach ([null, $account] as $actor) {
            $this->sql->start();
            $this->asAccount($actor, function () use ($actor, $task): void {
                $this->denied(fn () => $this->commands->dispatch(new CreateTaskCommand($this->prefix.'-denied')), null !== $actor);
                $this->denied(fn () => $this->queries->ask(new GetTaskQuery($task->toRfc4122())), null !== $actor);
            });
            self::assertSame([], $this->sql->writes());
            foreach ($this->sql->reads() as $read) {
                self::assertStringNotContainsString('task_tracking_task', $read['sql']);
            }
            $this->sql->stop();
            self::assertFalse($this->connection->isTransactionActive());
        }
        self::assertSame(1, $this->taskCount(), 'Only the independently seeded task exists; denied commands may open a transaction but never write.');

        foreach ([[Browser::create(), 401], [$this->login($account), 403]] as [$browser, $status]) {
            $this->httpDenied($browser, 'POST', '/_demo/tasks', $status, ['title' => $this->prefix.'-http-denied']);
            $this->httpDenied($browser, 'GET', '/_demo/tasks/'.$task->toRfc4122(), $status);
        }
        self::assertSame(1, $this->taskCount());
    }

    public function testNativeSessionUsesLiveScopedAndGlobalGrantsAndImmediateRevocation(): void
    {
        $account = $this->seedAccount();
        $other = $this->seedAccount();
        $task = $this->seedTask();
        $different = $this->seedTask();
        $browser = $this->login($account);
        $session = Browser::session($browser);
        $otherBrowser = $this->login($other);
        $this->httpDenied($browser, 'GET', '/_demo/tasks/'.$task, 403);

        $this->operatorPermission($account, self::VIEW, $task);
        $this->httpTask($browser, $task);
        $this->httpDenied($browser, 'GET', '/_demo/tasks/'.$different, 403);
        $this->httpDenied($otherBrowser, 'GET', '/_demo/tasks/'.$task, 403);
        $this->httpDenied($browser, 'POST', '/_demo/tasks', 403, ['title' => $this->prefix.'-not-creator']);

        $this->operatorPermission($account, self::VIEW);
        $this->operatorPermission($account, self::VIEW, $task, 'revoke');
        $this->httpTask($browser, $task);
        $this->httpTask($browser, $different);
        $this->operatorPermission($account, self::CREATE);
        $browser->jsonRequest('POST', '/_demo/tasks', ['title' => $this->prefix.'-created']);
        self::assertSame(201, $browser->getResponse()->getStatusCode());
        $payload = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);
        $this->httpTask($browser, Uuid::fromString($payload['id']));

        $this->operatorPermission($account, self::VIEW, operation: 'revoke');
        $this->operatorPermission($account, self::CREATE, operation: 'revoke');
        $this->httpDenied($browser, 'GET', '/_demo/tasks/'.$task, 403);
        $this->httpDenied($browser, 'POST', '/_demo/tasks', 403, ['title' => $this->prefix.'-revoked']);
        $browser->request('GET', '/account');
        self::assertSame(200, $browser->getResponse()->getStatusCode(), 'Revocation affects business access, while the native session remains authenticated.');
        self::assertTrue(hash_equals($session, Browser::session($browser)), 'The existing session is reused without reauthentication.');
        self::assertSame(3, $this->taskCount());
    }

    public function testAssignmentAuthorityComesFromAdministratorActorNotRecipient(): void
    {
        $admin = $this->seedAccount();
        $recipient = $this->seedAccount();
        $task = $this->seedTask();
        $this->seedPermission($admin, 'authorizing.manage');
        $change = new ChangeAccountAssignmentsCommand($recipient, [new AssignmentChangeInput('add', 'permission', self::VIEW, 'resource', self::TYPE, $task)]);
        $this->asAccount($admin, function () use ($change, $recipient): void {
            $result = $this->commands->dispatch($change);
            self::assertInstanceOf(ChangeAccountAssignmentsResult::class, $result);
            self::assertSame(1, $result->added);
            $page = $this->queries->ask(new ListAccountAssignmentsQuery($recipient));
            self::assertInstanceOf(ListAccountAssignmentsResult::class, $page);
            self::assertCount(1, $page->assignments);
        });
        $this->asAccount($recipient, function () use ($recipient, $admin, $task): void {
            self::assertInstanceOf(GetTaskResult::class, $this->queries->ask(new GetTaskQuery($task->toRfc4122())));
            foreach ([$recipient, $admin] as $target) {
                $this->denied(fn () => $this->commands->dispatch(new ChangeAccountAssignmentsCommand($target, [new AssignmentChangeInput('add', 'permission', 'authorizing.manage', 'global')])), true);
                $this->denied(fn () => $this->queries->ask(new ListAccountAssignmentsQuery($target)), true);
            }
        });
        self::assertSame(0, $this->observer->fetchOne('SELECT count(*) FROM authorizing_global_permission_grant WHERE account_id = ?', [$recipient->toRfc4122()]));
        $this->asAccount($admin, function () use ($recipient, $task): void {
            $result = $this->commands->dispatch(new ChangeAccountAssignmentsCommand($recipient, [new AssignmentChangeInput('remove', 'permission', self::VIEW, 'resource', self::TYPE, $task)]));
            self::assertInstanceOf(ChangeAccountAssignmentsResult::class, $result);
            self::assertSame(1, $result->removed);
            $this->denied(fn () => $this->queries->ask(new GetTaskQuery($task->toRfc4122())), true, 'Management authority does not implicitly grant task access.');
        });
        $this->asAccount($recipient, fn () => $this->denied(fn () => $this->queries->ask(new GetTaskQuery($task->toRfc4122())), true));
    }

    public function testSelfIdentityAndFoundationQueriesCannotBeUsedAsArbitraryReadApis(): void
    {
        $account = $this->seedAccount();
        $other = $this->seedAccount();
        $this->seedPermission($account, 'authorizing.manage');
        $this->asAccount($account, function () use ($account, $other): void {
            $identity = $this->queries->ask(new GetAccountIdentityQuery($account));
            self::assertInstanceOf(GetAccountIdentityResult::class, $identity);
            self::assertTrue($account->equals($identity->id));
            self::assertSame($this->email($account), $identity->email);
            $this->sql->start();
            $this->denied(fn () => $this->queries->ask(new GetAccountIdentityQuery($other)), true);
            $this->denied(fn () => $this->queries->ask(new CheckAccountExistenceQuery([$other])), true);
            $this->denied(fn () => $this->queries->ask(new EvaluatePermissionsQuery([new PermissionCheckInput($other, self::VIEW, 'global')])), true);
            self::assertSame([], $this->sql->statements, 'Direct foundation and cross-account identity denials must not read protected data.');
            $this->sql->stop();
        });
        $this->asAccount(null, fn () => $this->denied(fn () => $this->queries->ask(new GetAccountIdentityQuery($account)), false));
    }

    public function testBareCliBusAndStaleNativeTokenDenyWhileSupportedOperatorAdaptersWork(): void
    {
        $account = $this->seedAccount();
        $task = $this->seedTask();
        $this->seedPermission($account, self::CREATE);
        $this->seedPermission($account, self::VIEW);
        self::assertNull($this->requests->getMainRequest());
        foreach ([null, $this->token($account)] as $token) {
            $this->tokens->setToken($token);
            try {
                $this->denied(fn () => $this->commands->dispatch(new CreateTaskCommand($this->prefix.'-bare')), false);
                $this->denied(fn () => $this->queries->ask(new GetTaskQuery($task->toRfc4122())), false);
                $this->denied(fn () => $this->commands->dispatch(new ChangeAccountAssignmentsCommand($account, [new AssignmentChangeInput('add', 'permission', 'authorizing.manage', 'global')])), false);
            } finally {
                $this->tokens->setToken(null);
            }
        }
        self::assertSame(1, $this->taskCount());
        $create = $this->cli(['app:task:create', $this->prefix.'-operator']);
        self::assertSame(0, $create->getExitCode());
        self::assertSame('', $create->getErrorOutput());
        $id = trim($create->getOutput());
        self::assertTrue(Uuid::isValid($id));
        $show = $this->cli(['app:task:show', $id]);
        self::assertSame(0, $show->getExitCode());
        self::assertSame(['id' => $id, 'title' => $this->prefix.'-operator'], json_decode($show->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        $this->operatorPermission($account, 'authorizing.manage');
        self::assertSame(2, $this->taskCount());
        $this->denied(fn () => $this->queries->ask(new GetTaskQuery($id)), false, 'A previous scoped execution must not leak operator authority.');
    }

    public function testSuccessfulGetUsesTwoReadOnlyPermissionFactsAndOneTaskRead(): void
    {
        $account = $this->seedAccount();
        $task = $this->seedTask();
        $this->seedPermission($account, self::VIEW, $task);
        // Native token setup is outside capture: unlike HTTP this path does not
        // include the independent credential-provider read.
        $this->asAccount($account, function () use ($task): void {
            foreach (range(1, 2) as $attempt) {
                $this->sql->start();
                $result = $this->queries->ask(new GetTaskQuery($task->toRfc4122()));
                self::assertInstanceOf(GetTaskResult::class, $result);
                self::assertTrue($task->equals($result->id));
                $reads = $this->sql->reads();
                self::assertCount(3, $reads, 'Each independent call rechecks live permission facts; no recursive policy reads or cached decisions.');
                self::assertStringContainsString('authenticating_account', $reads[0]['sql']);
                self::assertStringNotContainsString('password', $reads[0]['sql']);
                self::assertStringNotContainsString('email', $reads[0]['sql']);
                foreach (self::TABLES as $table) {
                    self::assertStringContainsString($table, $reads[1]['sql']);
                }
                self::assertStringContainsString('task_tracking_task', $reads[2]['sql']);
                self::assertSame([], $this->sql->writes(), 'Policy QueryBus facts and the protected Get are read-only.');
                self::assertCount(3, $this->sql->statements);
                self::assertFalse($this->connection->isTransactionActive());
                $this->sql->stop();
            }
        });
        $this->observer->executeStatement('DELETE FROM authorizing_resource_permission_grant WHERE account_id = ?', [$account->toRfc4122()]);
        $this->forbidTaskHandlers();
        $this->asAccount($account, fn () => $this->denied(fn () => $this->queries->ask(new GetTaskQuery($task->toRfc4122())), true));
    }

    /** @return iterable<string, array{bool}> */
    public static function nestedDenials(): iterable
    {
        yield 'caught query denial' => [false];
        yield 'caught command denial after live self-revocation' => [true];
    }

    #[DataProvider('nestedDenials')]
    public function testCaughtNestedDenialRollsBackScheduledAndImmediateWritesAndRecovers(bool $command): void
    {
        $actor = $this->seedAccount();
        $recipient = $this->seedAccount();
        $this->seedPermission($actor, self::CREATE);
        $this->seedPermission($actor, 'authorizing.manage');
        $scheduled = null;
        $caught = false;
        $this->fault->afterAdd = function (Task $task) use ($actor, $recipient, $command, &$scheduled, &$caught): void {
            $scheduled = $task->id();
            $this->fault->afterAdd = static function (): never { self::fail('No handler may run after the caught denial.'); };
            self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM task_tracking_task WHERE id = ?', [$scheduled->toRfc4122()]));
            $result = $this->commands->dispatch(new ChangeAccountAssignmentsCommand($recipient, [new AssignmentChangeInput('add', 'permission', self::VIEW, 'resource', self::TYPE, $scheduled)]));
            self::assertInstanceOf(ChangeAccountAssignmentsResult::class, $result);
            self::assertSame(1, $result->added);
            self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM authorizing_resource_permission_grant WHERE account_id = ?', [$recipient->toRfc4122()]));
            self::assertSame(0, $this->observer->fetchOne('SELECT count(*) FROM authorizing_resource_permission_grant WHERE account_id = ?', [$recipient->toRfc4122()]), 'The successful nested write is immediate but not independently committed.');
            if ($command) {
                $removed = $this->commands->dispatch(new ChangeAccountAssignmentsCommand($actor, [new AssignmentChangeInput('remove', 'permission', self::CREATE, 'global')]));
                self::assertInstanceOf(ChangeAccountAssignmentsResult::class, $removed);
                self::assertSame(1, $removed->removed);
            }
            try {
                if ($command) {
                    $this->commands->dispatch(new CreateTaskCommand($this->prefix.'-nested-denied'));
                } else {
                    $this->queries->ask(new GetTaskQuery($scheduled->toRfc4122()));
                }
            } catch (AuthorizationDenied) {
                $caught = true;
            }
            self::assertTrue($caught, 'The policy denial must happen inside the root, before handler execution.');
            // Even the otherwise-authorized admin command cannot run after the
            // nested failure has been caught. Its policy cannot heal the root.
            $this->denied(fn () => $this->commands->dispatch(new ChangeAccountAssignmentsCommand($recipient, [new AssignmentChangeInput('add', 'permission', self::VIEW, 'global')])), true);
        };
        $this->asAccount($actor, fn () => $this->denied(fn () => $this->commands->dispatch(new CreateTaskCommand($this->prefix.'-outer')), true));
        self::assertInstanceOf(Uuid::class, $scheduled);
        self::assertTrue($caught);
        self::assertSame(0, $this->taskCount());
        foreach (self::TABLES as $table) {
            self::assertSame(0, $this->observer->fetchOne('SELECT count(*) FROM '.$table.' WHERE account_id = ?', [$recipient->toRfc4122()]));
        }
        self::assertSame(1, $this->observer->fetchOne('SELECT count(*) FROM authorizing_global_permission_grant WHERE account_id = ? AND permission_key = ?', [$actor->toRfc4122(), self::CREATE]));
        self::assertFalse($this->connection->isTransactionActive());
        $this->fault->afterAdd = null;
        $id = $this->asAccount($actor, fn () => $this->commands->dispatch(new CreateTaskCommand($this->prefix.'-recovered')));
        self::assertInstanceOf(Uuid::class, $id);
        self::assertSame(1, $this->taskCount(), 'The same bus and connection recover after rollback.');
        $this->asAccount($recipient, fn () => $this->denied(fn () => $this->commands->dispatch(new CreateTaskCommand($this->prefix.'-wrong-actor')), true));
        self::assertSame(1, $this->taskCount());
    }

    #[Group('authorization-outage-prepare')]
    public function testPrepareOutage(): void
    {
        self::assertFileDoesNotExist(self::STATE, 'Use a fresh isolated runner var volume for this journey.');
        $account = $this->seedAccount();
        $task = $this->seedTask();
        $this->seedPermission($account, self::VIEW);
        $this->seedPermission($account, self::CREATE);
        $this->asAccount($account, fn () => self::assertInstanceOf(GetTaskResult::class, $this->queries->ask(new GetTaskQuery($task->toRfc4122()))));
        $mask = umask(0077);
        try {
            self::assertNotFalse(file_put_contents(self::STATE, json_encode(['account' => $account->toRfc4122(), 'task' => $task->toRfc4122(), 'prefix' => $this->prefix], JSON_THROW_ON_ERROR), LOCK_EX));
        } finally {
            umask($mask);
        }
        $this->retain = true;
    }

    #[Group('authorization-outage-down')]
    public function testAssertOutage(): void
    {
        $state = $this->outageState();
        $account = Uuid::fromString($state['account']);
        $this->prefix = $state['prefix'];
        $this->forbidTaskHandlers();
        foreach ([false, true] as $command) {
            $failed = false;
            $started = microtime(true);
            try {
                $this->asAccount($account, fn () => $command
                    ? $this->commands->dispatch(new CreateTaskCommand($this->prefix.'-outage-write'))
                    : $this->queries->ask(new GetTaskQuery($state['task'])));
            } catch (DatabaseException) {
                $failed = true;
            }
            self::assertTrue($failed, 'Unavailable permission facts cannot fall back to a prior allow decision.');
            self::assertLessThan(15, microtime(true) - $started);
            self::assertFalse($this->connection->isTransactionActive());
            self::assertNull($this->requests->getMainRequest());
            self::assertNull($this->tokens->getToken());
        }
        // Anonymous reads still deny before any database-dependent facts.
        $this->httpDenied(Browser::create(), 'GET', '/_demo/tasks/'.$state['task'], 401);
    }

    #[Group('authorization-outage-recover')]
    public function testRecoverOutage(): void
    {
        $state = $this->outageState();
        $this->prefix = $state['prefix'];
        $account = Uuid::fromString($state['account']);
        $this->accounts[] = $account;
        $this->connectObserver();
        self::assertSame(1, $this->taskCount(), 'The prepared task survived and the outage command created nothing.');
        $this->asAccount($account, function () use ($state): void {
            self::assertInstanceOf(GetTaskResult::class, $this->queries->ask(new GetTaskQuery($state['task'])));
            self::assertInstanceOf(Uuid::class, $this->commands->dispatch(new CreateTaskCommand($this->prefix.'-after-outage')));
        });
        self::assertSame(2, $this->taskCount());
        $browser = $this->login($account);
        $this->httpTask($browser, Uuid::fromString($state['task']));
        $this->operatorPermission($account, self::VIEW, operation: 'revoke');
        $this->httpDenied($browser, 'GET', '/_demo/tasks/'.$state['task'], 403);
        self::assertTrue(unlink(self::STATE));
    }

    private function connectObserver(): void
    {
        if (!isset($this->observer)) {
            $this->observer = DriverManager::getConnection($this->connection->getParams());
            self::assertSame('app_test', $this->observer->fetchOne('SELECT current_database()'));
            self::assertSame('app', $this->observer->fetchOne('SELECT current_user'));
        }
    }

    private function seedAccount(): Uuid
    {
        $this->connectObserver();
        $id = Uuid::v7();
        $this->accounts[] = $id;
        $this->observer->executeStatement('INSERT INTO authenticating_account (id, email, password_hash) VALUES (?, ?, ?)', [$id->toRfc4122(), $this->email($id), 'unused-enforcement-fixture']);

        return $id;
    }

    private function email(Uuid $id): string
    {
        return $this->prefix.'-'.str_replace('-', '', $id->toRfc4122()).'@example.test';
    }

    private function seedTask(): Uuid
    {
        $this->connectObserver();
        $id = Uuid::v7();
        $this->observer->executeStatement('INSERT INTO task_tracking_task (id, title) VALUES (?, ?)', [$id->toRfc4122(), $this->prefix.'-'.$id->toRfc4122()]);

        return $id;
    }

    private function seedPermission(Uuid $account, string $permission, ?Uuid $task = null): void
    {
        $this->observer->executeStatement(null === $task
            ? 'INSERT INTO authorizing_global_permission_grant (id, account_id, permission_key) VALUES (?, ?, ?)'
            : 'INSERT INTO authorizing_resource_permission_grant (id, account_id, permission_key, resource_type, resource_id) VALUES (?, ?, ?, ?, ?)',
            [Uuid::v7()->toRfc4122(), $account->toRfc4122(), $permission, ...(null === $task ? [] : [self::TYPE, $task->toRfc4122()])]);
    }

    private function token(Uuid $id): UsernamePasswordToken
    {
        return new UsernamePasswordToken(new AccountPrincipal($id, $this->email($id), 'unused-enforcement-fixture'), 'main', []);
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function asAccount(?Uuid $account, callable $operation): mixed
    {
        self::assertNull($this->requests->getMainRequest(), 'Direct fixture scopes must not nest or replace an active actor.');
        $previous = $this->tokens->getToken();
        $this->requests->push(Request::create('/_demo/tasks'));
        $this->tokens->setToken(null === $account ? null : $this->token($account));
        try {
            return $operation();
        } finally {
            $this->tokens->setToken($previous);
            $this->requests->pop();
        }
    }

    private function denied(callable $operation, bool $authenticated, string $message = 'The protected bus must deny this invocation.'): void
    {
        try {
            $operation();
        } catch (AuthorizationDenied $failure) {
            self::assertSame($authenticated, $failure->authenticated);
            self::assertSame('Access denied.', $failure->getMessage());

            return;
        }
        self::fail($message);
    }

    private function forbidTaskHandlers(): void
    {
        $this->fault->afterAdd = static function (): never { self::fail('Denied create reached persistence.'); };
        $this->fault->afterFind = static function (): never { self::fail('Denied get reached persistence.'); };
    }

    private function login(Uuid $account): HttpBrowser
    {
        $password = Browser::secret();
        $this->observer->executeStatement('UPDATE authenticating_account SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), $account->toRfc4122()]);
        $browser = Browser::create();
        Browser::login($browser, $this->email($account), $password);
        self::assertTrue(Browser::redirectedTo($browser, '/account'), 'Native form login must succeed.');
        $browser->request('GET', '/account');
        self::assertSame(200, $browser->getResponse()->getStatusCode());

        return $browser;
    }

    /** @param array<string, string>|null $body */
    private function httpDenied(HttpBrowser $browser, string $method, string $path, int $status, ?array $body = null): void
    {
        if (null === $body) {
            $browser->request($method, $path);
        } else {
            $browser->jsonRequest($method, $path, $body);
        }
        self::assertSame($status, $browser->getResponse()->getStatusCode());
        self::assertSame(['error' => 'Access denied.'], json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringContainsString('no-store', Browser::header($browser, 'Cache-Control'));
    }

    private function httpTask(HttpBrowser $browser, Uuid $task): void
    {
        $browser->request('GET', '/_demo/tasks/'.$task->toRfc4122());
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame(['id' => $task->toRfc4122(), 'title' => $this->observer->fetchOne('SELECT title FROM task_tracking_task WHERE id = ?', [$task->toRfc4122()])], json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringContainsString('no-store', Browser::header($browser, 'Cache-Control'));
    }

    private function operatorPermission(Uuid $account, string $permission, ?Uuid $task = null, string $operation = 'grant'): void
    {
        $process = $this->cli(['app:authorization:permission:'.$operation, $account->toRfc4122(), $permission, ...(null === $task ? ['--global'] : ['--resource-type='.self::TYPE, '--resource-id='.$task->toRfc4122()])]);
        self::assertSame(0, $process->getExitCode(), 'Supported operator adapter must succeed.');
        self::assertSame('', $process->getErrorOutput());
        self::assertSame(['requested' => 1, 'added' => 'grant' === $operation ? 1 : 0, 'removed' => 'revoke' === $operation ? 1 : 0, 'unchanged' => 0], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $arguments */
    private function cli(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, 'bin/console', '--env=test', '--no-debug', '--no-interaction', '--no-ansi', ...$arguments], dirname(__DIR__, 2), timeout: 20);
        $process->run();

        return $process;
    }

    private function taskCount(): int
    {
        $count = $this->observer->fetchOne('SELECT count(*) FROM task_tracking_task WHERE title LIKE ?', [$this->prefix.'%']);
        self::assertIsInt($count);

        return $count;
    }

    /** @return array{account: string, task: string, prefix: string} */
    private function outageState(): array
    {
        self::assertFileExists(self::STATE, 'Run testPrepareOutage while PostgreSQL is healthy first.');
        $raw = file_get_contents(self::STATE);
        self::assertIsString($raw);
        $state = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        foreach (['account', 'task', 'prefix'] as $field) {
            self::assertIsString($state[$field]);
        }
        self::assertTrue(Uuid::isValid($state['account']) && Uuid::isValid($state['task']));
        self::assertMatchesRegularExpression('/\Aenforcement-[a-f0-9]{16}\z/', $state['prefix']);

        return ['account' => $state['account'], 'task' => $state['task'], 'prefix' => $state['prefix']];
    }
}
