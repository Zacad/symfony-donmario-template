<?php

declare(strict_types=1);

namespace App\Module\Authorizing\UI\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:authorization:role:define', description: 'Create, update or safely seed a runtime role.')]
final class DefineRoleConsoleCommand extends Command
{
    public function __construct(private readonly AuthorizationConsole $console)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'Immutable role key')
            ->addArgument('label', InputArgument::REQUIRED, 'Editable role label')
            ->addArgument('permissions', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'One to 4096 permission keys')
            ->addOption('expected-revision', null, InputOption::VALUE_REQUIRED, 'Required current revision for an update')
            ->addOption('if-absent', null, InputOption::VALUE_NONE, 'Create only when absent; never overwrite an existing role');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->console->run($output, fn (): array => $this->console->defineRole($input));
    }
}
