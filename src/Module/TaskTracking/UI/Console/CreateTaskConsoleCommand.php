<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\UI\Console;

use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Platform\Messaging\CommandBus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:task:create', description: 'Create a task through the command bus.')]
final class CreateTaskConsoleCommand extends Command
{
    public function __construct(private readonly CommandBus $commands, private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('title', InputArgument::REQUIRED, 'Task title');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $title = $input->getArgument('title');
            if (!is_string($title)) {
                return Command::INVALID;
            }
            $id = $this->commands->dispatch(new CreateTaskCommand($title));
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
