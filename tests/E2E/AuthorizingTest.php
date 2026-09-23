<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\Authorizing\Application\ChangeSubjectAssignments\AssignmentChangeInput;
use App\Module\Authorizing\Application\ChangeSubjectAssignments\ChangeSubjectAssignmentsCommand;
use App\Module\Authorizing\Application\ChangeSubjectAssignments\ChangeSubjectAssignmentsResult;
use App\Module\Authorizing\Application\DefineRole\DefineRoleCommand;
use App\Module\Authorizing\Application\DefineRole\DefineRoleResult;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EntitlementCheckInput;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsQuery;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsResult;
use App\Module\Authorizing\Application\ListSubjectAssignments\AssignmentCursorInput;
use App\Module\Authorizing\Application\ListSubjectAssignments\ListSubjectAssignmentsQuery;
use App\Module\Authorizing\Application\ListSubjectAssignments\ListSubjectAssignmentsResult;
use App\Module\Authorizing\Application\RetireRole\RetireRoleCommand;
use App\Module\Authorizing\Application\RetireRole\RetireRoleResult;
use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;
use App\Platform\Authorization\Actor;
use App\Platform\Authorization\ActorKind;
use App\Platform\Authorization\ExecutionContext;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\QueryBus;
use App\Tests\Fixtures\Authorizing\AuthorizingKernel;
use App\Tests\Fixtures\Authorizing\SqlLog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/** @phpstan-import-type Statement from SqlLog */
final class AuthorizingTest extends DatabaseTestCase
{
    private const string VIEW = 'task_tracking.task.view';
    private const array ASSIGNMENT_TABLES = ['authorizing_role_assignment', 'authorizing_permission_grant'];

    private Connection $connection;
    private Connection $observer;
    private CommandBus $commands;
    private QueryBus $queries;
    private ExecutionContext $execution;
    private SqlLog $sql;
    private Uuid $subject;
    private string $role;
    /** @var list<string> */
    private array $ownedRoles = [];

    protected static function getKernelClass(): string
    {
        return AuthorizingKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->database();
        $this->observer = DriverManager::getConnection($this->connection->getParams());
        $container = self::getContainer();
        $commands = $container->get('test.authorizing.commands');
        $queries = $container->get('test.authorizing.queries');
        $sql = $container->get('test.authorizing.sql');
        $execution = $container->get(ExecutionContext::class);
        self::assertInstanceOf(CommandBus::class, $commands);
        self::assertInstanceOf(QueryBus::class, $queries);
        self::assertInstanceOf(SqlLog::class, $sql);
        self::assertInstanceOf(ExecutionContext::class, $execution);
        $this->commands = $commands;
        $this->queries = $queries;
        $this->sql = $sql;
        $this->execution = $execution;
        $this->subject = Uuid::v7();
        $this->role = 'test.reader.'.bin2hex(random_bytes(6));
        $this->seedRole($this->role, 'Task reader', [self::VIEW]);
    }

    protected function tearDown(): void
    {
        if (isset($this->sql)) {
            $this->sql->stop();
        }
        if (isset($this->observer)) {
            if ($this->observer->isTransactionActive()) {
                $this->observer->rollBack();
            }
            foreach (self::ASSIGNMENT_TABLES as $table) {
                $this->observer->executeStatement('DELETE FROM public.'.$table.' WHERE subject_id = ?', [$this->subject->toRfc4122()]);
            }
            foreach (array_reverse($this->ownedRoles) as $role) {
                $this->observer->delete('public.authorizing_role_assignment', ['role_key' => $role]);
                $this->observer->delete('public.authorizing_role', ['role_key' => $role]);
            }
            $this->observer->close();
        }
        parent::tearDown();
    }

    public function testRoleAndDirectGrantAreAdditiveWhileUnknownPermissionsDeny(): void
    {
        self::assertSame([false], $this->decisions([new EntitlementCheckInput($this->subject, self::VIEW)]));

        self::assertSame(1, $this->change([new AssignmentChangeInput('add', 'role', $this->role)])->added);
        self::assertSame([true], $this->decisions([new EntitlementCheckInput($this->subject, self::VIEW)]));
        self::assertSame(1, $this->change([new AssignmentChangeInput('remove', 'role', $this->role)])->removed);
        self::assertSame([false], $this->decisions([new EntitlementCheckInput($this->subject, self::VIEW)]));

        self::assertSame(1, $this->change([new AssignmentChangeInput('add', 'permission', self::VIEW)])->added);
        self::assertSame([true], $this->decisions([new EntitlementCheckInput($this->subject, self::VIEW)]));
        self::assertSame(1, $this->change([new AssignmentChangeInput('remove', 'permission', self::VIEW)])->removed);
        self::assertSame([false], $this->decisions([new EntitlementCheckInput($this->subject, self::VIEW)]));

        $this->observer->insert('public.authorizing_permission_grant', [
            'subject_id' => $this->subject->toRfc4122(),
            'permission_key' => 'unknown.permission',
        ]);
        self::assertSame([false], $this->decisions([new EntitlementCheckInput($this->subject, 'unknown.permission')]));

        $missingRole = 'missing.role.'.bin2hex(random_bytes(6));
        $this->assertThrows(fn () => $this->change([new AssignmentChangeInput('add', 'role', $missingRole)]), InvalidAuthorizationInputException::class);
        self::assertSame(0, $this->observer->fetchOne('SELECT count(*) FROM authorizing_role_assignment WHERE subject_id = ? AND role_key = ?', [$this->subject->toRfc4122(), $missingRole]));
    }

    public function testCrossModuleRoleRevisionAndRetirementAffectLiveAssignment(): void
    {
        $role = 'test.cross.'.bin2hex(random_bytes(6));
        $created = $this->catalogue(new DefineRoleCommand($role, 'Cross-module operator', [self::VIEW, 'authorizing.manage']));
        self::assertInstanceOf(DefineRoleResult::class, $created);
        $this->ownedRoles[] = $role;
        self::assertSame(['authorizing.manage', self::VIEW], $created->permissions);
        self::assertSame(['key', 'label', 'revision', 'retiredAt', 'permissions'], array_keys(get_object_vars($created)));

        self::assertSame(1, $this->change([new AssignmentChangeInput('add', 'role', $role)])->added);
        self::assertSame([true, true], $this->decisions([
            new EntitlementCheckInput($this->subject, self::VIEW),
            new EntitlementCheckInput($this->subject, 'authorizing.manage'),
        ]));

        $updated = $this->catalogue(new DefineRoleCommand($role, 'Assignment operator', ['authorizing.manage'], 1));
        self::assertInstanceOf(DefineRoleResult::class, $updated);
        self::assertSame([false, true], $this->decisions([
            new EntitlementCheckInput($this->subject, self::VIEW),
            new EntitlementCheckInput($this->subject, 'authorizing.manage'),
        ]));

        $retired = $this->catalogue(new RetireRoleCommand($role, 2));
        self::assertInstanceOf(RetireRoleResult::class, $retired);
        self::assertSame([false], $this->decisions([new EntitlementCheckInput($this->subject, 'authorizing.manage')]));
        self::assertSame([['role', $role]], $this->assignmentPairs($this->page()));
        self::assertSame(1, $this->change([new AssignmentChangeInput('remove', 'role', $role)])->removed);
        $this->assertThrows(fn () => $this->change([new AssignmentChangeInput('add', 'role', $role)]), InvalidAuthorizationInputException::class);
    }

    public function testAssignmentsAreIdempotentAndPageOnlyByKindAndKey(): void
    {
        $changes = [
            new AssignmentChangeInput('add', 'role', $this->role),
            new AssignmentChangeInput('add', 'permission', self::VIEW),
        ];
        $added = $this->change($changes);
        self::assertSame([2, 2, 0, 0], [$added->requested, $added->added, $added->removed, $added->unchanged]);
        $unchanged = $this->change($changes);
        self::assertSame([2, 0, 0, 2], [$unchanged->requested, $unchanged->added, $unchanged->removed, $unchanged->unchanged]);

        $first = $this->page(1);
        self::assertSame([['role', $this->role]], $this->assignmentPairs($first));
        self::assertSame(['kind', 'key'], array_keys(get_object_vars($first->assignments[0])));
        self::assertNotNull($first->next);
        self::assertSame(['subjectId', 'kind', 'key'], array_keys(get_object_vars($first->next)));
        self::assertTrue($this->subject->equals($first->next->subjectId));
        $second = $this->page(1, new AssignmentCursorInput($first->next->subjectId, $first->next->kind, $first->next->key));
        self::assertSame([['permission', self::VIEW]], $this->assignmentPairs($second));
        self::assertNull($second->next);

        $removals = [
            new AssignmentChangeInput('remove', 'role', $this->role),
            new AssignmentChangeInput('remove', 'permission', self::VIEW),
        ];
        $removed = $this->change($removals);
        self::assertSame([2, 0, 2, 0], [$removed->requested, $removed->added, $removed->removed, $removed->unchanged]);
        $missing = $this->change($removals);
        self::assertSame([2, 0, 0, 2], [$missing->requested, $missing->added, $missing->removed, $missing->unchanged]);
        self::assertSame([], $this->page()->assignments);
    }

    public function testEvaluationUsesOneBoundedReadForOneHundredOrderedChecks(): void
    {
        $this->change([new AssignmentChangeInput('add', 'role', $this->role)]);
        $checks = [];
        for ($index = 0; $index < 100; ++$index) {
            $checks[] = new EntitlementCheckInput(0 === $index % 2 ? $this->subject : Uuid::v7(), self::VIEW);
        }

        $this->sql->start();
        $decisions = $this->decisions($checks);
        $reads = $this->sql->reads();
        $this->sql->stop();

        self::assertSame(array_map(static fn (int $index): bool => 0 === $index % 2, range(0, 99)), $decisions);
        self::assertCount(1, $reads);
        self::assertStringContainsString('authorizing_role_assignment', $reads[0]['sql']);
        self::assertStringContainsString('authorizing_permission_grant', $reads[0]['sql']);
    }

    public function testHundredAssignmentChangesAndPopulatedContinuationStaySetBased(): void
    {
        $roles = [];
        for ($index = 0; $index < 150; ++$index) {
            $role = sprintf('test.scale.%03d.%s', $index, bin2hex(random_bytes(4)));
            $this->seedRole($role, 'Scale role '.$index, [self::VIEW]);
            $roles[] = $role;
        }

        $initial = array_map(static fn (string $role): AssignmentChangeInput => new AssignmentChangeInput('add', 'role', $role), array_slice($roles, 0, 100));
        $this->sql->start();
        $added = $this->change($initial);
        $initialReads = $this->sql->reads();
        $initialWrites = $this->sql->writes();
        $this->sql->stop();
        self::assertSame([100, 100, 0, 0], [$added->requested, $added->added, $added->removed, $added->unchanged]);
        self::assertCount(1, $initialReads, 'One set-based active-role read validates all N=100 additions.');
        self::assertCount(1, $initialWrites, 'One grouped insert writes all N=100 role assignments.');
        self::assertCount(100, $initialReads[0]['params']);
        self::assertCount(200, $initialWrites[0]['params']);

        $mixed = [];
        for ($index = 0; $index < 50; ++$index) {
            $mixed[] = new AssignmentChangeInput('remove', 'role', $roles[$index]);
            $mixed[] = new AssignmentChangeInput('add', 'role', $roles[100 + $index]);
        }
        $this->sql->start();
        $changed = $this->change($mixed);
        $mixedReads = $this->sql->reads();
        $mixedWrites = $this->sql->writes();
        $this->sql->stop();
        self::assertSame([100, 50, 50, 0], [$changed->requested, $changed->added, $changed->removed, $changed->unchanged]);
        self::assertCount(1, $mixedReads, 'One set-based active-role read validates all additions in the mixed batch.');
        self::assertCount(2, $mixedWrites, 'Mixed N=100 role changes use one grouped delete and one grouped insert.');
        self::assertSame([100, 100], array_map(static fn (array $write): int => count($write['params']), $mixedWrites));

        self::assertSame(1, $this->change([new AssignmentChangeInput('add', 'permission', self::VIEW)])->added);
        $this->observer->executeStatement(
            'INSERT INTO public.authorizing_role_assignment (subject_id, role_key) SELECT md5(? || n::text)::uuid, CAST(? AS text) FROM generate_series(1, 8000) n ON CONFLICT DO NOTHING',
            ['authorizing-scale-distractor-', $roles[149]],
        );
        foreach (self::ASSIGNMENT_TABLES as $table) {
            $this->observer->executeStatement('ANALYZE public.'.$table);
        }

        $this->sql->start();
        $first = $this->page(100);
        $pageReads = $this->sql->reads();
        self::assertSame([], $this->sql->writes());
        $this->sql->stop();
        self::assertCount(100, $first->assignments);
        self::assertNotNull($first->next);
        self::assertCount(1, $pageReads, 'N=100 assignment listing uses one bounded union read.');
        self::assertStringNotContainsString('OFFSET', strtoupper($pageReads[0]['sql']));
        $this->explain($pageReads[0], 'subject assignments N=100', 101);

        $last = $this->page(100, new AssignmentCursorInput($first->next->subjectId, $first->next->kind, $first->next->key));
        self::assertSame([['permission', self::VIEW]], $this->assignmentPairs($last));
        self::assertNull($last->next);
    }

    public function testNativeCliUsesCleanGlobalContracts(): void
    {
        $subject = $this->subject->toRfc4122();
        self::assertSame(['allowed' => false], $this->json($this->cli(['check', $subject, self::VIEW])));
        self::assertSame(['requested' => 1, 'added' => 1, 'removed' => 0, 'unchanged' => 0], $this->json($this->cli(['role:assign', $subject, $this->role])));
        self::assertSame(['assignments' => [['kind' => 'role', 'key' => $this->role]], 'next' => null], $this->json($this->cli(['assignments', $subject])));
        self::assertSame(['allowed' => true], $this->json($this->cli(['check', $subject, self::VIEW])));
        self::assertSame(['requested' => 1, 'added' => 0, 'removed' => 1, 'unchanged' => 0], $this->json($this->cli(['role:remove', $subject, $this->role])));

        $batch = json_encode([
            ['subjectId' => $subject, 'permission' => self::VIEW],
            ['subjectId' => $subject, 'permission' => 'unknown.permission'],
        ], JSON_THROW_ON_ERROR);
        self::assertSame(['decisions' => [['allowed' => false], ['allowed' => false]]], $this->json($this->cli(['check-batch'], $batch)));
    }

    public function testAssignmentHoldingSharedCatalogueLockSerializesRetirement(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $function = 'authorization_assignment_barrier_'.$suffix;
        $trigger = 'authorization_assignment_barrier_'.$suffix;
        $barrier = 'authorization.assignment-race.'.$suffix;
        $assignmentName = 'assignment-race-'.$suffix;
        $retirementName = 'retirement-race-'.$suffix;
        $assignment = new Process([PHP_BINARY, 'bin/console', '--env=test', '--no-debug', '--no-interaction', '--no-ansi', 'app:authorization:role:assign', $this->subject->toRfc4122(), $this->role], dirname(__DIR__, 2), ['PGAPPNAME' => $assignmentName], timeout: 20);
        $retirement = new Process([PHP_BINARY, 'tests/Fixtures/Authorization/retire-role.php', $this->role, '1'], dirname(__DIR__, 2), ['PGAPPNAME' => $retirementName], timeout: 20);
        $barrierHeld = false;
        try {
            $this->observer->executeStatement("CREATE FUNCTION public.$function() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_advisory_xact_lock(hashtextextended('$barrier', 0)); RETURN NEW; END; $$");
            $this->observer->executeStatement("CREATE TRIGGER $trigger BEFORE INSERT ON public.authorizing_role_assignment FOR EACH ROW WHEN (NEW.role_key = '{$this->role}') EXECUTE FUNCTION public.$function()");
            $this->observer->executeQuery('SELECT pg_advisory_lock(hashtextextended(CAST(? AS text), 0))', [$barrier])->free();
            $barrierHeld = true;

            $assignment->start();
            $this->waitForLock($assignment, $assignmentName, 'Assignment did not reach its PostgreSQL barrier.');
            $retirement->start();
            $this->waitForLock($retirement, $retirementName, 'Retirement did not wait for the assignment catalogue lock.');

            self::assertSame(1, $this->observer->fetchOne('SELECT CASE WHEN pg_advisory_unlock(hashtextextended(CAST(? AS text), 0)) THEN 1 ELSE 0 END', [$barrier]));
            $barrierHeld = false;
            $assignment->wait();
            self::assertSame(['requested' => 1, 'added' => 1, 'removed' => 0, 'unchanged' => 0], $this->json($assignment));
            $retirement->wait();
            self::assertSame(0, $retirement->getExitCode(), $retirement->getErrorOutput());
            self::assertSame("RETIRED\n", $retirement->getOutput());
        } finally {
            if ($barrierHeld) {
                $this->observer->fetchOne('SELECT pg_advisory_unlock(hashtextextended(CAST(? AS text), 0))', [$barrier]);
            }
            foreach ([$assignment, $retirement] as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $this->observer->executeStatement("DROP TRIGGER IF EXISTS $trigger ON public.authorizing_role_assignment");
            $this->observer->executeStatement("DROP FUNCTION IF EXISTS public.$function()");
        }

        self::assertSame(1, $this->observer->fetchOne('SELECT count(*) FROM authorizing_role_assignment WHERE subject_id = ? AND role_key = ?', [$this->subject->toRfc4122(), $this->role]));
        self::assertSame([false], $this->decisions([new EntitlementCheckInput($this->subject, self::VIEW)]), 'The assignment committed first but became inert when the serialized retirement committed.');
        self::assertSame([['role', $this->role]], $this->assignmentPairs($this->page()));
        self::assertSame(1, $this->change([new AssignmentChangeInput('remove', 'role', $this->role)])->removed);
    }

    /** Also run after PostgreSQL restarts. */
    public function testRecoveryChecks(): void
    {
        $subject = $this->subject->toRfc4122();
        self::assertSame(1, $this->json($this->cli(['permission:grant', $subject, self::VIEW]))['added']);
        self::assertSame(['allowed' => true], $this->json($this->cli(['check', $subject, self::VIEW])));
        self::assertSame(1, $this->json($this->cli(['permission:revoke', $subject, self::VIEW]))['removed']);
        self::assertSame(['allowed' => false], $this->json($this->cli(['check', $subject, self::VIEW])));
    }

    /** @param list<string> $permissions */
    private function seedRole(string $key, string $label, array $permissions): void
    {
        $this->observer->insert('public.authorizing_role', [
            'role_key' => $key,
            'label' => $label,
            'revision' => 1,
        ]);
        foreach ($permissions as $permission) {
            $this->observer->insert('public.authorizing_role_permission', ['role_key' => $key, 'permission_key' => $permission]);
        }
        $this->ownedRoles[] = $key;
    }

    /** @param list<AssignmentChangeInput> $changes */
    private function change(array $changes): ChangeSubjectAssignmentsResult
    {
        $result = $this->execution->run(new Actor(ActorKind::Operator, scope: 'assignments'), fn () => $this->commands->dispatch(new ChangeSubjectAssignmentsCommand($this->subject, $changes)));
        self::assertInstanceOf(ChangeSubjectAssignmentsResult::class, $result);

        return $result;
    }

    private function catalogue(object $command): mixed
    {
        return $this->execution->run(new Actor(ActorKind::Operator, scope: 'catalogue'), fn () => $this->commands->dispatch($command));
    }

    /** @param list<EntitlementCheckInput> $checks
     * @return list<bool>
     */
    private function decisions(array $checks): array
    {
        $result = $this->execution->run(new Actor(ActorKind::Operator, scope: 'assignments'), fn () => $this->queries->ask(new EvaluateSubjectEntitlementsQuery($checks)));
        self::assertInstanceOf(EvaluateSubjectEntitlementsResult::class, $result);

        return array_map(static fn ($decision): bool => $decision->allowed, $result->decisions);
    }

    private function page(int $limit = 50, ?AssignmentCursorInput $after = null): ListSubjectAssignmentsResult
    {
        $result = $this->execution->run(new Actor(ActorKind::Operator, scope: 'assignments'), fn () => $this->queries->ask(new ListSubjectAssignmentsQuery($this->subject, $limit, $after)));
        self::assertInstanceOf(ListSubjectAssignmentsResult::class, $result);

        return $result;
    }

    /** @return list<array{string, string}> */
    private function assignmentPairs(ListSubjectAssignmentsResult $page): array
    {
        return array_map(static fn ($assignment): array => [$assignment->kind, $assignment->key], $page->assignments);
    }

    private function waitForLock(Process $process, string $applicationName, string $message): void
    {
        $deadline = microtime(true) + 10;
        while (true) {
            $blocked = $this->observer->fetchOne("SELECT count(*) FROM pg_catalog.pg_stat_activity WHERE application_name = ? AND datname = current_database() AND wait_event_type = 'Lock'", [$applicationName]);
            if (1 === $blocked) {
                return;
            }
            self::assertTrue($process->isRunning(), $message);
            self::assertLessThan($deadline, microtime(true), $message);
            usleep(10000);
        }
    }

    /** @param Statement $statement */
    private function explain(array $statement, string $label, int $maximum): void
    {
        $raw = $this->observer->fetchOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$statement['sql'], $statement['params']);
        self::assertIsString($raw);
        $plan = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($plan);
        $nodes = $this->planNodes($plan);
        self::assertNotEmpty($nodes);
        self::assertIsNumeric($nodes[0]['Actual Rows']);
        self::assertLessThanOrEqual($maximum, $nodes[0]['Actual Rows']);
        $indexes = [];
        foreach ($nodes as $node) {
            if (isset($node['Index Name'])) {
                self::assertIsString($node['Index Name']);
                $indexes[] = $node['Index Name'];
            }
        }
        self::assertNotEmpty($indexes, 'The populated selective workload must exercise the natural-key assignment indexes.');
        fwrite(STDOUT, sprintf("Task10 EXPLAIN %s: rows=%s indexes=%s\n", $label, (string) $nodes[0]['Actual Rows'], implode(',', array_unique($indexes))));
    }

    /**
     * @param array<mixed> $value
     *
     * @return list<array<string, mixed>>
     */
    private function planNodes(array $value): array
    {
        $nodes = [];
        if (isset($value['Node Type'])) {
            $node = [];
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $node[$key] = $item;
                }
            }
            $nodes[] = $node;
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                $nodes = [...$nodes, ...$this->planNodes($item)];
            }
        }

        return $nodes;
    }

    /** @param class-string<\Throwable> $type */
    private function assertThrows(callable $operation, string $type): \Throwable
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
        self::fail('Expected operation to fail.');
    }

    /** @param list<string> $arguments */
    private function cli(array $arguments, ?string $input = null): Process
    {
        $command = array_shift($arguments);
        self::assertNotNull($command);
        $process = new Process(['php', 'bin/console', 'app:authorization:'.$command, ...$arguments, '--no-interaction', '--no-ansi'], dirname(__DIR__, 2), timeout: 20);
        $process->setInput($input);
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
}
