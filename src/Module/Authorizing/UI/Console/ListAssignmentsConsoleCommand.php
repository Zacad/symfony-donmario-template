<?php

declare(strict_types=1);

namespace App\Module\Authorizing\UI\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:authorization:assignments', description: 'List a current page of account assignments as JSON.')]
final class ListAssignmentsConsoleCommand extends Command
{
    public function __construct(private readonly AuthorizationConsole $console)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('account', InputArgument::REQUIRED, 'Account UUID')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Page size (1–100).', '50')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'The next cursor from the previous page.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->console->run($output, fn (): array => $this->console->assignments($input));
    }
}
