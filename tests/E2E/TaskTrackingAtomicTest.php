<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\TaskTracking\Domain\Task;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\Uid\Uuid;

/** Executed once per actual event transport by docker/tools/test.sh. */
final class TaskTrackingAtomicTest extends TaskTrackingTestCase
{
    public function testFinalFlushFailureRollsBackTaskAndNativeEventThenRecovers(): void
    {
        $owner = $this->account();
        foreach ([self::CREATE, self::VIEW, self::COMPLETE] as $permission) {
            $this->grant($owner, $permission);
        }
        $eventsBefore = $this->observer->fetchOne('SELECT count(*) FROM platform_messaging_message');
        $name = 'task10_fault_'.bin2hex(random_bytes(6));
        $title = $this->prefix.'-atomic-failure';
        $this->observer->executeStatement("CREATE FUNCTION public.$name() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.title = '$title' THEN RAISE EXCEPTION 'task10-private-canary'; END IF; RETURN NEW; END; $$");
        $id = null;
        try {
            $this->observer->executeStatement("CREATE TRIGGER $name BEFORE INSERT ON public.task_tracking_task FOR EACH ROW EXECUTE FUNCTION public.$name()");
            $this->fault->afterAdd = static function (Task $task) use (&$id): void { $id = $task->id(); };
            $this->sql->start();
            $this->fails(fn () => $this->create($owner, 'atomic-failure'), DriverException::class);
            self::assertInstanceOf(Uuid::class, $id);
            $writes = array_column($this->sql->writes(), 'sql');
            $this->sql->stop();
            self::assertFalse((bool) array_filter($writes, static fn (string $sql): bool => str_contains($sql, 'authorizing_role_assignment') || str_contains($sql, 'authorizing_permission_grant')), 'Task creation must not write authorization assignments.');
            if ('doctrine://default' === getenv('EVENT_TRANSPORT_DSN')) {
                self::assertTrue((bool) array_filter($writes, static fn (string $sql): bool => str_contains($sql, 'platform_messaging_message')), 'The native event enqueue actually executed inside the failing root.');
            }
            self::assertSame(0, $this->observer->fetchOne('SELECT count(*) FROM task_tracking_task WHERE id = ?', [$id->toRfc4122()]));
            self::assertSame(0, $this->observer->fetchOne('SELECT count(*) FROM platform_messaging_message WHERE body LIKE ?', ['%'.$id.'%']));
            self::assertFalse($this->connection->isTransactionActive());
            $this->fixedFailure($this->cli(['app:task:create', $title, '--owner='.$owner, '-vvv']), 1, 'Task operation failed.');
            self::assertSame(0, $this->observer->fetchOne('SELECT count(*) FROM task_tracking_task WHERE title = ?', [$title]));
            self::assertSame($eventsBefore, $this->observer->fetchOne('SELECT count(*) FROM platform_messaging_message'));
        } finally {
            $this->observer->executeStatement("DROP TRIGGER IF EXISTS $name ON public.task_tracking_task");
            $this->observer->executeStatement("DROP FUNCTION public.$name()");
            $this->fault->afterAdd = null;
        }
        $id = $this->create($owner, 'atomic-recovery');
        self::assertSame([$id->toRfc4122()], $this->ids($this->page($owner)));
        self::assertTrue($this->complete($owner, $id)?->changed);
        self::assertSame('doctrine://default' === getenv('EVENT_TRANSPORT_DSN') ? 1 : 0, $this->observer->fetchOne('SELECT count(*) FROM platform_messaging_message WHERE body LIKE ?', ['%'.$id.'%']));
    }
}
