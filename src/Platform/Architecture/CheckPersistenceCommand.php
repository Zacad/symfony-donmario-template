<?php

declare(strict_types=1);

namespace App\Platform\Architecture;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:architecture:check', description: 'Validate module persistence ownership (offline unless --database).')]
final class CheckPersistenceCommand extends Command
{
    public function __construct(private readonly PersistenceBoundaries $boundaries)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('database', null, InputOption::VALUE_NONE, 'Also audit the actual public PostgreSQL schema.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            if ($input->getOption('database')) {
                $this->boundaries->assertDatabase();
                $io->success('Persistence metadata, inventory and public database schema passed.');
            } else {
                $this->boundaries->assertMetadata();
                $io->success('Persistence metadata and entity inventory passed (offline).');
            }
        } catch (\LogicException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
