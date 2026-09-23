<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\UI\Console;

use App\Module\TaskTracking\Application\CompleteTask\CompleteTaskCommand;
use App\Module\TaskTracking\Application\CompleteTask\CompleteTaskResult;
use App\Platform\Authorization\OperatorExecution;
use App\Platform\Messaging\CommandBus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Exception\ValidationFailedException;

#[AsCommand(name: 'app:task:complete', description: 'Complete a task through the command bus.')]
final class CompleteTaskConsoleCommand extends Command
{
    public function __construct(private readonly CommandBus $commands, private readonly LoggerInterface $logger, private readonly OperatorExecution $operator)
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
            $command = new CompleteTaskCommand($id);
            $result = $this->operator->run('tasks', fn (): mixed => $this->commands->dispatch($command));
            if (null === $result) {
                $output->writeln('Task not found.', OutputInterface::OUTPUT_RAW);

                return Command::FAILURE;
            }
            if (!$result instanceof CompleteTaskResult) {
                throw new \LogicException('Unexpected task completion result.');
            }
            $output->writeln(json_encode([
                'id' => $result->id->toRfc4122(),
                'changed' => $result->changed,
                'completedAt' => $result->completedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        } catch (ValidationFailedException) {
            $output->writeln('Invalid task input.', OutputInterface::OUTPUT_RAW);

            return Command::INVALID;
        } catch (\Throwable $failure) {
            $this->logger->error('Task operation failed.', ['operation' => 'complete_task', 'failure_type' => $failure::class]);
            $output->writeln('Task operation failed.', OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }
    }
}
