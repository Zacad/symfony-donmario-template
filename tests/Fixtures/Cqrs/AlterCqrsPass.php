<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Cqrs;

use App\Module\Authorizing\Application\CreateTask\ForeignHandler;
use App\Module\TaskTracking\Application\CreateTask\AcceptedDataHandler;
use App\Module\TaskTracking\Application\CreateTask\ArrayHandler;
use App\Module\TaskTracking\Application\CreateTask\CollectionCommandHandler;
use App\Module\TaskTracking\Application\CreateTask\CollectionQueryHandler;
use App\Module\TaskTracking\Application\CreateTask\CollectionUnionHandler;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskHandler;
use App\Module\TaskTracking\Application\CreateTask\EntityHandler;
use App\Module\TaskTracking\Application\CreateTask\InputHandler;
use App\Module\TaskTracking\Application\CreateTask\UnionHandler;
use App\Module\TaskTracking\Application\CreateTask\VoidHandler;
use App\Module\TaskTracking\Application\CreateTask\WrappedCommandHandler;
use App\Module\TaskTracking\Application\CreateTask\WrappedQueryHandler;
use App\Module\TaskTracking\Application\GetTask\GetTaskHandler;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\CommandTransactionMiddleware;
use App\Platform\Messaging\InvocationContext;
use App\Platform\Messaging\ResultValidationMiddleware;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

require_once __DIR__.'/HandlerFixtures.php';

final readonly class AlterCqrsPass implements CompilerPassInterface
{
    public function __construct(private string $scenario)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        $handler = $container->getDefinition(CreateTaskHandler::class);
        switch ($this->scenario) {
            case 'bus-constructor':
                $container->getDefinition('command.bus')->addMethodCall('__construct', [[new Reference('query.bus.middleware.handle_message')]]);
                break;
            case 'locator-constructor':
                $container->getDefinition('command.bus.messenger.handlers_locator')->addMethodCall('__construct', [[]]);
                break;
            case 'descriptor-constructor':
                $mapping = $container->getDefinition('command.bus.messenger.handlers_locator')->getArgument(0);
                if (!is_array($mapping) || !($mapping[CreateTaskCommand::class] ?? null) instanceof IteratorArgument) {
                    throw new \LogicException('Expected the compiled Messenger map.');
                }
                $descriptor = $mapping[CreateTaskCommand::class]->getValues()[0];
                if (!$descriptor instanceof Reference) {
                    throw new \LogicException('Expected the compiled handler descriptor.');
                }
                $container->findDefinition((string) $descriptor)->addMethodCall('__construct', [new Reference(GetTaskHandler::class)]);
                break;
            case 'public-handler-definition':
                $handler->setPublic(true);
                break;
            case 'public-handler':
                $container->setAlias('public.handler', CreateTaskHandler::class)->setPublic(true);
                break;
            case 'public-handler-chain':
                $container->setAlias('private.handler.alias', CreateTaskHandler::class);
                $container->setAlias('public.handler', 'private.handler.alias')->setPublic(true);
                break;
            case 'public-handler-copy':
                $copy = clone $handler;
                $copy->clearTag('messenger.message_handler');
                $container->setDefinition('copied.handler', $copy);
                $container->setAlias('public.handler', 'copied.handler')->setPublic(true);
                break;
            case 'unshared-context':
                $container->getDefinition(InvocationContext::class)->setShared(false);
                break;
            case 'missing':
                $handler->clearTag('messenger.message_handler');
                break;
            case 'duplicate':
                $container->setDefinition('duplicate.handler', clone $handler);
                break;
            case 'wrong-bus':
                $handler->clearTag('messenger.message_handler')->addTag('messenger.message_handler', ['bus' => 'query.bus']);
                break;
            case 'implicit-bus':
                $handler->clearTag('messenger.message_handler')->addTag('messenger.message_handler');
                break;
            case 'wildcard':
                $handler->clearTag('messenger.message_handler')->addTag('messenger.message_handler', ['bus' => 'command.bus', 'handles' => '*']);
                break;
            case 'transport':
                $handler->clearTag('messenger.message_handler')->addTag('messenger.message_handler', ['bus' => 'command.bus', 'from_transport' => 'async']);
                break;
            case 'foreign':
            case 'entity-result':
            case 'input-result':
            case 'array-result':
            case 'collection-command-result':
            case 'collection-query-result':
            case 'wrapped-command-result':
            case 'wrapped-query-result':
            case 'collection-union-result':
            case 'accepted-data-result':
            case 'void-result':
            case 'union':
                $handler->setClass(match ($this->scenario) {
                    'foreign' => ForeignHandler::class,
                    'entity-result' => EntityHandler::class,
                    'input-result' => InputHandler::class,
                    'array-result' => ArrayHandler::class,
                    'collection-command-result' => CollectionCommandHandler::class,
                    'collection-query-result' => CollectionQueryHandler::class,
                    'wrapped-command-result' => WrappedCommandHandler::class,
                    'wrapped-query-result' => WrappedQueryHandler::class,
                    'collection-union-result' => CollectionUnionHandler::class,
                    'accepted-data-result' => AcceptedDataHandler::class,
                    'void-result' => VoidHandler::class,
                    default => UnionHandler::class,
                })->setArguments([]);
                $handler->clearTag('messenger.message_handler')->addTag('messenger.message_handler', ['bus' => 'command.bus', 'handles' => CreateTaskCommand::class]);
                break;
            case 'missing-command-result-validation':
            case 'missing-query-result-validation':
            case 'result-before-transaction':
            case 'result-before-input':
            case 'result-after-handler':
                $bus = 'missing-query-result-validation' === $this->scenario ? 'query' : 'command';
                /** @var list<array{id: string}> $middleware */
                $middleware = $container->getParameter($bus.'.bus.middleware');
                $middleware = array_values(array_filter($middleware, static fn (array $item): bool => ResultValidationMiddleware::class !== $item['id']));
                if (!str_starts_with($this->scenario, 'missing-')) {
                    $before = match ($this->scenario) {
                        'result-before-transaction' => CommandTransactionMiddleware::class,
                        'result-before-input' => 'validation',
                        default => null,
                    };
                    $position = count($middleware);
                    foreach ($middleware as $index => $item) {
                        if ($item['id'] === $before) {
                            $position = $index;
                        }
                    }
                    array_splice($middleware, $position, 0, [['id' => ResultValidationMiddleware::class, 'arguments' => []]]);
                }
                $container->setParameter($bus.'.bus.middleware', $middleware);
                break;
            case 'result-constructor':
                $container->getDefinition(ResultValidationMiddleware::class)->addMethodCall('__construct', [new Reference('validator')]);
                break;
            case 'missing-validation':
                /** @var list<array{id: string}> $middleware */
                $middleware = $container->getParameter('command.bus.middleware');
                $container->setParameter('command.bus.middleware', array_values(array_filter($middleware, static fn (array $item): bool => 'validation' !== $item['id'])));
                break;
            case 'facade-bypass':
                $container->getDefinition(CommandBus::class)->setArgument(0, new Reference('query.bus'));
                break;
            case 'missing-mapping':
                $builder = $container->getDefinition('validator.builder');
                /** @var list<array{0: string, 1: array<int|string, mixed>, 2?: bool}> $calls */
                $calls = $builder->getMethodCalls();
                $builder->setMethodCalls(array_values(array_filter($calls, static fn (array $call): bool => 'addYamlMappings' !== $call[0])));
                break;
        }
    }
}
