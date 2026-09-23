<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\UI\Console;

use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Platform\Authorization\OperatorExecution;
use App\Platform\Messaging\CommandBus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:task:create', description: 'Create a task through the command bus.')]
final class CreateTaskConsoleCommand extends Command
{
    public function __construct(private readonly CommandBus $commands, private readonly LoggerInterface $logger, private readonly OperatorExecution $operator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('title', InputArgument::REQUIRED, 'Task title');
        $this->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Owner account UUID (business target)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $title = $input->getArgument('title');
            if (!is_string($title)) {
                return Command::INVALID;
            }
            $owner = $input->getOption('owner');
            if (null !== $owner && (!is_string($owner) || 36 !== strlen($owner) || !Uuid::isValid($owner))) {
                $output->writeln('Invalid task input.', OutputInterface::OUTPUT_RAW);

                return Command::INVALID;
            }
            $message = new CreateTaskCommand($title, null === $owner ? null : Uuid::fromString($owner));
            $id = $this->operator->run('tasks', fn (): mixed => $this->commands->dispatch($message));
            if (!$id instanceof Uuid) {
                throw new \LogicException('Unexpected create result.');
            }
            $output->writeln($id->toRfc4122(), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        } catch (ValidationFailedException $failure) {
            foreach ($failure->getViolations() as $violation) {
                $output->writeln($violation->getPropertyPath().': '.$violation->getMessage(), OutputInterface::OUTPUT_RAW);
            }

            return Command::INVALID;
        } catch (\Throwable $failure) {
            $this->logger->error('Task operation failed.', ['operation' => 'create_task', 'failure_type' => $failure::class]);
            $output->writeln('Task operation failed.', OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }
    }
}
