<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class DatabaseTestCase extends KernelTestCase
{
    protected function database(string $name = 'default'): Connection
    {
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $connection = self::getContainer()->get('doctrine.dbal.'.$name.'_connection');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertSame('app_test', $connection->fetchOne('SELECT current_database()'));
        self::assertSame('app', $connection->fetchOne('SELECT current_user'));

        return $connection;
    }
}
