<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

final class AuthorizingDatabaseDownTest extends TestCase
{
    public function testNativeGlobalCliOutageFailsWithoutDecisionsOrInternalDiagnostics(): void
    {
        $subject = Uuid::v7()->toRfc4122();
        foreach ([
            [['app:authorization:check-batch'], json_encode([['subjectId' => $subject, 'permission' => 'task_tracking.task.view']], JSON_THROW_ON_ERROR)],
            [['app:authorization:role:assign', $subject, 'task_tracking.user'], null],
        ] as [$arguments, $input]) {
            $process = new Process(['php', 'bin/console', ...$arguments, '--no-interaction', '--no-ansi', '-vvv'], dirname(__DIR__, 2), timeout: 20);
            $process->setInput($input);
            $started = microtime(true);
            self::assertSame(1, $process->run());
            self::assertLessThan(15, microtime(true) - $started);
            self::assertSame("Authorization operation failed.\n", $process->getOutput());
            self::assertSame('', $process->getErrorOutput());
        }
    }
}
