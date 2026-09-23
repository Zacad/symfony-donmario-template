<?php

declare(strict_types=1);

use App\Module\Authorizing\Application\RetireRole\RetireRoleCommand;
use App\Module\Authorizing\Application\RetireRole\RetireRoleResult;
use App\Platform\Authorization\Actor;
use App\Platform\Authorization\ActorKind;
use App\Platform\Authorization\ExecutionContext;
use App\Platform\Messaging\CommandBus;
use App\Tests\Fixtures\Authorizing\AuthorizingKernel;
use Symfony\Component\DependencyInjection\ContainerInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$kernel = new AuthorizingKernel('test', false);
try {
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    if (!$container instanceof ContainerInterface || !isset($argv) || 3 !== count($argv)) {
        throw new LogicException('Invalid role-retirement verification arguments.');
    }
    $commands = $container->get('test.authorizing.commands');
    $execution = $container->get(ExecutionContext::class);
    if (!$commands instanceof CommandBus || !$execution instanceof ExecutionContext) {
        throw new LogicException('Invalid role-retirement verification runtime.');
    }
    $revision = filter_var($argv[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!is_int($revision)) {
        throw new LogicException('Invalid role-retirement verification revision.');
    }
    $result = $execution->run(
        new Actor(ActorKind::Operator, scope: 'catalogue'),
        fn (): mixed => $commands->dispatch(new RetireRoleCommand($argv[1], $revision)),
    );
    if (!$result instanceof RetireRoleResult) {
        throw new LogicException('Role retirement did not complete.');
    }
    echo "RETIRED\n";
} catch (Throwable) {
    fwrite(STDERR, "Role-retirement verification failed.\n");
    exit(1);
} finally {
    $kernel->shutdown();
}
