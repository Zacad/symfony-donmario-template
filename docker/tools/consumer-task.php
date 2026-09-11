<?php

declare(strict_types=1);

// Used only by the clean, disposable DEVELOPMENT checkout verifier.
use App\Module\TaskTracking\Domain\Task;
use App\Module\TaskTracking\Domain\TaskRepository;
use App\Tests\Fixtures\Repository\RepositoryKernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ('dev' !== getenv('APP_ENV') || !in_array($argv[1] ?? '', ['create', 'read'], true)) {
    throw new RuntimeException('Consumer persistence helper requires a disposable dev checkout and create/read mode.');
}
$kernel = new RepositoryKernel('dev', true);
$kernel->boot();
try {
    $registry = $kernel->getContainer()->get('doctrine');
    assert($registry instanceof ManagerRegistry);
    $manager = $registry->getManager();
    assert($manager instanceof EntityManagerInterface);
    $repository = $kernel->getContainer()->get('test.task_repository');
    assert($repository instanceof TaskRepository);
    $connection = $manager->getConnection();
    if ('app' !== $connection->fetchOne('SELECT current_database()') || 'app' !== $connection->fetchOne('SELECT current_user')) {
        throw new RuntimeException('Consumer persistence helper requires the disposable dev app database/role.');
    }
    $filesystem = new Filesystem();
    $file = $kernel->getProjectDir().'/var/consumer-task.json';
    $history = $connection->fetchAllAssociative('SELECT version, executed_at, execution_time FROM doctrine_migration_versions ORDER BY version');
    if ('create' === $argv[1]) {
        if (is_file($file) || [] === $history) {
            throw new RuntimeException('Consumer marker already exists or migrations were not applied.');
        }
        $task = new Task('consumer-setup-marker');
        $repository->add($task);
        $manager->flush();
        $filesystem->dumpFile($file, json_encode(['id' => $task->id()->toRfc4122(), 'history' => $history], JSON_THROW_ON_ERROR));
    }
    $saved = json_decode($filesystem->readFile($file), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($saved) || !is_string($saved['id'] ?? null) || $history !== ($saved['history'] ?? null)) {
        throw new RuntimeException('Consumer marker or migration history changed.');
    }
    $manager->clear();
    $task = $repository->find(Uuid::fromString($saved['id']));
    if (!$task instanceof Task || 'consumer-setup-marker' !== $task->title() || $saved['id'] !== $task->id()->toRfc4122()) {
        throw new RuntimeException('Consumer task was lost or changed.');
    }
    fwrite(STDOUT, "Consumer Task UUID/title and migration history verified.\n");
} finally {
    $kernel->shutdown();
}
