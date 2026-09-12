<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\UI\Console;

use App\Module\TaskTracking\Application\GetTask\GetTaskQuery;
use App\Module\TaskTracking\Application\GetTask\GetTaskResult;
use App\Platform\Messaging\QueryBus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Exception\ValidationFailedException;

#[AsCommand(name: 'app:task:show', description: 'Read a task through the query bus.')]
final class ShowTaskConsoleCommand extends Command
{
    public function __construct(private readonly QueryBus $queries, private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Task UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $id = $input->getArgument('id');
            if (!is_string($id)) {
                return Command::INVALID;
            }
            $task = $this->queries->ask(new GetTaskQuery($id));
            if (null === $task) {
                $output->writeln('Task not found.', OutputInterface::OUTPUT_RAW);

                return Command::FAILURE;
            }
            if (!$task instanceof GetTaskResult) {
                throw new \LogicException('Unexpected lookup result.');
            }
            $output->writeln(json_encode(['id' => $task->id->toRfc4122(), 'title' => $task->title], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        } catch (ValidationFailedException $failure) {
            foreach ($failure->getViolations() as $violation) {
                $output->writeln($violation->getPropertyPath().': '.$violation->getMessage(), OutputInterface::OUTPUT_RAW);
            }

            return Command::INVALID;
        } catch (\Throwable $failure) {
            $this->logger->error('Task operation failed.', ['operation' => 'get_task', 'failure_type' => $failure::class]);
            $output->writeln('Task operation failed.', OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }
    }
}
