<?php

declare(strict_types=1);

// Only verify-setup's disposable DEVELOPMENT checkout calls this CLI helper.
use App\Kernel;
use App\Tests\Fixtures\Authenticating\Browser;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 2).'/vendor/autoload.php';
umask(0077);

try {
    if ('dev' !== getenv('APP_ENV') || 2 !== count($argv) || !in_array($argv[1], ['create', 'read'], true)) {
        throw new RuntimeException('Invalid disposable consumer authorization operation.');
    }

    // Bound private marker reads; never publish or copy authentication credentials.
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
    $readId = static function (string $file) use ($readJson): string {
        $id = $readJson($file)['id'] ?? null;
        if (!is_string($id) || !Uuid::isValid($id) || Uuid::fromString($id)->toRfc4122() !== $id) {
            throw new RuntimeException('Invalid consumer UUID.');
        }

        return $id;
    };
    $console = static function (array $arguments, ?array $input = null): array {
        $process = new Process(['php', 'bin/console', ...$arguments, '--no-interaction', '--no-ansi'], '/app', timeout: 15);
        $process->setInput(null === $input ? '' : json_encode($input, JSON_THROW_ON_ERROR));
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
            throw new RuntimeException('Consumer authorization command failed.');
        }
        $data = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
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
        if ('app' !== $connection->fetchOne('SELECT current_database()') || 'app' !== $connection->fetchOne('SELECT current_user')) {
            throw new RuntimeException('Consumer authorization requires its disposable dev app database/role.');
        }

        $accountId = $readId('/app/var/consumer-authenticating.json');
        $taskId = $readId('/app/var/consumer-task.json');
        $file = '/app/var/consumer-authorizing.json';
        $resource = ['scope' => 'resource', 'resourceType' => 'task_tracking.task', 'resourceId' => $taskId];
        $changes = [
            ['operation' => 'add', 'kind' => 'role', 'key' => 'task_tracking.reader', 'scope' => 'global'],
            ['operation' => 'add', 'kind' => 'role', 'key' => 'task_tracking.editor', ...$resource],
            ['operation' => 'add', 'kind' => 'permission', 'key' => 'task_tracking.task.create', 'scope' => 'global'],
            ['operation' => 'add', 'kind' => 'permission', 'key' => 'task_tracking.task.complete', ...$resource],
        ];
        $list = ['app:authorization:assignments', $accountId, '--limit=4'];
        if ('create' === $argv[1]) {
            if (file_exists($file) || is_link($file) || ['assignments' => [], 'next' => null] !== $console($list)) {
                throw new RuntimeException('Consumer authorization marker or assignments already exist.');
            }
            if (['requested' => 4, 'added' => 4, 'removed' => 0, 'unchanged' => 0] !== $console(['app:authorization:change-batch', $accountId], $changes)) {
                throw new RuntimeException('Consumer authorization assignments were not created.');
            }
        }

        $page = $console($list);
        if (['assignments', 'next'] !== array_keys($page) || null !== $page['next']
            || !is_array($page['assignments']) || !array_is_list($page['assignments']) || 4 !== count($page['assignments'])) {
            throw new RuntimeException('Consumer authorization page changed.');
        }
        $ids = [];
        foreach ($changes as $source => $change) {
            $row = $page['assignments'][$source];
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            if (!is_string($id) || !Uuid::isValid($id) || Uuid::fromString($id)->toRfc4122() !== $id
                || $row !== [
                    'id' => $id,
                    'source' => $source,
                    'kind' => $change['kind'],
                    'key' => $change['key'],
                    'scope' => $change['scope'],
                    'resourceType' => $change['resourceType'] ?? null,
                    'resourceId' => $change['resourceId'] ?? null,
                ]) {
                throw new RuntimeException('Consumer authorization assignment changed.');
            }
            $ids[] = $id;
        }
        if (4 !== count(array_unique($ids))) {
            throw new RuntimeException('Consumer assignment UUIDs are not distinct.');
        }

        if ('create' === $argv[1]) {
            // Exclusive creation plus umask keeps the safe page owner-private.
            $json = json_encode($page, JSON_THROW_ON_ERROR);
            $stream = @fopen($file, 'x');
            if (false === $stream) {
                throw new RuntimeException('Consumer authorization marker creation failed.');
            }
            try {
                if (strlen($json) !== fwrite($stream, $json)) {
                    throw new RuntimeException('Consumer authorization marker write failed.');
                }
            } finally {
                fclose($stream);
            }
            // Reapplying the same grants must reuse all four original identities.
            if (['requested' => 4, 'added' => 0, 'removed' => 0, 'unchanged' => 4] !== $console(['app:authorization:change-batch', $accountId], $changes)
                || $page !== $console($list)) {
                throw new RuntimeException('Consumer authorization grant identities were not reused.');
            }
        }
        if (is_link($file) || 0600 !== (fileperms($file) & 0777) || $page !== $readJson($file)) {
            throw new RuntimeException('Consumer authorization marker permissions or saved rows changed.');
        }

        $wrongResource = [...$resource, 'resourceId' => Uuid::v4()->toRfc4122()];
        if ($wrongResource['resourceId'] === $taskId) {
            throw new RuntimeException('Consumer wrong-resource UUID collided.');
        }
        $checks = [
            ['accountId' => $accountId, 'permission' => 'task_tracking.task.create', 'scope' => 'global'],
            ['accountId' => $accountId, 'permission' => 'task_tracking.task.view', 'scope' => 'global'],
            ['accountId' => $accountId, 'permission' => 'task_tracking.task.view', ...$resource],
            ['accountId' => $accountId, 'permission' => 'task_tracking.task.complete', ...$resource],
            // Global reader allows other tasks' view, but scoped completion does not.
            ['accountId' => $accountId, 'permission' => 'task_tracking.task.view', ...$wrongResource],
            ['accountId' => $accountId, 'permission' => 'task_tracking.task.complete', ...$wrongResource],
            ['accountId' => $accountId, 'permission' => 'task_tracking.task.complete', 'scope' => 'global'],
            ['accountId' => $accountId, 'permission' => 'task_tracking.task.unknown', ...$resource],
        ];
        $expected = ['decisions' => array_map(static fn (bool $allowed): array => ['allowed' => $allowed], [true, true, true, true, true, false, false, false])];
        if ($expected !== $console(['app:authorization:check-batch'], $checks)) {
            throw new RuntimeException('Consumer authorization decisions changed.');
        }
        $anonymous = Browser::create(base: 'http://localhost:8080');
        $anonymous->request('GET', '/_demo/tasks/'.$taskId);
        if (401 !== $anonymous->getResponse()->getStatusCode()
            || ['error' => 'Access denied.'] !== json_decode((string) $anonymous->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)) {
            throw new RuntimeException('Consumer anonymous task access was not denied.');
        }
        $session = $readJson('/app/var/consumer-authenticating.json')['session'] ?? null;
        if (!is_string($session) || '' === $session || strlen($session) > 256) {
            throw new RuntimeException('Consumer native session unavailable.');
        }
        $browser = Browser::create($session, 'http://localhost:8080');
        $browser->request('GET', '/_demo/tasks/'.$taskId);
        if (200 !== $browser->getResponse()->getStatusCode()
            || ['id' => $taskId, 'title' => 'consumer-setup-marker'] !== json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)
            || !str_contains(Browser::header($browser, 'Cache-Control'), 'no-store')) {
            throw new RuntimeException('Consumer native session did not receive its live task permission.');
        }
    } finally {
        $kernel->shutdown();
    }
    fwrite(STDOUT, "Verified all four consumer authorization assignment UUIDs/rows, global/scoped decisions and anonymous-denied/session-authorized Task HTTP across setup/test/recreation.\n");
} catch (Throwable) {
    // Never present process exceptions, raw console output or marker contents.
    fwrite(STDERR, "Disposable consumer authorization verification failed.\n");
    exit(1);
}
