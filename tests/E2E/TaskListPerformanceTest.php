<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\TaskTracking\Application\GetTask\GetTaskQuery;
use App\Module\TaskTracking\Application\GetTask\GetTaskResult;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

/** @phpstan-import-type Statement from \App\Tests\Fixtures\Authorizing\SqlLog */
final class TaskListPerformanceTest extends TaskTrackingTestCase
{
    public function testAccountCreateGetAndCompleteStayWithinReadBudgets(): void
    {
        $account = $this->account();
        foreach ([self::CREATE, self::VIEW, self::COMPLETE] as $permission) {
            $this->grant($account, $permission);
        }

        $this->sql->start();
        $task = $this->create($account, 'read-budgets');
        $createReads = $this->sql->reads();
        $createWrites = $this->sql->writes();
        $this->sql->stop();
        self::assertLessThanOrEqual(2, count($createReads));
        foreach ($createWrites as $write) {
            self::assertStringNotContainsString('authorizing_role_assignment', $write['sql']);
            self::assertStringNotContainsString('authorizing_permission_grant', $write['sql']);
        }

        $this->sql->start();
        $shown = $this->asAccount($account, fn () => $this->queries->ask(new GetTaskQuery($task->toRfc4122())));
        $getReads = $this->sql->reads();
        $this->sql->stop();
        self::assertInstanceOf(GetTaskResult::class, $shown);
        self::assertLessThanOrEqual(3, count($getReads));
        self::assertSame(1, count(array_filter($getReads, static fn (array $read): bool => str_contains($read['sql'], 'task_tracking_task'))), 'The handler must reuse the voter-loaded Task through the identity map.');

        $this->sql->start();
        self::assertTrue($this->complete($account, $task)?->changed);
        $completeReads = $this->sql->reads();
        $this->sql->stop();
        self::assertLessThanOrEqual(4, count($completeReads));
        self::assertSame(2, count(array_filter($completeReads, static fn (array $read): bool => str_contains($read['sql'], 'task_tracking_task'))), 'Completion adds only its owning lock read after admission.');
    }

    public function testHundredItemLivePagesStayWithinBudgetsAndUsePopulatedExistingIndexes(): void
    {
        $account = $this->account();
        $other = $this->account();
        $this->grant($account, self::VIEW);
        $this->observer->executeStatement("INSERT INTO task_tracking_task (id, title, owner_account_id) SELECT md5(? || ':' || n::text)::uuid, ? || '-distractor-' || n::text, CAST(? AS uuid) FROM generate_series(1, 8000) n", [$this->prefix, $this->prefix, $other->toRfc4122()]);
        $ids = [];
        foreach (range(1, 101) as $i) {
            $id = $this->task(ownerAccountId: $account);
            $ids[] = $id->toRfc4122();
        }
        foreach (['authorizing_role_assignment', 'authorizing_permission_grant', 'task_tracking_task', 'authenticating_account'] as $table) {
            $this->observer->executeStatement('ANALYZE public.'.$table);
        }
        $this->sql->start();
        $first = $this->page($account, 100);
        $reads = $this->sql->reads();
        self::assertSame([], $this->sql->writes());
        $this->sql->stop();
        self::assertSame(array_slice($ids, 0, 100), $this->ids($first));
        self::assertNotNull($first->next);
        self::assertLessThanOrEqual(3, count($reads), 'Account N=100 list includes existence, global entitlement and one owner-filtered Task read.');
        self::assertFalse($this->connection->isTransactionActive());
        $taskPlans = 0;
        foreach ($reads as $statement) {
            self::assertStringNotContainsString('OFFSET', strtoupper($statement['sql']));
            if (str_contains($statement['sql'], 'task_tracking_task')) {
                ++$taskPlans;
                $this->explain($statement, 'owner-filtered tasks N=100', 101);
            }
        }
        self::assertSame(1, $taskPlans);
        $this->sql->start();
        $last = $this->page($account, 100, $first->next);
        $continuation = $this->sql->reads();
        $this->sql->stop();
        self::assertSame([$ids[100]], $this->ids($last));
        self::assertNull($last->next);
        self::assertLessThanOrEqual(3, count($continuation));
        foreach ($continuation as $statement) {
            if (str_contains($statement['sql'], 'task_tracking_task')) {
                $this->explain($statement, 'owner continuation N=100', 101);
            }
        }

        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $this->sql->start();
        self::assertSame(0, $tester->run(['command' => 'app:task:list', '--owner' => $account->toRfc4122(), '--limit' => '100', '--no-interaction' => true]));
        $operatorReads = $this->sql->reads();
        self::assertSame([], $this->sql->writes());
        $this->sql->stop();
        self::assertCount(1, $operatorReads, 'The actual unfiltered operator console adapter uses exactly one bounded Task read.');
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['tasks']);
        self::assertCount(100, $payload['tasks']);
        self::assertIsString($payload['next']);
        $this->explain($operatorReads[0], 'operator owner keyset N=100', 101);
        $this->fails(fn () => $this->queries->ask(new \App\Module\TaskTracking\Application\ListTasks\ListTasksQuery()), \App\Platform\Authorization\AuthorizationDenied::class);
    }

    /** @param Statement $statement */
    private function explain(array $statement, string $label, int $maximum): void
    {
        $raw = $this->observer->fetchOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$statement['sql'], $statement['params']);
        self::assertIsString($raw);
        $plan = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($plan);
        $nodes = $this->nodes($plan);
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
        self::assertNotEmpty($indexes, 'The populated selective workload must exercise existing indexes without planner overrides.');
        fwrite(STDOUT, sprintf("Task10 EXPLAIN %s: rows=%s indexes=%s\n", $label, (string) $nodes[0]['Actual Rows'], implode(',', array_unique($indexes))));
    }

    /**
     * @param array<mixed> $value
     *
     * @return list<array<string, mixed>>
     */
    private function nodes(array $value): array
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
                $nodes = [...$nodes, ...$this->nodes($item)];
            }
        }

        return $nodes;
    }
}
