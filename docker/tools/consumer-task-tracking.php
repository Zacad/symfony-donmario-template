<?php

declare(strict_types=1);

// Only verify-setup's disposable DEVELOPMENT checkout calls this CLI helper.
use App\Kernel;
use App\Tests\Fixtures\Authenticating\Browser;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 2).'/vendor/autoload.php';
umask(0077);

try {
    if ('dev' !== getenv('APP_ENV') || !isset($argv) || 2 !== count($argv) || !in_array($argv[1], ['create', 'read'], true)) {
        throw new RuntimeException('Invalid disposable consumer TaskTracking operation.');
    }
    $readJson = static function (string $file): array {
        $json = @file_get_contents($file, false, null, 0, 65537);
        if (false === $json || strlen($json) > 65536) {
            throw new RuntimeException('Consumer marker unavailable or oversized.');
        }
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid consumer marker.');
        }

        return $data;
    };
    $uuid = static function (mixed $id): string {
        if (!is_string($id) || 36 !== strlen($id) || !Uuid::isValid($id) || Uuid::fromString($id)->toRfc4122() !== $id) {
            throw new RuntimeException('Invalid consumer UUID.');
        }

        return $id;
    };
    /** @param list<string> $arguments */
    $console = static function (array $arguments): string {
        /** @var list<string> $command */
        $command = ['php', 'bin/console', ...$arguments, '--no-interaction', '--no-ansi'];
        $process = new Process($command, '/app', timeout: 30);
        $process->setInput('');
        $stdout = '';
        $bytes = 0;
        try {
            $status = $process->run(static function (string $type, string $buffer) use ($process, &$stdout, &$bytes): void {
                $bytes += strlen($buffer);
                if ($bytes > 65536) {
                    throw new RuntimeException('Consumer console output exceeded its bound.');
                }
                if (Process::OUT === $type) {
                    $stdout .= $buffer;
                }
                $process->clearOutput();
                $process->clearErrorOutput();
            });
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        if (0 !== $status) {
            throw new RuntimeException('Consumer TaskTracking command failed.');
        }

        return trim($stdout);
    };
    /** @param list<string> $arguments
     * @return array<mixed>
     */
    $jsonConsole = static function (array $arguments) use ($console): array {
        $data = json_decode($console($arguments), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid consumer console response.');
        }

        return $data;
    };

    $kernel = new Kernel('dev', true);
    try {
        $kernel->boot();
        $registry = $kernel->getContainer()->get('doctrine');
        if (!$registry instanceof ManagerRegistry) {
            throw new RuntimeException('Consumer Doctrine registry unavailable.');
        }
        $connection = $registry->getConnection();
        if (!$connection instanceof Connection) {
            throw new RuntimeException('Consumer Doctrine connection unavailable.');
        }
        if ('app' !== $connection->fetchOne('SELECT current_database()') || 'app' !== $connection->fetchOne('SELECT current_user')) {
            throw new RuntimeException('Consumer TaskTracking requires its disposable dev app database/role.');
        }

        $file = '/app/var/consumer-task-tracking.json';
        $authentication = $readJson('/app/var/consumer-authenticating.json');
        $ownerId = $uuid($authentication['id'] ?? null);
        $session = $authentication['session'] ?? null;
        if (!is_string($session) || '' === $session || strlen($session) > 256) {
            throw new RuntimeException('Consumer native session unavailable.');
        }
        $unownedTaskId = $uuid($readJson('/app/var/consumer-task.json')['id'] ?? null);
        $authorization = $readJson('/app/var/consumer-authorizing.json');
        $expectedAssignments = $authorization['subjectAssignments'] ?? null;
        $expectedAssignmentRows = is_array($expectedAssignments) ? ($expectedAssignments['assignments'] ?? null) : null;
        if (!is_array($expectedAssignments) || !is_array($expectedAssignmentRows) || !array_is_list($expectedAssignmentRows) || 2 !== count($expectedAssignmentRows)
            || $expectedAssignments !== $jsonConsole(['app:authorization:assignments', $ownerId, '--limit=3'])) {
            throw new RuntimeException('Consumer Task owner lacks its preassigned global role/direct grant.');
        }

        $browser = Browser::create($session, 'http://localhost:8080');
        if ('create' === $argv[1]) {
            if (file_exists($file) || is_link($file)) {
                throw new RuntimeException('Consumer TaskTracking marker already exists.');
            }
            $browser->jsonRequest('POST', '/_demo/tasks', ['title' => 'consumer-owned-task-marker']);
            $created = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $taskId = $uuid(is_array($created) ? ($created['id'] ?? null) : null);
            if (201 !== $browser->getResponse()->getStatusCode() || $taskId === $unownedTaskId) {
                throw new RuntimeException('Consumer owner HTTP task creation failed.');
            }
            if ($expectedAssignments !== $jsonConsole(['app:authorization:assignments', $ownerId, '--limit=3'])) {
                throw new RuntimeException('Consumer task creation unexpectedly changed authorization assignments.');
            }
            $task = ['id' => $taskId, 'title' => 'consumer-owned-task-marker', 'ownerAccountId' => $ownerId, 'completedAt' => null];
            if ($task !== $jsonConsole(['app:task:show', $taskId])
                || ['tasks' => [$task], 'next' => null] !== $jsonConsole(['app:task:list', '--owner='.$ownerId, '--limit=2'])) {
                throw new RuntimeException('Consumer owned task was not available through owner-named CLI contracts.');
            }
            $completion = $jsonConsole(['app:task:complete', $taskId]);
            $completedAt = $completion['completedAt'] ?? null;
            if (!is_string($completedAt) || ['id' => $taskId, 'changed' => true, 'completedAt' => $completedAt] !== $completion) {
                throw new RuntimeException('Consumer first completion did not change the task.');
            }
            $saved = ['ownerAccountId' => $ownerId, 'taskId' => $taskId, 'unownedTaskId' => $unownedTaskId, 'completedAt' => $completedAt];
            // Cross a wire timestamp second so a broken repeat cannot look stable by chance.
            usleep(1100000);
        } else {
            $saved = $readJson($file);
        }

        $taskId = $uuid($saved['taskId'] ?? null);
        if ($ownerId !== $uuid($saved['ownerAccountId'] ?? null) || $unownedTaskId !== $uuid($saved['unownedTaskId'] ?? null)) {
            throw new RuntimeException('Consumer persisted owner/task identity changed.');
        }
        $completedAt = $saved['completedAt'] ?? null;
        $instant = is_string($completedAt) && 20 === strlen($completedAt)
            ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $completedAt, new DateTimeZone('UTC')) : false;
        if (!$instant instanceof DateTimeImmutable || $completedAt !== $instant->format('Y-m-d\TH:i:s\Z')) {
            throw new RuntimeException('Consumer completion timestamp changed.');
        }
        if (['id' => $taskId, 'changed' => false, 'completedAt' => $completedAt] !== $jsonConsole(['app:task:complete', $taskId])) {
            throw new RuntimeException('Consumer repeat completion was not stable.');
        }
        $task = ['id' => $taskId, 'title' => 'consumer-owned-task-marker', 'ownerAccountId' => $ownerId, 'completedAt' => $completedAt];
        if ($task !== $jsonConsole(['app:task:show', $taskId])
            || ['tasks' => [$task], 'next' => null] !== $jsonConsole(['app:task:list', '--owner='.$ownerId, '--limit=2'])
            || $expectedAssignments !== $jsonConsole(['app:authorization:assignments', $ownerId, '--limit=3'])) {
            throw new RuntimeException('Consumer owner-bound CLI state or global assignments changed.');
        }

        $ownerBrowser = Browser::create($session, 'http://localhost:8080');
        $ownerBrowser->request('GET', '/_demo/tasks/'.$taskId);
        if (200 !== $ownerBrowser->getResponse()->getStatusCode()
            || $task !== json_decode((string) $ownerBrowser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)
            || !str_contains(Browser::header($ownerBrowser, 'Cache-Control'), 'no-store')) {
            throw new RuntimeException('Consumer owner could not read its task over HTTP.');
        }
        $deniedBrowser = Browser::create($session, 'http://localhost:8080');
        $deniedBrowser->request('GET', '/_demo/tasks/'.$unownedTaskId);
        if (403 !== $deniedBrowser->getResponse()->getStatusCode()
            || ['error' => 'Access denied.'] !== json_decode((string) $deniedBrowser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)) {
            throw new RuntimeException('Consumer global permission bypassed Task ownership.');
        }
        $anonymous = Browser::create(base: 'http://localhost:8080');
        $anonymous->request('GET', '/_demo/tasks/'.$taskId);
        if (401 !== $anonymous->getResponse()->getStatusCode()) {
            throw new RuntimeException('Consumer anonymous task access was not denied.');
        }

        if ('create' === $argv[1]) {
            $json = json_encode($saved, JSON_THROW_ON_ERROR);
            $stream = @fopen($file, 'x');
            if (false === $stream) {
                throw new RuntimeException('Consumer TaskTracking marker creation failed.');
            }
            try {
                if (strlen($json) !== @fwrite($stream, $json)) {
                    throw new RuntimeException('Consumer TaskTracking marker write failed.');
                }
            } finally {
                fclose($stream);
            }
        }
        if (is_link($file) || 0600 !== (@fileperms($file) & 0777) || $saved !== $readJson($file)) {
            throw new RuntimeException('Consumer TaskTracking marker permissions or state changed.');
        }
    } finally {
        $kernel->shutdown();
    }
    fwrite(STDOUT, "Verified preassigned task_tracking.user access, unchanged kind/key assignment pages after Task creation, owner HTTP create/get, --owner CLI list/get/complete, ownership denial and persistence.\n");
} catch (Throwable) {
    // Never present process exceptions, raw console output or marker contents.
    fwrite(STDERR, "Disposable consumer TaskTracking verification failed.\n");
    exit(1);
}
