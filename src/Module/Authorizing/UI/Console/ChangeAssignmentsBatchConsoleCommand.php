<?php

declare(strict_types=1);

namespace App\Module\Authorizing\UI\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:authorization:change-batch', description: 'Atomically change assignments from a stdin JSON array (1–100 items, at most 64 KiB).')]
final class ChangeAssignmentsBatchConsoleCommand extends Command
{
    public function __construct(private readonly AuthorizationConsole $console)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('subject', InputArgument::REQUIRED, 'Subject UUID');
        $this->setHelp('Each item requires exactly operation, kind and key. Trusted operator shell access is administration authority.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->console->run($output, fn (): array => $this->console->changeBatch($input));
    }
}
