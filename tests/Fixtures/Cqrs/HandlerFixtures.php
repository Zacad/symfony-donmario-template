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
