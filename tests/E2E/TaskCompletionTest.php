<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\TaskTracking\Application\CompleteTask\CompleteTaskCommand;
use App\Module\TaskTracking\Application\CompleteTask\CompleteTaskResult;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Domain\Task;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

final class TaskCompletionTest extends TaskTrackingTestCase
{
    /** @return iterable<string, array{bool}> */
    public static function outcomes(): iterable
    {
        yield 'commit' => [false];
        yield 'rollback' => [true];
    }

    #[DataProvider('outcomes')]
    public function testNestedCreateCompleteRepeatHasNoPrecommitVisibilityAndRecovers(bool $rollback): void
    {
        $account = $this->account();
        $this->grant($account, self::CREATE);
        $this->grant($account, self::COMPLETE);
        $id = null;
        $first = null;
        $this->fault->afterAdd = function (Task $task) use ($rollback, &$id, &$first): void {
            $id = $task->id();
            $first = $this->commands->dispatch(new CompleteTaskCommand($id->toRfc4122()));
            $again = $this->commands->dispatch(new CompleteTaskCommand($id->toRfc4122()));
            self::assertInstanceOf(CompleteTaskResult::class, $first);
            self::assertInstanceOf(CompleteTaskResult::class, $again);
            self::assertTrue($first->changed);
            self::assertFalse($again->changed);
            self::assertEquals($first->completedAt, $again->completedAt);
            foreach ([$this->connection, $this->observer] as $connection) {
                self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM task_tracking_task WHERE id = ?', [$id->toRfc4122()]));
            }
            if ($rollback) {
                throw new \RuntimeException('Synthetic task outer rollback.');
            }
        };
        if ($rollback) {
            $this->fails(fn () => $this->create($account), \RuntimeException::class);
        } else {
            $this->create($account);
        }
        self::assertInstanceOf(Uuid::class, $id);
        self::assertInstanceOf(CompleteTaskResult::class, $first);
        self::assertSame($rollback ? false : $first->completedAt->format('Y-m-d H:i:s'), $this->observer->fetchOne('SELECT completed_at FROM task_tracking_task WHERE id = ?', [$id->toRfc4122()]));
        self::assertFalse($this->connection->isTransactionActive());
        $this->fault->afterAdd = null;
        $recovered = $this->create($account, 'recovery');
        self::assertTrue($this->complete($account, $recovered)?->changed);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function staleStates(): iterable
    {
        yield 'clean stale refresh' => ['clean', false];
        yield 'dirty stale conflict' => ['dirty', true];
        yield 'deleted managed conflict' => ['deleted', true];
        yield 'scheduled removal conflict' => ['removed', true];
        yield 'pending local completion preserved' => ['local', false];
    }

    #[DataProvider('staleStates')]
    public function testCompletionRespectsManagedStateAndNeverOverwritesDirtyOrRemovedObjects(string $mode, bool $conflict): void
    {
        $account = $this->account();
        $this->grant($account, self::CREATE);
        $this->grant($account, self::COMPLETE);
        $task = $this->task(ownerAccountId: $account);
        $result = null;
        $localTime = new \DateTimeImmutable('2026-09-16T10:11:12Z');
        $this->fault->afterAdd = function () use ($mode, $task, $localTime, &$result): void {
            $managed = $this->manager->find(Task::class, $task);
            self::assertInstanceOf(Task::class, $managed);
            if (in_array($mode, ['dirty', 'local'], true)) {
                self::assertTrue($managed->complete($localTime));
            }
            if (in_array($mode, ['clean', 'dirty'], true)) {
                $this->observer->executeStatement("UPDATE task_tracking_task SET completed_at = '2026-09-16 10:20:30' WHERE id = ?", [$task->toRfc4122()]);
            } elseif ('deleted' === $mode) {
                $this->observer->executeStatement('DELETE FROM task_tracking_task WHERE id = ?', [$task->toRfc4122()]);
            } elseif ('removed' === $mode) {
                $this->manager->remove($managed);
            }
            $result = $this->commands->dispatch(new CompleteTaskCommand($task->toRfc4122()));
            self::assertInstanceOf(CompleteTaskResult::class, $result);
            self::assertFalse($result->changed);
            self::assertSame('local' === $mode ? '2026-09-16 10:11:12' : '2026-09-16 10:20:30', $result->completedAt->format('Y-m-d H:i:s'));
        };
        if ($conflict) {
            $failure = $this->fails(fn () => $this->create($account, 'stale-root'), \RuntimeException::class);
            self::assertSame('Task completion conflict.', $failure->getMessage());
            self::assertNull($result);
            self::assertSame(0, $this->observer->fetchOne('SELECT count(*) FROM task_tracking_task WHERE title = ?', [$this->prefix.'-stale-root']));
        } else {
            $this->create($account, 'stale-root');
        }
        $expected = match ($mode) {
            'deleted' => false,
            'removed' => null,
            'local' => '2026-09-16 10:11:12',
            default => '2026-09-16 10:20:30',
        };
        self::assertSame($expected, $this->observer->fetchOne('SELECT completed_at FROM task_tracking_task WHERE id = ?', [$task->toRfc4122()]));
        $this->fault->afterAdd = null;
        $this->fails(fn () => $this->complete($account, Uuid::v7()));
        $recovery = $this->create($account, 'stale-recovery');
        self::assertTrue($this->complete($account, $recovery)?->changed);
    }

    public function testCaughtNestedOwnershipDenialPoisonsCompletionRoot(): void
    {
        $account = $this->account();
        $this->grant($account, self::CREATE);
        $this->grant($account, self::COMPLETE);
        $task = $this->create($account);
        $caught = false;
        $this->fault->afterFindForCompletion = function () use (&$caught): void {
            $this->fails(fn () => $this->commands->dispatch(new CreateTaskCommand($this->prefix.'-forged-unowned')));
            $caught = true;
        };
        $this->fails(fn () => $this->complete($account, $task));
        self::assertTrue($caught);
        self::assertNull($this->observer->fetchOne('SELECT completed_at FROM task_tracking_task WHERE id = ?', [$task->toRfc4122()]));
        $this->fault->afterFindForCompletion = null;
        self::assertTrue($this->complete($account, $task)?->changed);
    }

    #[DataProvider('outcomes')]
    public function testRealConcurrentCompletionWaitsForRootCommitOrRollback(bool $rollback): void
    {
        $account = $this->account();
        $this->grant($account, self::CREATE);
        $this->grant($account, self::COMPLETE);
        $task = $this->task(ownerAccountId: $account);
        $input = new InputStream();
        $holder = new Process([PHP_BINARY, 'tests/Fixtures/TaskTracking/held-completion.php', $account->toRfc4122(), $task->toRfc4122(), $this->prefix.'-held'], dirname(__DIR__, 2), timeout: 25);
        $holder->setInput($input);
        $name = $this->prefix.'-waiter';
        $waiter = new Process([PHP_BINARY, 'bin/console', '--env=test', '--no-debug', '--no-interaction', '--no-ansi', 'app:task:complete', $task->toRfc4122()], dirname(__DIR__, 2), ['PGAPPNAME' => $name], timeout: 25);
        try {
            $holder->start();
            $deadline = microtime(true) + 15;
            while (!str_contains($holder->getOutput(), "LOCKED\n")) {
                self::assertTrue($holder->isRunning(), 'Completion holder exited before the transaction barrier.');
                self::assertLessThan($deadline, microtime(true), 'Completion holder failed to reach its barrier.');
                usleep(10000);
            }
            self::assertNull($this->observer->fetchOne('SELECT completed_at FROM task_tracking_task WHERE id = ?', [$task->toRfc4122()]), 'The first flushed completion remains invisible before root commit.');
            $waiter->start();
            $deadline = microtime(true) + 10;
            while (true) {
                $blocked = $this->observer->fetchOne("SELECT count(*) FROM pg_catalog.pg_stat_activity WHERE application_name = ? AND datname = current_database() AND wait_event_type = 'Lock'", [$name]);
                if (1 === $blocked) {
                    break;
                }
                self::assertTrue($waiter->isRunning(), 'Second completion must reach the real PostgreSQL row lock.');
                self::assertLessThan($deadline, microtime(true), 'Second completion failed to reach its row-lock barrier.');
                usleep(10000);
            }
            self::assertTrue($waiter->isRunning());
            $input->write($rollback ? "rollback\n" : "commit\n");
            $input->close();
            $holder->wait();
            self::assertSame(0, $holder->getExitCode(), 'Controlled root must finish without leaking diagnostics.');
            $waiter->wait();
            $result = $this->json($waiter);
            self::assertSame($rollback, $result['changed'], 'Committed first writer wins; a rolled-back writer leaves completion available.');
            self::assertIsString($result['completedAt']);
            self::assertSame(1, preg_match('/COMPLETED=([^\n]+)\n/', $holder->getOutput(), $firstCompletion));
            $winningTimestamp = $firstCompletion[1] ?? null;
            self::assertIsString($winningTimestamp);
            if (!$rollback) {
                self::assertSame($winningTimestamp, $result['completedAt'], 'The waiting command returns the winning transaction timestamp unchanged.');
            }
            self::assertSame($result['completedAt'], $this->json($this->cli(['app:task:show', $task->toRfc4122()]))['completedAt']);
            self::assertSame($rollback ? 0 : 1, $this->observer->fetchOne('SELECT count(*) FROM task_tracking_task WHERE title = ?', [$this->prefix.'-held']));
        } finally {
            $input->close();
            foreach ([$holder, $waiter] as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
        self::assertFalse($this->complete($account, $task)?->changed);
    }
}
