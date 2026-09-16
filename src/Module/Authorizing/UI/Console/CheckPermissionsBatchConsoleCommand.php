<?php

declare(strict_types=1);

namespace App\Module\Authorizing\UI\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:authorization:check-batch', description: 'Check permissions from a stdin JSON array (1–100 items, at most 64 KiB).')]
final class CheckPermissionsBatchConsoleCommand extends Command
{
    public function __construct(private readonly AuthorizationConsole $console)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp('Each item requires accountId, permission and scope; resource scope also requires resourceType and resourceId. Decisions retain input order.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->console->run($output, fn (): array => $this->console->checkBatch($input));
    }
}
