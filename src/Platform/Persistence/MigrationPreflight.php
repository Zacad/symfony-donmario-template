<?php

declare(strict_types=1);

namespace App\Platform\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MigrationPreflight
{
    public function __construct(
        private MigrationInventory $inventory,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private DependencyFactory $factory,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function assertSafe(): void
    {
        $this->inventory->assertValid();
        if ('test' === $this->environment) {
            self::assertTestDatabase($this->factory->getConnection());
        }
    }

    public static function assertTestDatabase(Connection $connection): void
    {
        $params = $connection->getParams();
        if ('database' !== ($params['host'] ?? null) || 'app_test' !== $connection->fetchOne('SELECT current_database()') || 'app' !== $connection->fetchOne('SELECT current_user')) {
            throw new \LogicException('migration.target: tests require the isolated database/app_test connection as app.');
        }
    }
}
