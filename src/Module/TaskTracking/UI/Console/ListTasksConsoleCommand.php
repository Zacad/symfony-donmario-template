<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\UI\Console;

use App\Module\TaskTracking\Application\ListTasks\ListTasksQuery;
use App\Module\TaskTracking\Application\ListTasks\ListTasksResult;
use App\Module\TaskTracking\Application\ListTasks\TaskListItemResult;
use App\Module\TaskTracking\Domain\InvalidTaskInput;
use App\Platform\Authorization\OperatorExecution;
use App\Platform\Messaging\QueryBus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:task:list', description: 'List tasks through the query bus.')]
final class ListTasksConsoleCommand extends Command
{
    public function __construct(private readonly QueryBus $queries, private readonly LoggerInterface $logger, private readonly OperatorExecution $operator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Limit results to an owner account UUID');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Page size from 1 to 100', '50');
        $this->addOption('after', null, InputOption::VALUE_REQUIRED, 'Opaque continuation from the preceding page');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $owner = $input->getOption('owner');
            $limit = $input->getOption('limit');
            $after = $input->getOption('after');
            if ((null !== $owner && (!is_string($owner) || 36 !== strlen($owner) || !Uuid::isValid($owner)))
                || !is_string($limit) || 1 !== preg_match('/\A(?:[1-9][0-9]?|100)\z/D', $limit)
                || (null !== $after && (!is_string($after) || strlen($after) > 512))) {
                throw new InvalidTaskInput('Invalid task input.');
            }

            $query = new ListTasksQuery(null === $owner ? null : Uuid::fromString($owner), (int) $limit, $after);
            $result = $this->operator->run('tasks', fn (): mixed => $this->queries->ask($query));
            if (!$result instanceof ListTasksResult) {
                throw new \LogicException('Unexpected task list result.');
            }
            $output->writeln(json_encode([
                'tasks' => array_map(static fn (TaskListItemResult $task): array => [
                    'id' => $task->id->toRfc4122(),
                    'title' => $task->title,
                    'ownerAccountId' => $task->ownerAccountId?->toRfc4122(),
                    'completedAt' => $task->completedAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                ], $result->tasks),
                'next' => $result->next,
            ], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        } catch (ValidationFailedException|InvalidTaskInput) {
            $output->writeln('Invalid task input.', OutputInterface::OUTPUT_RAW);

            return Command::INVALID;
        } catch (\Throwable $failure) {
            $this->logger->error('Task operation failed.', ['operation' => 'list_tasks', 'failure_type' => $failure::class]);
            $output->writeln('Task operation failed.', OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }
    }
}
