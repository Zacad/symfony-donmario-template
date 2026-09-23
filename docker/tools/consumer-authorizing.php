<?php

declare(strict_types=1);

// Only verify-setup's disposable DEVELOPMENT checkout calls this CLI helper.
use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 2).'/vendor/autoload.php';
umask(0077);

try {
    if ('dev' !== getenv('APP_ENV') || !isset($argv) || 2 !== count($argv) || !in_array($argv[1], ['create', 'read'], true)) {
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
    $uuid = static function (mixed $id): string {
        if (!is_string($id) || 36 !== strlen($id) || !Uuid::isValid($id) || Uuid::fromString($id)->toRfc4122() !== $id) {
            throw new RuntimeException('Invalid consumer UUID.');
        }

        return $id;
    };
    $readId = static fn (string $file): string => $uuid($readJson($file)['id'] ?? null);
    /**
     * @param list<string>      $arguments
     * @param array<mixed>|null $input
     *
     * @return array<mixed>
     */
    $console = static function (array $arguments, ?array $input = null): array {
        /** @var list<string> $command */
        $command = ['php', 'bin/console', ...$arguments, '--no-interaction', '--no-ansi'];
        $process = new Process($command, '/app', timeout: 15);
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
        if (!$connection instanceof Connection) {
            throw new RuntimeException('Consumer Doctrine connection unavailable.');
        }
        if ('app' !== $connection->fetchOne('SELECT current_database()') || 'app' !== $connection->fetchOne('SELECT current_user')) {
            throw new RuntimeException('Consumer authorization requires its disposable dev app database/role.');
        }

        $accountId = $readId('/app/var/consumer-authenticating.json');
        $file = '/app/var/consumer-authorizing.json';
        $mode = $argv[1];
        $roles = [
            'task_tracking.user' => ['Task user', ['task_tracking.task.complete', 'task_tracking.task.create', 'task_tracking.task.view']],
            'authorizing.administrator' => ['Authorization administrator', ['authorizing.catalogue.manage', 'authorizing.manage']],
            'application.administrator' => ['Application administrator', ['authorizing.catalogue.manage', 'authorizing.manage', 'task_tracking.task.complete', 'task_tracking.task.create', 'task_tracking.task.view']],
        ];
        $accountChanges = [
            ['operation' => 'add', 'kind' => 'role', 'key' => 'task_tracking.user'],
            ['operation' => 'add', 'kind' => 'permission', 'key' => 'authorizing.manage'],
        ];
        $accountList = ['app:authorization:assignments', $accountId, '--limit=3'];

        if ('create' === $mode) {
            if (file_exists($file) || is_link($file) || ['assignments' => [], 'next' => null] !== $console($accountList)) {
                throw new RuntimeException('Consumer authorization marker or assignments already exist.');
            }
            foreach ($roles as $key => [$label, $permissions]) {
                $role = $console(['app:authorization:role:define', $key, $label, ...$permissions, '--if-absent']);
                if (['key', 'label', 'revision', 'retiredAt', 'permissions'] !== array_keys($role)
                    || ['key' => $key, 'label' => $label, 'revision' => 1, 'retiredAt' => null, 'permissions' => $permissions] !== $role) {
                    throw new RuntimeException('Consumer authorization role output or default changed.');
                }
            }
            $administratorId = Uuid::v7()->toRfc4122();
            if (['requested' => 2, 'added' => 2, 'removed' => 0, 'unchanged' => 0] !== $console(['app:authorization:change-batch', $accountId], $accountChanges)
                || ['requested' => 1, 'added' => 1, 'removed' => 0, 'unchanged' => 0] !== $console(['app:authorization:role:assign', $administratorId, 'application.administrator'])) {
                throw new RuntimeException('Consumer authorization assignments were not created.');
            }
        } else {
            $saved = $readJson($file);
            $administratorId = $uuid($saved['administratorSubjectId'] ?? null);
        }

        $accountPage = $console($accountList);
        $administratorPage = $console(['app:authorization:assignments', $administratorId, '--limit=2']);
        $expectedAccountPage = [
            'assignments' => [
                ['kind' => 'role', 'key' => 'task_tracking.user'],
                ['kind' => 'permission', 'key' => 'authorizing.manage'],
            ],
            'next' => null,
        ];
        $expectedAdministratorPage = [
            'assignments' => [['kind' => 'role', 'key' => 'application.administrator']],
            'next' => null,
        ];
        if ($expectedAccountPage !== $accountPage || $expectedAdministratorPage !== $administratorPage) {
            throw new RuntimeException('Consumer authorization assignment output changed.');
        }
        $storedAssignments = [
            'accountRoles' => $connection->fetchFirstColumn('SELECT role_key FROM public.authorizing_role_assignment WHERE subject_id = CAST(? AS uuid) ORDER BY role_key', [$accountId]),
            'accountPermissions' => $connection->fetchFirstColumn('SELECT permission_key FROM public.authorizing_permission_grant WHERE subject_id = CAST(? AS uuid) ORDER BY permission_key', [$accountId]),
            'administratorRoles' => $connection->fetchFirstColumn('SELECT role_key FROM public.authorizing_role_assignment WHERE subject_id = CAST(? AS uuid) ORDER BY role_key', [$administratorId]),
            'administratorPermissions' => $connection->fetchFirstColumn('SELECT permission_key FROM public.authorizing_permission_grant WHERE subject_id = CAST(? AS uuid) ORDER BY permission_key', [$administratorId]),
        ];
        if ([
            'accountRoles' => ['task_tracking.user'],
            'accountPermissions' => ['authorizing.manage'],
            'administratorRoles' => ['application.administrator'],
            'administratorPermissions' => [],
        ] !== $storedAssignments) {
            throw new RuntimeException('Consumer authorization assignment persistence changed.');
        }

        $checks = [
            ['subjectId' => $accountId, 'permission' => 'task_tracking.task.create'],
            ['subjectId' => $accountId, 'permission' => 'task_tracking.task.view'],
            ['subjectId' => $accountId, 'permission' => 'task_tracking.task.complete'],
            ['subjectId' => $accountId, 'permission' => 'authorizing.manage'],
            ['subjectId' => $accountId, 'permission' => 'authorizing.catalogue.manage'],
            ['subjectId' => $administratorId, 'permission' => 'task_tracking.task.create'],
            ['subjectId' => $administratorId, 'permission' => 'task_tracking.task.view'],
            ['subjectId' => $administratorId, 'permission' => 'task_tracking.task.complete'],
            ['subjectId' => $administratorId, 'permission' => 'authorizing.manage'],
            ['subjectId' => $administratorId, 'permission' => 'authorizing.catalogue.manage'],
            ['subjectId' => $administratorId, 'permission' => 'retired.permission'],
        ];
        $decisions = $console(['app:authorization:check-batch'], $checks);
        $expectedDecisions = ['decisions' => array_map(static fn (bool $allowed): array => ['allowed' => $allowed], [true, true, true, true, false, true, true, true, true, true, false])];
        if ($expectedDecisions !== $decisions) {
            throw new RuntimeException('Consumer authorization decisions changed.');
        }

        $marker = [
            'administratorSubjectId' => $administratorId,
            'subjectAssignments' => $accountPage,
            'administratorAssignments' => $administratorPage,
            'decisions' => $decisions,
        ];
        if ('create' === $mode) {
            if (['requested' => 2, 'added' => 0, 'removed' => 0, 'unchanged' => 2] !== $console(['app:authorization:change-batch', $accountId], $accountChanges)
                || ['requested' => 1, 'added' => 0, 'removed' => 0, 'unchanged' => 1] !== $console(['app:authorization:role:assign', $administratorId, 'application.administrator'])
                || $accountPage !== $console($accountList)
                || $administratorPage !== $console(['app:authorization:assignments', $administratorId, '--limit=2'])) {
                throw new RuntimeException('Consumer authorization assignment replay was not idempotent.');
            }
            $json = json_encode($marker, JSON_THROW_ON_ERROR);
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
        }
        if (is_link($file) || 0600 !== (fileperms($file) & 0777) || $marker !== $readJson($file)) {
            throw new RuntimeException('Consumer authorization marker permissions or saved state changed.');
        }
    } finally {
        $kernel->shutdown();
    }
    fwrite(STDOUT, "Verified exact role snapshots, operation/kind/key batches, kind/key assignment pages, clean role/direct-grant persistence and decisions across setup/test/recreation.\n");
} catch (Throwable) {
    // Never present process exceptions, raw console output or marker contents.
    fwrite(STDERR, "Disposable consumer authorization verification failed.\n");
    exit(1);
}
