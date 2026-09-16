<?php

declare(strict_types=1);

namespace App\Module\Authorizing\UI\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:authorization:role:assign', description: 'Assign a role using trusted operator shell authority.')]
final class AssignRoleConsoleCommand extends Command
{
    public function __construct(private readonly AuthorizationConsole $console, private readonly AuthorizationConsoleInput $transport)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('account', InputArgument::REQUIRED, 'Account UUID')
            ->addArgument('role', InputArgument::REQUIRED, 'Role key');
        $this->transport->configureScope($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->console->run($output, fn (): array => $this->console->change($input, 'add', 'role', 'role'));
    }
}
