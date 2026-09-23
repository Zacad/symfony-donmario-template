<?php

declare(strict_types=1);

namespace App\Module\Authorizing\UI\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:authorization:check', description: 'Check one permission; denial is a successful decision, not an operation error.')]
final class CheckPermissionConsoleCommand extends Command
{
    public function __construct(private readonly AuthorizationConsole $console)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('subject', InputArgument::REQUIRED, 'Subject UUID')
            ->addArgument('permission', InputArgument::REQUIRED, 'Permission key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->console->run($output, fn (): array => $this->console->check($input));
    }
}
