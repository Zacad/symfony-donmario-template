<?php

declare(strict_types=1);

namespace App\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Technical connectivity check; business persistence belongs to modules. */
final class ReadinessProbe
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.readiness_connection')]
        private readonly Connection $connection,
    ) {
    }

    public function isReady(): bool
    {
        try {
            $this->connection->executeStatement('SET statement_timeout = 1000');

            return 1 === $this->connection->fetchOne('SELECT 1');
        } catch (Exception) {
            return false;
        } finally {
            $this->connection->close();
        }
    }
}
