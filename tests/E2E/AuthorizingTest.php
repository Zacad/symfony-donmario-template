<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\Authorizing\Application\ChangeAccountAssignments\AssignmentChangeInput;
use App\Module\Authorizing\Application\ChangeAccountAssignments\ChangeAccountAssignmentsCommand;
use App\Module\Authorizing\Application\ChangeAccountAssignments\ChangeAccountAssignmentsResult;
use App\Module\Authorizing\Application\EvaluatePermissions\EvaluatePermissionsQuery;
use App\Module\Authorizing\Application\EvaluatePermissions\EvaluatePermissionsResult;
use App\Module\Authorizing\Application\EvaluatePermissions\PermissionCheckInput;
use App\Module\Authorizing\Application\ListAccountAssignments\AssignmentCursorInput;
use App\Module\Authorizing\Application\ListAccountAssignments\ListAccountAssignmentsQuery;
use App\Module\Authorizing\Application\ListAccountAssignments\ListAccountAssignmentsResult;
use App\Module\Authorizing\Domain\InvalidAuthorizationInput;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Domain\Task;
use App\Platform\Authorization\Actor;
use App\Platform\Authorization\ActorKind;
use App\Platform\Authorization\ExecutionContext;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\QueryBus;
use App\Tests\Fixtures\Authorizing\AuthorizingKernel;
use App\Tests\Fixtures\Authorizing\SqlLog;
use App\Tests\Fixtures\Cqrs\FaultControl;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/** @phpstan-import-type Statement from SqlLog */
final class AuthorizingTest extends DatabaseTestCase
{
    private const string TYPE = 'task_tracking.task';
    private const string VIEW = 'task_tracking.task.view';
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
    private ExecutionContext $execution;
    private FaultControl $fault;
    private SqlLog $sql;
    private Uuid $account;
    private Uuid $other;
    private Uuid $resource;
    private string $prefix;
    /** @var list<Uuid> */
    private array $ownedAccounts = [];
    /** @var list<Uuid> */
    private array $ownedTasks = [];

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
        $commands = $container->get('test.authorizing.commands');
        $queries = $container->get('test.authorizing.queries');
        $fault = $container->get('test.authorizing.fault');
        $sql = $container->get('test.authorizing.sql');
        $execution = $container->get(ExecutionContext::class);
        self::assertInstanceOf(CommandBus::class, $commands);
        self::assertInstanceOf(QueryBus::class, $queries);
        self::assertInstanceOf(FaultControl::class, $fault);
        self::assertInstanceOf(SqlLog::class, $sql);
        self::assertInstanceOf(ExecutionContext::class, $execution);
        $this->commands = $commands;
        $this->queries = $queries;
        $this->fault = $fault;
        $this->sql = $sql;
        $this->execution = $execution;
        $this->prefix = 'authorizing-'.bin2hex(random_bytes(8));
        $this->account = $this->seedAccount();
        $this->other = $this->seedAccount();
        $this->resource = Uuid::v7();
    }

    protected function tearDown(): void
    {
        if (isset($this->sql)) {
            $this->sql->stop();
            $this->fault->afterAdd = null;
        }
        if (isset($this->observer)) {
            if ($this->observer->isTransactionActive()) {
                $this->observer->rollBack();
            }
            foreach ($this->ownedAccounts as $account) {
                foreach (self::TABLES as $table) {
                    $this->observer->executeStatement('DELETE FROM public.'.$table.' WHERE account_id = ?', [$account->toRfc4122()]);
                }
                $this->observer->executeStatement('DELETE FROM public.authenticating_account WHERE id = ?', [$account->toRfc4122()]);
            }
            foreach ($this->ownedTasks as $task) {
                $this->observer->executeStatement('DELETE FROM public.task_tracking_task WHERE id = ?', [$task->toRfc4122()]);
            }
            $this->observer->close();
        }
        parent::tearDown();
    }

    public function testEverySourceIndependentlyAndAdditiveUnionRespectAccountAndResourceScope(): void
    {
        foreach (range(0, 3) as $source) {
            self::assertSame(1, $this->change([$this->sourceChange($source)])->added);
            self::assertSame([true, 0 === $source % 2, false, 0 === $source % 2], $this->decisions([
                $this->check($this->account, $this->resource),
                $this->check($this->account, Uuid::v7()),
                $this->check($this->other, $this->resource),
                $this->check($this->account),
            ]), 'Each source independently grants only its account and applicable scope.');
            self::assertSame(1, $this->change([$this->sourceChange($source, 'remove')])->removed);
            self::assertSame([false], $this->decisions([$this->check($this->account, $this->resource)]));
        }
        $changes = array_map(fn (int $source): AssignmentChangeInput => $this->sourceChange($source), range(0, 3));
        self::assertSame(4, $this->change($changes)->added);
        foreach (range(0, 3) as $source) {
            self::assertSame(1, $this->change([$this->sourceChange($source, 'remove')])->removed);
            self::assertSame([3 !== $source], $this->decisions([$this->check($this->account, $this->resource)]), 'Only removing the final applicable source denies.');
        }
    }

    public function testKnownIncompatibleScopeFailsAndGlobalOnlyCapabilitiesRequireGlobalAssignments(): void
    {
        $this->change([$this->sourceChange(0)]);
        $this->assertThrows(fn () => $this->decisions([new PermissionCheckInput($this->account, self::VIEW, 'resource', 'other.resource', $this->resource)]), InvalidAuthorizationInput::class);
        $this->assertThrows(fn () => $this->change([new AssignmentChangeInput('add', 'permission', self::VIEW, 'resource', 'other.resource', $this->resource)]), InvalidAuthorizationInput::class);
        foreach ([['task_tracking.creator', 'task_tracking.task.create'], ['authorizing.administrator', 'authorizing.manage']] as [$role, $permission]) {
            foreach ([['role', $role], ['permission', $permission]] as [$kind, $key]) {
                $this->assertThrows(fn () => $this->change([new AssignmentChangeInput('add', $kind, $key, 'resource', self::TYPE, $this->resource)]), InvalidAuthorizationInput::class);
                self::assertSame(1, $this->change([new AssignmentChangeInput('add', $kind, $key, 'global')])->added);
                self::assertSame([true], $this->decisions([$this->check($this->account, permission: $permission)]));
                $this->assertThrows(fn () => $this->decisions([$this->check($this->account, $this->resource, $permission)]), InvalidAuthorizationInput::class);
                self::assertSame(1, $this->change([new AssignmentChangeInput('remove', $kind, $key, 'global')])->removed);
            }
        }
        self::assertSame([false, false], $this->decisions([
            $this->check($this->account, permission: 'retired.permission'),
            $this->check(Uuid::v7()),
        ]));
    }

    public function testOrphansAndRetiredKeysRemainListableAndRemovableButCannotGrantAccess(): void
    {
        $this->change([$this->sourceChange(0), $this->sourceChange(3)]);
        foreach (range(0, 3) as $source) {
            $this->seedAssignment($source, $this->account, 'retired.key', Uuid::v7(), 'retired.type');
        }
        $this->observer->executeStatement('DELETE FROM authenticating_account WHERE id = ?', [$this->account->toRfc4122()]);
        self::assertCount(6, $this->page()->assignments);
        self::assertSame([false, false], $this->decisions([$this->check($this->account, $this->resource), $this->check($this->account, permission: 'retired.key')]));
        $this->assertThrows(fn () => $this->change([$this->sourceChange(2)]), \DomainException::class);
        $removals = [];
        foreach ($this->page()->assignments as $row) {
            $removals[] = new AssignmentChangeInput('remove', $row->kind, $row->key, $row->scope, $row->resourceType, $row->resourceId);
        }
        self::assertSame(6, $this->change($removals)->removed);
        self::assertSame(6, $this->change($removals)->unchanged);
        self::assertSame([], $this->page()->assignments);
    }

    public function testNativeCollectionBoundsPrecedeDatabaseAndDuplicateNaturalKeysAreAtomic(): void
    {
        foreach ([0, 101] as $size) {
            $this->sql->start();
            $this->assertThrows(fn () => $this->change(array_fill(0, $size, $this->sourceChange(0))), ValidationFailedException::class);
            self::assertSame(0, $this->sql->events, 'Invalid native collection input must not begin a transaction or issue SQL.');
            $this->assertThrows(fn () => $this->decisions(array_fill(0, $size, $this->check($this->account))), ValidationFailedException::class);
            self::assertSame(0, $this->sql->events);
            $this->sql->stop();
        }
        foreach (['add', 'remove'] as $operation) {
            $this->assertThrows(fn () => $this->change([$this->sourceChange(0), $this->sourceChange(0, $operation)]), InvalidAuthorizationInput::class);
            self::assertSame(0, $this->assignmentCount($this->account));
        }
        self::assertSame(1, $this->change([$this->sourceChange(0)])->added);
        self::assertSame(1, $this->change([$this->sourceChange(0)])->unchanged);
    }

    public function testHundredItemBatchBudgetsAndPopulatedIndexedDecisionAndKeysetPlans(): void
    {
        // Trusted fixture data: 8,000 unrelated assignments, including retired keys.
        // No planner GUCs are changed; the target account is deliberately selective.
        foreach (self::TABLES as $source => $table) {
            $key = $source < 2 ? 'role_key' : 'permission_key';
            $scoped = 1 === $source % 2;
            $this->observer->executeStatement(
                "INSERT INTO public.$table (id, account_id, $key".($scoped ? ', resource_type, resource_id' : '').") SELECT md5(? || ':' || n::text)::uuid, CAST(? AS uuid), 'fixture.key.' || n::text".($scoped ? ", 'task_tracking.task', CAST(? AS uuid)" : '').' FROM generate_series(1, 2000) n',
                $scoped ? [$this->prefix.$source, $this->other->toRfc4122(), $this->resource->toRfc4122()] : [$this->prefix.$source, $this->other->toRfc4122()],
            );
        }
        $changes = [];
        $checks = [];
        foreach (range(1, 100) as $i) {
            $resource = Uuid::v7();
            $changes[] = new AssignmentChangeInput('add', 'permission', self::VIEW, 'resource', self::TYPE, $resource);
            $checks[] = $this->check(0 === $i % 2 ? $this->other : $this->account, $resource);
        }
        $this->sql->start();
        $result = $this->change($changes);
        self::assertSame([100, 100, 0, 0], [$result->requested, $result->added, $result->removed, $result->unchanged]);
        self::assertNotEmpty($this->sql->writes());
        self::assertLessThanOrEqual(8, count($this->sql->writes()));
        self::assertSame(100, $this->change($changes)->unchanged);
        $this->sql->stop();
        foreach (self::TABLES as $table) {
            $this->observer->executeStatement('ANALYZE public.'.$table);
        }
        $this->sql->start();
        self::assertSame(array_map(static fn (int $i): bool => 1 === $i % 2, range(1, 100)), $this->decisions($checks));
        $reads = $this->sql->reads();
        $this->sql->stop();
        self::assertCount(2, $reads, '100 ordered decisions use one existence read and one authorization read.');
        self::assertStringContainsString('authenticating_account', $reads[0]['sql']);
        self::assertStringNotContainsString('password', $reads[0]['sql']);
        self::assertStringNotContainsString('email', $reads[0]['sql']);
        foreach (self::TABLES as $table) {
            self::assertStringContainsString($table, $reads[1]['sql']);
        }
        $this->explain($reads[1], '100 decisions');
        $this->sql->start();
        $first = $this->page(limit: 7);
        self::assertNotNull($first->next);
        $firstSql = $this->sql->reads();
        self::assertCount(1, $firstSql);
        $this->sql->start();
        self::assertCount(7, $this->page(after: new AssignmentCursorInput($this->account, $first->next->source, $first->next->assignmentId), limit: 7)->assignments);
        $laterSql = $this->sql->reads();
        $this->sql->stop();
        self::assertCount(1, $laterSql);
        self::assertSame(5, substr_count($laterSql[0]['sql'], 'LIMIT'));
        self::assertSame(3, substr_count($laterSql[0]['sql'], 'AND FALSE'), 'Ranks preceding source 3 are pruned, not scanned and discarded.');
        self::assertStringContainsString('id > CAST(? AS uuid)', $laterSql[0]['sql']);
        self::assertStringNotContainsString('OFFSET', strtoupper($laterSql[0]['sql']));
        $this->explain($firstSql[0], 'first page');
        $this->explain($laterSql[0], 'source 3 continuation');
        $removals = array_map(static fn (AssignmentChangeInput $change): AssignmentChangeInput => new AssignmentChangeInput('remove', $change->kind, $change->key, $change->scope, $change->resourceType, $change->resourceId), $changes);
        $this->sql->start();
        self::assertSame(100, $this->change($removals)->removed);
        self::assertLessThanOrEqual(8, count($this->sql->writes()));
        $this->sql->stop();
        self::assertSame(100, $this->change($removals)->unchanged);
        self::assertSame(0, $this->assignmentCount($this->account));
    }

    public function testMixedBatchTouchesAllEightWriteGroupsWithIdempotentCounts(): void
    {
        $this->change(array_map(fn (int $source): AssignmentChangeInput => $this->sourceChange($source), range(0, 3)));
        $changes = [];
        foreach (range(0, 3) as $source) {
            $changes[] = $this->sourceChange($source, 'remove');
            $changes[] = new AssignmentChangeInput('add', $source < 2 ? 'role' : 'permission', $source < 2 ? 'task_tracking.editor' : 'task_tracking.task.complete', 0 === $source % 2 ? 'global' : 'resource', 0 === $source % 2 ? null : self::TYPE, 0 === $source % 2 ? null : $this->resource);
        }
        $this->sql->start();
        $result = $this->change($changes);
        self::assertSame([8, 4, 4, 0], [$result->requested, $result->added, $result->removed, $result->unchanged]);
        self::assertCount(8, $this->sql->writes());
        $this->sql->stop();
        self::assertSame(8, $this->change($changes)->unchanged);
        self::assertSame([true], $this->decisions([$this->check($this->account, $this->resource, 'task_tracking.task.complete')]));
    }

    public function testPostgresOwnsNaturalUniquenessNonnullScopeAndNoCrossModuleForeignKeys(): void
    {
        foreach (self::TABLES as $source => $table) {
            $this->change([$this->sourceChange($source)]);
            $key = $source < 2 ? 'role_key' : 'permission_key';
            $columns = 'account_id, '.$key.(1 === $source % 2 ? ', resource_type, resource_id' : '');
            $indexes = $this->observer->fetchFirstColumn('SELECT pg_get_indexdef(indexrelid) FROM pg_catalog.pg_index WHERE indrelid = CAST(? AS regclass) AND indisunique AND indisvalid', ['public.'.$table]);
            self::assertTrue((bool) array_filter($indexes, static fn (mixed $definition): bool => is_string($definition) && str_contains($definition, '('.$columns.')')), 'Natural unique index must be live in PostgreSQL.');
            // This independent connection is in autocommit: a violation cannot
            // poison the application's transaction or the subsequent inspection.
            $this->assertThrows(fn () => $this->observer->executeStatement("INSERT INTO public.$table (id, $columns) SELECT CAST(? AS uuid), $columns FROM public.$table WHERE account_id = ?", [Uuid::v7()->toRfc4122(), $this->account->toRfc4122()]), UniqueConstraintViolationException::class);
            self::assertFalse($this->observer->isTransactionActive());
            self::assertSame(0, $this->observer->fetchOne("SELECT count(*) FROM pg_catalog.pg_constraint WHERE conrelid = CAST(? AS regclass) AND contype = 'f'", ['public.'.$table]));
            if (1 === $source % 2) {
                self::assertSame(2, $this->observer->fetchOne("SELECT count(*) FROM pg_catalog.pg_attribute WHERE attrelid = CAST(? AS regclass) AND attname IN ('resource_type', 'resource_id') AND attnotnull", ['public.'.$table]));
                foreach (['resource_type', 'resource_id'] as $nullable) {
                    $this->assertThrows(fn () => $this->observer->executeStatement("INSERT INTO public.$table (id, account_id, $key, resource_type, resource_id) VALUES (?, ?, ?, ?, ?)", [Uuid::v7()->toRfc4122(), $this->account->toRfc4122(), 'fixture.null', 'resource_type' === $nullable ? null : self::TYPE, 'resource_id' === $nullable ? null : $this->resource->toRfc4122()]), NotNullConstraintViolationException::class);
                }
            }
        }
        self::assertSame(4, $this->assignmentCount($this->account));
    }

    public function testLateSqlFailureRollsBackEarlierSourceAndCliReturnsOnlyFixedError(): void
    {
        $name = 'authorizing_fault_'.bin2hex(random_bytes(6));
        $account = $this->account->toRfc4122();
        $this->observer->executeStatement("CREATE FUNCTION public.$name() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.account_id = '$account'::uuid THEN RAISE EXCEPTION 'authorizing-private-fault-canary'; END IF; RETURN NEW; END; $$");
        try {
            $this->observer->executeStatement("CREATE TRIGGER $name BEFORE INSERT ON public.authorizing_global_permission_grant FOR EACH ROW EXECUTE FUNCTION public.$name()");
            $this->sql->start();
            $this->assertThrows(fn () => $this->change([$this->sourceChange(0), $this->sourceChange(2)]), DriverException::class);
            self::assertCount(2, $this->sql->writes(), 'Failure is in the later source, after the earlier INSERT actually executed.');
            $this->sql->stop();
            self::assertSame(0, $this->assignmentCount($this->account));
            $process = $this->cli(['change-batch', $account, '-vvv'], json_encode([
                ['operation' => 'add', 'kind' => 'role', 'key' => 'task_tracking.reader', 'scope' => 'global'],
                ['operation' => 'add', 'kind' => 'permission', 'key' => self::VIEW, 'scope' => 'global'],
            ], JSON_THROW_ON_ERROR));
            $this->fixedFailure($process, 1, 'Authorization operation failed.');
            self::assertSame(0, $this->assignmentCount($this->account));
        } finally {
            $this->observer->executeStatement("DROP TRIGGER IF EXISTS $name ON public.authorizing_global_permission_grant");
            $this->observer->executeStatement("DROP FUNCTION public.$name()");
        }
        self::assertSame(2, $this->change([$this->sourceChange(0), $this->sourceChange(2)])->added);
    }

    public function testNativeOperatorCliManagementBatchStdinAndStrictTransportErrors(): void
    {
        $account = $this->account->toRfc4122();
        $scope = ['--resource-type='.self::TYPE, '--resource-id='.$this->resource->toRfc4122()];
        self::assertSame(['allowed' => false], $this->json($this->cli(['check', $account, self::VIEW, '--global'])));
        // The native console adapter supplies its exact assignments operator scope.
        self::assertSame(1, $this->json($this->cli(['role:assign', $account, 'task_tracking.reader', '--global']))['added']);
        self::assertSame(1, $this->json($this->cli(['permission:grant', $account, self::VIEW, ...$scope]))['added']);
        self::assertSame(1, $this->json($this->cli(['role:remove', $account, 'task_tracking.reader', '--global']))['removed']);
        $checks = json_encode([
            ['accountId' => $account, 'permission' => self::VIEW, 'scope' => 'resource', 'resourceType' => self::TYPE, 'resourceId' => $this->resource->toRfc4122()],
            ['accountId' => $account, 'permission' => self::VIEW, 'scope' => 'global'],
        ], JSON_THROW_ON_ERROR);
        self::assertSame(['decisions' => [['allowed' => true], ['allowed' => false]]], $this->json($this->cli(['check-batch'], $checks)));
        self::assertSame(1, $this->json($this->cli(['permission:revoke', $account, self::VIEW, ...$scope]))['removed']);
        $change = json_encode([['operation' => 'add', 'kind' => 'role', 'key' => 'task_tracking.editor', 'scope' => 'resource', 'resourceType' => self::TYPE, 'resourceId' => $this->resource->toRfc4122()]], JSON_THROW_ON_ERROR);
        self::assertSame(['requested' => 1, 'added' => 1, 'removed' => 0, 'unchanged' => 0], $this->json($this->cli(['change-batch', $account], $change)));
        self::assertSame(1, $this->json($this->cli(['change-batch', $account], $change))['unchanged']);
        foreach ([[], ['--global', ...$scope], ['--resource-type='.self::TYPE]] as $invalidScope) {
            $this->fixedFailure($this->cli(['check', $account, self::VIEW, ...$invalidScope]), 2, 'Invalid authorization input.');
        }
        foreach ([['check-batch', $checks], ['change-batch', $change]] as [$command, $validInput]) {
            $arguments = 'check-batch' === $command ? [$command] : [$command, $account];
            $this->json($this->cli($arguments, str_pad($validInput, 65536)));
            $this->fixedFailure($this->cli($arguments, str_pad($validInput, 65537)), 2, 'Invalid authorization input.');
        }
        foreach (['{', '{}', '[{"unexpected":"authorizing-private-input-canary"}]', '['.str_repeat('[', 17).'0'.str_repeat(']', 17).']'] as $invalid) {
            foreach ([['check-batch'], ['change-batch', $account]] as $arguments) {
                $this->fixedFailure($this->cli($arguments, $invalid), 2, 'Invalid authorization input.');
            }
        }
        foreach (['=', 'e30', 'not+a+cursor', str_repeat('a', 513)] as $cursor) {
            $this->fixedFailure($this->cli(['assignments', $account, '--after='.$cursor]), 2, 'Invalid authorization input.');
        }
        $this->fixedFailure($this->cli(['assignments', $account, '--limit=101']), 2, 'Invalid authorization input.');
        $this->fixedFailure($this->cli(['permission:grant', Uuid::v7()->toRfc4122(), self::VIEW, '--global']), 2, 'Invalid authorization input.');
    }

    public function testMixedSourceKeysetPagesSurviveDeletedCursorAndDocumentSnapshotChanges(): void
    {
        $this->change(array_map(fn (int $source): AssignmentChangeInput => $this->sourceChange($source), range(0, 3)));
        foreach (range(0, 3) as $source) {
            $this->seedAssignment($source, $this->account, 'retired.page', Uuid::v7());
        }
        $seen = [];
        $sources = [];
        $cursor = null;
        $pages = 0;
        do {
            self::assertLessThanOrEqual(4, ++$pages, 'A broken continuation must not create an unbounded CLI loop.');
            $arguments = ['assignments', $this->account->toRfc4122(), '--limit=3'];
            if (null !== $cursor) {
                $arguments[] = '--after='.$cursor;
            }
            $page = $this->json($this->cli($arguments));
            self::assertIsArray($page['assignments']);
            self::assertLessThanOrEqual(3, count($page['assignments']));
            foreach ($page['assignments'] as $row) {
                self::assertIsArray($row);
                self::assertIsString($row['id']);
                self::assertNotContains($row['id'], $seen);
                $seen[] = $row['id'];
                $sources[] = $row['source'];
            }
            $cursor = $page['next'];
            self::assertTrue(null === $cursor || is_string($cursor));
            if (is_string($cursor)) {
                $this->fixedFailure($this->cli(['assignments', $this->other->toRfc4122(), '--after='.$cursor]), 2, 'Invalid authorization input.');
            }
            self::assertLessThanOrEqual(8, count($seen), 'Bound pagination even if a broken cursor repeats.');
        } while (is_string($cursor));
        self::assertCount(8, $seen);
        self::assertSame([0, 0, 1, 1, 2, 2, 3, 3], $sources);

        // A separate generated account makes UUID ordering deterministic without
        // replacing IDs of production-created rows. Each page is a new snapshot.
        $account = $this->seedAccount();
        $ids = array_map(static fn (int $i): Uuid => Uuid::fromString(sprintf('10000000-0000-4000-8000-%012d', $i)), [10, 20, 30]);
        foreach ($ids as $i => $id) {
            $this->seedAssignment(0, $account, 'retired.page.'.$i, $id);
        }
        $first = $this->page($account, limit: 2);
        self::assertNotNull($first->next);
        self::assertTrue($ids[1]->equals($first->next->assignmentId));
        $after = new AssignmentCursorInput($account, 0, $ids[1]);
        $this->change([new AssignmentChangeInput('remove', 'role', 'retired.page.1', 'global')], $account);
        $behind = Uuid::fromString('10000000-0000-4000-8000-000000000015');
        $this->seedAssignment(0, $account, 'retired.behind', $behind);
        $next = $this->page($account, $after, 2);
        self::assertCount(1, $next->assignments);
        self::assertTrue($ids[2]->equals($next->assignments[0]->id), 'Deleting the cursor row does not break continuation; insertion behind it is omitted.');
        $this->change([new AssignmentChangeInput('add', 'role', 'task_tracking.reader', 'global')], $account);
        $beforeRemoval = $this->page($account)->assignments[0];
        self::assertSame('task_tracking.reader', $beforeRemoval->key);
        $this->change([new AssignmentChangeInput('remove', 'role', 'task_tracking.reader', 'global')], $account);
        $this->change([new AssignmentChangeInput('add', 'role', 'task_tracking.reader', 'global')], $account);
        $regranted = $this->page($account, new AssignmentCursorInput($account, 0, $beforeRemoval->id));
        self::assertSame('task_tracking.reader', $regranted->assignments[0]->key);
        self::assertFalse($beforeRemoval->id->equals($regranted->assignments[0]->id), 'Removal/regrant has a new immutable row ID and can reappear.');
    }

    public function testUnflushedTaskAndNestedInitialGrantCommitOrRollbackAsOneRoot(): void
    {
        $actor = $this->seedCreatorAdministrator();
        foreach (['commit', 'parent-failure', 'caught-bad-grant'] as $mode) {
            $taskId = null;
            $caught = false;
            $this->fault->afterAdd = function (Task $task) use ($mode, &$taskId, &$caught): void {
                $taskId = $task->id();
                $this->ownedTasks[] = $taskId;
                self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM task_tracking_task WHERE id = ?', [$taskId->toRfc4122()]), 'Task is only scheduled, not yet SQL-visible.');
                self::assertSame(0, $this->observer->fetchOne('SELECT count(*) FROM task_tracking_task WHERE id = ?', [$taskId->toRfc4122()]));
                $grant = new AssignmentChangeInput('add', 'role', 'task_tracking.editor', 'resource', self::TYPE, $taskId);
                self::assertSame(1, $this->change([$grant], nested: true)->added);
                self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM authorizing_resource_role_assignment WHERE account_id = ? AND resource_id = ?', [$this->account->toRfc4122(), $taskId->toRfc4122()]));
                self::assertSame(0, $this->observer->fetchOne('SELECT count(*) FROM authorizing_resource_role_assignment WHERE account_id = ? AND resource_id = ?', [$this->account->toRfc4122(), $taskId->toRfc4122()]), 'Independent observer sees no early nested commit.');
                if ('caught-bad-grant' === $mode) {
                    try {
                        $this->change([new AssignmentChangeInput('add', 'role', 'task_tracking.creator', 'resource', self::TYPE, $taskId)], nested: true);
                    } catch (InvalidAuthorizationInput) {
                        $caught = true;
                    }
                }
                if ('parent-failure' === $mode) {
                    throw new \RuntimeException('Synthetic parent failure.');
                }
            };
            if ('commit' === $mode) {
                $this->execution->run($actor, fn () => $this->commands->dispatch(new CreateTaskCommand($this->prefix.'-'.$mode)));
            } else {
                $this->assertThrows(fn () => $this->execution->run($actor, fn () => $this->commands->dispatch(new CreateTaskCommand($this->prefix.'-'.$mode))), \Throwable::class);
            }
            self::assertInstanceOf(Uuid::class, $taskId);
            self::assertSame('caught-bad-grant' === $mode, $caught);
            self::assertSame('commit' === $mode ? 1 : 0, $this->observer->fetchOne('SELECT count(*) FROM task_tracking_task WHERE id = ?', [$taskId->toRfc4122()]));
            self::assertSame('commit' === $mode ? 1 : 0, $this->observer->fetchOne('SELECT count(*) FROM authorizing_resource_role_assignment WHERE account_id = ? AND resource_id = ?', [$this->account->toRfc4122(), $taskId->toRfc4122()]));
            self::assertFalse($this->connection->isTransactionActive());
        }
        $this->fault->afterAdd = null;
        self::assertSame(1, $this->change([$this->sourceChange(0)])->added, 'Same-process bus recovers after the failed root.');
    }

    public function testConcurrentDuplicateCliMutationsBlockPerAccountAndUnrelatedAccountProgresses(): void
    {
        $this->holdAccountLock();
        $processes = [];
        try {
            foreach (['first', 'second'] as $suffix) {
                $name = $this->prefix.'-'.$suffix;
                $process = $this->process(['role:assign', $this->account->toRfc4122(), 'task_tracking.reader', '--global'], name: $name);
                $process->start();
                $processes[] = $process;
                $this->waitForAdvisoryWaiter($name, $process);
            }
            self::assertSame(0, $this->assignmentCount($this->account));
            self::assertSame(1, $this->json($this->cli(['role:assign', $this->other->toRfc4122(), 'task_tracking.reader', '--global']))['added'], 'An unrelated account proceeds while both target-account mutations wait.');
            self::assertSame(1, $this->assignmentCount($this->other), 'The unrelated mutation committed while the target account remained blocked.');
            foreach ($processes as $process) {
                self::assertTrue($process->isRunning());
            }
            $this->observer->commit();
            $added = [];
            $unchanged = [];
            foreach ($processes as $process) {
                $process->wait();
                $result = $this->json($process);
                $added[] = $result['added'];
                $unchanged[] = $result['unchanged'];
            }
            sort($added);
            sort($unchanged);
            self::assertSame([0, 1], $added);
            self::assertSame([0, 1], $unchanged);
            self::assertSame(1, $this->assignmentCount($this->account));
        } finally {
            $this->releaseAndStop($processes);
        }
    }

    public function testOrderedOpposingCliMutationsFollowLockOrderRatherThanRevokeWins(): void
    {
        foreach ([['role:assign', 'role:remove', false], ['role:remove', 'role:assign', true]] as [$first, $second, $allowed]) {
            $this->holdAccountLock();
            $processes = [];
            try {
                foreach ([$first, $second] as $i => $command) {
                    $name = $this->prefix.'-ordered-'.$i;
                    $process = $this->process([$command, $this->account->toRfc4122(), 'task_tracking.reader', '--global'], name: $name);
                    $process->start();
                    $processes[] = $process;
                    $this->waitForAdvisoryWaiter($name, $process);
                }
                $this->observer->commit();
                foreach ($processes as $process) {
                    $process->wait();
                    $this->json($process);
                }
                self::assertSame([$allowed], $this->decisions([$this->check($this->account)]));
            } finally {
                $this->releaseAndStop($processes);
            }
        }
    }

    public function testLockTimeoutRollsBackNestedRootAndReleasesLocksForRecovery(): void
    {
        $actor = $this->seedCreatorAdministrator();
        $this->holdAccountLock();
        $taskId = null;
        $this->fault->afterAdd = function (Task $task) use (&$taskId): void {
            $taskId = $task->id();
            $this->ownedTasks[] = $taskId;
            $this->connection->executeStatement("SET LOCAL lock_timeout = '250ms'");
            // Acquire another account's lock and write first: failure must release
            // that already-held lock too, not merely cancel the blocked request.
            $this->change([$this->sourceChange(0)], $this->other, nested: true);
            $this->change([$this->sourceChange(0)], nested: true);
        };
        try {
            $started = microtime(true);
            $failure = $this->assertThrows(fn () => $this->execution->run($actor, fn () => $this->commands->dispatch(new CreateTaskCommand($this->prefix.'-timeout'))), DriverException::class);
            self::assertLessThan(5, microtime(true) - $started);
            self::assertStringContainsString('55P03', $failure->getMessage());
            self::assertSame(0, $this->assignmentCount($this->other));
            self::assertFalse($this->connection->isTransactionActive());
        } finally {
            $this->observer->rollBack();
            $this->fault->afterAdd = null;
        }
        self::assertInstanceOf(Uuid::class, $taskId);
        self::assertSame(0, $this->observer->fetchOne('SELECT count(*) FROM task_tracking_task WHERE id = ?', [$taskId->toRfc4122()]));
        self::assertSame(1, $this->json($this->cli(['role:assign', $this->other->toRfc4122(), 'task_tracking.reader', '--global']))['added']);
        self::assertSame(1, $this->change([$this->sourceChange(0)])->added);
    }

    /** Also run by main after PostgreSQL restarts; creates fresh owned data. */
    public function testRecoveryChecks(): void
    {
        $account = $this->account->toRfc4122();
        self::assertSame(1, $this->json($this->cli(['role:assign', $account, 'task_tracking.reader', '--global']))['added']);
        self::assertSame(['decisions' => [['allowed' => true]]], $this->json($this->cli(['check-batch'], json_encode([
            ['accountId' => $account, 'permission' => self::VIEW, 'scope' => 'global'],
        ], JSON_THROW_ON_ERROR))));
        self::assertSame(1, $this->json($this->cli(['role:remove', $account, 'task_tracking.reader', '--global']))['removed']);
        self::assertSame(['allowed' => false], $this->json($this->cli(['check', $account, self::VIEW, '--global'])));
    }

    private function seedCreatorAdministrator(): Actor
    {
        // A separate real account has both capabilities for the whole nested root;
        // neither the target account nor a scope switch supplies this authority.
        $id = $this->seedAccount();
        foreach (['task_tracking.creator', 'authorizing.administrator'] as $role) {
            $this->seedAssignment(0, $id, $role, Uuid::v7());
        }

        return new Actor(ActorKind::Account, $id);
    }

    private function seedAccount(): Uuid
    {
        $id = Uuid::v7();
        $this->ownedAccounts[] = $id;
        // Trusted fixture seed only. No authentication journey uses this sentinel.
        $this->observer->executeStatement('INSERT INTO authenticating_account (id, email, password_hash) VALUES (?, ?, ?)', [$id->toRfc4122(), $this->prefix.'-'.$id->toRfc4122().'@example.test', 'unused-authorizing-fixture']);

        return $id;
    }

    private function seedAssignment(int $source, Uuid $account, string $key, Uuid $id, string $type = self::TYPE): void
    {
        $table = self::TABLES[$source];
        $keyColumn = $source < 2 ? 'role_key' : 'permission_key';
        $scoped = 1 === $source % 2;
        $this->observer->executeStatement("INSERT INTO public.$table (id, account_id, $keyColumn".($scoped ? ', resource_type, resource_id' : '').') VALUES (?, ?, ?'.($scoped ? ', ?, ?' : '').')', $scoped ? [$id->toRfc4122(), $account->toRfc4122(), $key, $type, $this->resource->toRfc4122()] : [$id->toRfc4122(), $account->toRfc4122(), $key]);
    }

    private function sourceChange(int $source, string $operation = 'add'): AssignmentChangeInput
    {
        return new AssignmentChangeInput($operation, $source < 2 ? 'role' : 'permission', $source < 2 ? 'task_tracking.reader' : self::VIEW, 0 === $source % 2 ? 'global' : 'resource', 0 === $source % 2 ? null : self::TYPE, 0 === $source % 2 ? null : $this->resource);
    }

    /** @param list<AssignmentChangeInput> $changes */
    private function change(array $changes, ?Uuid $account = null, bool $nested = false): ChangeAccountAssignmentsResult
    {
        $command = new ChangeAccountAssignmentsCommand($account ?? $this->account, $changes);
        // Nested dispatch retains the root account identity and reauthorizes it.
        $result = $nested
            ? $this->commands->dispatch($command)
            : $this->execution->run(new Actor(ActorKind::Operator, scope: 'assignments'), fn () => $this->commands->dispatch($command));
        self::assertInstanceOf(ChangeAccountAssignmentsResult::class, $result);

        return $result;
    }

    private function check(Uuid $account, ?Uuid $resource = null, string $permission = self::VIEW): PermissionCheckInput
    {
        return new PermissionCheckInput($account, $permission, null === $resource ? 'global' : 'resource', null === $resource ? null : self::TYPE, $resource);
    }

    /**
     * @param list<PermissionCheckInput> $checks
     *
     * @return list<bool>
     */
    private function decisions(array $checks): array
    {
        $result = $this->execution->run(new Actor(ActorKind::Operator, scope: 'assignments'), fn () => $this->queries->ask(new EvaluatePermissionsQuery($checks)));
        self::assertInstanceOf(EvaluatePermissionsResult::class, $result);

        return array_map(static fn ($decision): bool => $decision->allowed, $result->decisions);
    }

    private function page(?Uuid $account = null, ?AssignmentCursorInput $after = null, int $limit = 50): ListAccountAssignmentsResult
    {
        $result = $this->execution->run(new Actor(ActorKind::Operator, scope: 'assignments'), fn () => $this->queries->ask(new ListAccountAssignmentsQuery($account ?? $this->account, $limit, $after)));
        self::assertInstanceOf(ListAccountAssignmentsResult::class, $result);

        return $result;
    }

    private function assignmentCount(Uuid $account): int
    {
        $total = 0;
        foreach (self::TABLES as $table) {
            $count = $this->observer->fetchOne('SELECT count(*) FROM public.'.$table.' WHERE account_id = ?', [$account->toRfc4122()]);
            self::assertIsInt($count);
            $total += $count;
        }

        return $total;
    }

    /** @param class-string<\Throwable> $type */
    private function assertThrows(callable $operation, string $type): \Throwable
    {
        $failure = null;
        try {
            $operation();
        } catch (\Throwable $caught) {
            if ($caught instanceof AssertionFailedError) {
                throw $caught;
            }
            $failure = $caught;
        }
        self::assertInstanceOf($type, $failure);

        return $failure;
    }

    /** @param list<string> $arguments */
    private function process(array $arguments, ?string $input = null, ?string $name = null): Process
    {
        $command = array_shift($arguments);
        self::assertNotNull($command);
        $process = new Process(['php', 'bin/console', 'app:authorization:'.$command, ...$arguments, '--no-interaction', '--no-ansi'], dirname(__DIR__, 2), null === $name ? null : ['PGAPPNAME' => $name], timeout: 20);
        $process->setInput($input);

        return $process;
    }

    /** @param list<string> $arguments */
    private function cli(array $arguments, ?string $input = null): Process
    {
        $process = $this->process($arguments, $input);
        $process->run();

        return $process;
    }

    /** @return array<string, mixed> */
    private function json(Process $process): array
    {
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
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

    private function fixedFailure(Process $process, int $status, string $message): void
    {
        self::assertSame($status, $process->getExitCode());
        self::assertSame($message."\n", $process->getOutput());
        self::assertSame('', $process->getErrorOutput());
    }

    private function holdAccountLock(): void
    {
        $this->observer->beginTransaction();
        $this->observer->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))', ['authorizing.account_assignments:'.$this->account->toRfc4122()])->free();
    }

    private function waitForAdvisoryWaiter(string $name, Process $process): void
    {
        $deadline = microtime(true) + 10;
        do {
            $waiters = $this->observer->fetchOne("SELECT count(*) FROM pg_catalog.pg_stat_activity a JOIN pg_catalog.pg_locks l ON l.pid = a.pid WHERE a.application_name = ? AND a.datname = current_database() AND a.wait_event_type = 'Lock' AND lower(a.wait_event) = 'advisory' AND l.locktype = 'advisory' AND NOT l.granted", [$name]);
            if (1 === $waiters) {
                self::assertTrue($process->isRunning());

                return;
            }
            // pg_stat_activity snapshots are transaction-local. Refresh on each
            // poll while deliberately retaining the fixture's account lock.
            $this->observer->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            self::assertTrue($process->isRunning(), 'CLI exited before reaching the account advisory-lock barrier.');
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('CLI did not reach the PostgreSQL advisory-lock barrier within 10 seconds.');
    }

    /** @param list<Process> $processes */
    private function releaseAndStop(array $processes): void
    {
        if ($this->observer->isTransactionActive()) {
            $this->observer->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    /** @param Statement $statement */
    private function explain(array $statement, string $label): void
    {
        $json = $this->observer->fetchOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$statement['sql'], $statement['params']);
        self::assertIsString($json);
        $plan = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($plan);
        $nodes = $this->planNodes($plan);
        self::assertNotEmpty($nodes);
        $root = $nodes[0];
        self::assertIsNumeric($root['Total Cost']);
        self::assertIsNumeric($root['Actual Rows']);
        $indexes = array_values(array_filter($nodes, static fn (array $node): bool => isset($node['Index Name'])));
        self::assertNotEmpty($indexes, 'Selective authorization workload must exercise an indexed access path without disabling sequential scans.');
        $indexNames = [];
        foreach ($indexes as $node) {
            self::assertIsString($node['Index Name']);
            $indexNames[] = $node['Index Name'];
        }
        $limits = count(array_filter($nodes, static fn (array $node): bool => 'Limit' === ($node['Node Type'] ?? null)));
        if ('100 decisions' !== $label) {
            self::assertGreaterThanOrEqual(2, $limits);
            self::assertLessThanOrEqual(8, $root['Actual Rows'], 'The actual page statement returns at most limit plus one.');
        } else {
            self::assertEquals(100, $root['Actual Rows']);
        }
        if ('source 3 continuation' === $label) {
            foreach ($nodes as $node) {
                if (in_array($node['Relation Name'] ?? null, array_slice(self::TABLES, 0, 3), true)) {
                    self::assertEquals(0, $node['Actual Loops'], 'Earlier source ranks must not be scanned on continuation.');
                }
            }
        }
        // Only safe summary evidence reaches retained PHPUnit stdout; no SQL,
        // parameters, credentials, full plans or timing thresholds are dumped.
        fwrite(STDOUT, "\nAuthorizing EXPLAIN ".json_encode(['case' => $label, 'indexes' => array_values(array_unique($indexNames)), 'limit_nodes' => $limits, 'total_cost' => $root['Total Cost'], 'actual_rows' => $root['Actual Rows']], JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @param array<array-key, mixed> $tree
     *
     * @return list<array<array-key, mixed>>
     */
    private function planNodes(array $tree): array
    {
        $nodes = isset($tree['Node Type']) ? [$tree] : [];
        foreach ($tree as $value) {
            if (is_array($value)) {
                array_push($nodes, ...$this->planNodes($value));
            }
        }

        return $nodes;
    }
}
