<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/** Only the runner's stopped isolated PostgreSQL phase may execute this class. */
final class TaskTrackingDatabaseDownTest extends TestCase
{
    public function testOperatorCommandsFailBoundedlyWithoutSuccessOrDatabaseDiagnostics(): void
    {
        $id = Uuid::v7()->toRfc4122();
        foreach ([['app:task:create', 'task10-outage'], ['app:task:create', 'task10-outage-owned', '--owner='.$id], ['app:task:show', $id], ['app:task:list'], ['app:task:list', '--owner='.$id], ['app:task:complete', $id]] as $arguments) {
            $process = new Process([PHP_BINARY, 'bin/console', '--env=test', '--no-debug', '--no-interaction', '--no-ansi', ...$arguments], dirname(__DIR__, 2), timeout: 15);
            $start = microtime(true);
            $process->run();
            self::assertLessThan(15, microtime(true) - $start);
            self::assertSame(1, $process->getExitCode());
            self::assertSame("Task operation failed.\n", $process->getOutput());
            foreach (['SQLSTATE', 'password=', 'postgresql://', 'PDOException', 'Stack trace'] as $private) {
                self::assertStringNotContainsString($private, $process->getOutput().$process->getErrorOutput());
            }
        }
    }
}
