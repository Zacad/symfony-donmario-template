<?php

declare(strict_types=1);

namespace App\Platform\Persistence;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Enforce the same preflight for setup and direct Doctrine console execution. */
final readonly class MigrationCommandListener
{
    public function __construct(private MigrationPreflight $preflight)
    {
    }

    #[AsEventListener(event: ConsoleEvents::COMMAND)]
    public function onCommand(ConsoleCommandEvent $event): void
    {
        if (in_array($event->getCommand()?->getName(), ['doctrine:migrations:migrate', 'doctrine:migrations:execute', 'doctrine:migrations:sync-metadata-storage', 'doctrine:migrations:version', 'doctrine:migrations:rollup'], true)) {
            foreach (['configuration', 'em', 'conn'] as $option) {
                if (null !== $event->getInput()->getOption($option)) {
                    throw new \LogicException('migration.configuration: mutations use the configured default EntityManager and module inventory; CLI overrides are unsupported.');
                }
            }
            $this->preflight->assertSafe();
        }
    }
}
