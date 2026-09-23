<?php

declare(strict_types=1);

use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use App\Module\TaskTracking\Application\CompleteTask\CompleteTaskCommand;
use App\Module\TaskTracking\Application\CompleteTask\CompleteTaskResult;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Platform\Messaging\CommandBus;
use App\Tests\Fixtures\Authorizing\AuthorizingKernel;
use App\Tests\Fixtures\Cqrs\FaultControl;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$kernel = new AuthorizingKernel('test', false);
try {
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    if (!$container instanceof ContainerInterface || !isset($argv) || 4 !== count($argv)) {
        throw new LogicException('Invalid completion verification arguments.');
    }
    $db = $container->get('doctrine.dbal.default_connection');
    $manager = $container->get('doctrine.orm.default_entity_manager');
    $commands = $container->get('test.authorizing.commands');
    $fault = $container->get('test.authorizing.fault');
    $requests = $container->get('request_stack');
    $tokens = $container->get('security.token_storage');
    if (!$db instanceof Connection || !$manager instanceof EntityManagerInterface || !$commands instanceof CommandBus || !$fault instanceof FaultControl || !$requests instanceof RequestStack || !$tokens instanceof TokenStorageInterface || 'app_test' !== $db->fetchOne('SELECT current_database()') || 'app' !== $db->fetchOne('SELECT current_user')) {
        throw new LogicException('Invalid completion verification runtime.');
    }
    $account = Uuid::fromString($argv[1]);
    $task = Uuid::fromString($argv[2]);
    $title = $argv[3];
    if (!str_starts_with($title, 'task10-')) {
        throw new LogicException('Invalid completion verification title.');
    }
    $requests->push(Request::create('/_demo/tasks'));
    $tokens->setToken(new UsernamePasswordToken(new AccountPrincipal($account, 'held@example.test', 'unused-task10-fixture'), 'main', []));
    $rollback = false;
    $fault->afterAdd = static function () use ($commands, $manager, $task, &$rollback): void {
        $result = $commands->dispatch(new CompleteTaskCommand($task->toRfc4122()));
        if (!$result instanceof CompleteTaskResult || !$result->changed) {
            throw new LogicException('Expected first completion to change the task.');
        }
        // Verification-only explicit flush: the normal handler never flushes.
        $manager->flush();
        echo "LOCKED\n";
        echo 'COMPLETED='.$result->completedAt->format('Y-m-d\TH:i:s\Z')."\n";
        flush();
        // The parent Process timeout bounds this fixture-only pipe barrier.
        $decision = fgets(STDIN);
        if ("rollback\n" === $decision) {
            $rollback = true;
            throw new RuntimeException('Controlled root rollback.');
        }
        if ("commit\n" !== $decision) {
            throw new LogicException('Missing completion barrier decision.');
        }
    };
    try {
        $commands->dispatch(new CreateTaskCommand($title, $account));
    } catch (RuntimeException $failure) {
        if (!$rollback || 'Controlled root rollback.' !== $failure->getMessage()) {
            throw $failure;
        }
    }
    if ($db->isTransactionActive()) {
        throw new LogicException('Root transaction did not finish.');
    }
    echo "FINISHED\n";
} catch (Throwable) {
    fwrite(STDERR, "Completion verification failed.\n");
    exit(1);
} finally {
    $kernel->shutdown();
}
