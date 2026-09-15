<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\CreateTask {
    use App\Module\TaskTracking\Application\GetTask\GetTaskQuery;
    use App\Module\TaskTracking\Domain\Task;
    use Symfony\Component\Uid\Uuid;

    final class EntityHandler
    {
        public function __invoke(CreateTaskCommand $command): Task
        {
            return new Task($command->title);
        }
    }

    final readonly class TaskInput
    {
        public function __construct(public string $title)
        {
        }
    }

    final class InputHandler
    {
        public function __invoke(CreateTaskCommand $command): TaskInput
        {
            return new TaskInput($command->title);
        }
    }

    final class ArrayHandler
    {
        /** @return list<string> */
        public function __invoke(CreateTaskCommand $command): array
        {
            return [$command->title];
        }
    }

    final readonly class CollectionCommand
    {
        /** @param list<string> $items */
        public function __construct(public array $items)
        {
        }
    }

    final readonly class CollectionQuery
    {
        /** @param list<string> $items */
        public function __construct(public array $items)
        {
        }
    }

    final readonly class CollectionResult
    {
        /** @param list<string> $items */
        public function __construct(public array $items)
        {
        }
    }

    final readonly class WrappedCommand
    {
        public function __construct(public CollectionResult $child)
        {
        }
    }

    final readonly class CollectionInput
    {
        public function __construct(public ?CollectionResult $child)
        {
        }
    }

    final readonly class WrappedQuery
    {
        public function __construct(public CollectionInput $child)
        {
        }
    }

    final readonly class WrappedResult
    {
        public function __construct(public CollectionInput $child)
        {
        }
    }

    final readonly class RecursiveCommand
    {
        public function __construct(public ?RecursiveInput $child = null)
        {
        }
    }

    final readonly class RecursiveInput
    {
        public function __construct(public ?RecursiveCommand $child = null)
        {
        }
    }

    enum ChoiceResult: string
    {
        case Ready = 'ready';
    }

    final class CollectionCommandHandler
    {
        public function __invoke(CreateTaskCommand $command): CollectionCommand
        {
            return new CollectionCommand([$command->title]);
        }
    }

    final class CollectionQueryHandler
    {
        public function __invoke(CreateTaskCommand $command): CollectionQuery
        {
            return new CollectionQuery([$command->title]);
        }
    }

    final class WrappedCommandHandler
    {
        public function __invoke(CreateTaskCommand $command): WrappedCommand
        {
            return new WrappedCommand(new CollectionResult([$command->title]));
        }
    }

    final class WrappedQueryHandler
    {
        public function __invoke(CreateTaskCommand $command): WrappedQuery
        {
            return new WrappedQuery(new CollectionInput(new CollectionResult([$command->title])));
        }
    }

    final class CollectionUnionHandler
    {
        public function __invoke(CreateTaskCommand $command): CollectionResult|WrappedCommand|null
        {
            return match ($command->title) {
                'null' => null,
                'result' => new CollectionResult([]),
                default => new WrappedCommand(new CollectionResult([$command->title])),
            };
        }
    }

    final class AcceptedDataHandler
    {
        public function __invoke(CreateTaskCommand $command): string|int|float|bool|Uuid|\DateTimeImmutable|ChoiceResult|CreateTaskCommand|GetTaskQuery|TaskCreatedEvent|RecursiveCommand|CollectionResult|WrappedResult|null
        {
            return match ($command->title) {
                'string' => 'value',
                'int' => 1,
                'float' => 1.5,
                'bool' => true,
                'null' => null,
                'uuid' => Uuid::v7(),
                'date' => new \DateTimeImmutable(),
                'enum' => ChoiceResult::Ready,
                'command' => $command,
                'query' => new GetTaskQuery(Uuid::v7()->toRfc4122()),
                'event' => new TaskCreatedEvent(Uuid::v7()),
                'recursive' => new RecursiveCommand(),
                'result' => new CollectionResult([]),
                default => new WrappedResult(new CollectionInput(new CollectionResult([$command->title]))),
            };
        }
    }

    final class VoidHandler
    {
        public function __invoke(CreateTaskCommand $command): void
        {
        }
    }

    final class UnionHandler
    {
        public function __invoke(CreateTaskCommand|GetTaskQuery $message): Uuid
        {
            return Uuid::v7();
        }
    }
}

namespace App\Module\Authorizing\Application\CreateTask {
    use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
    use Symfony\Component\Uid\Uuid;

    final class ForeignHandler
    {
        public function __invoke(CreateTaskCommand $command): Uuid
        {
            return Uuid::v7();
        }
    }
}
