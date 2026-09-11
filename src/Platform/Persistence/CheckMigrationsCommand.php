<?php

declare(strict_types=1);

namespace App\Platform\Persistence;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:migrations:check', description: 'Validate module migration inventory and timestamps without accessing the database.')]
final class CheckMigrationsCommand extends Command
{
    public function __construct(private readonly MigrationInventory $inventory)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->inventory->assertValid();
        $output->writeln('Module migration inventory and timestamps passed (offline).');

        return self::SUCCESS;
    }
}
