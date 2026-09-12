<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

final class DatabaseDownTest extends TestCase
{
    public function testDatabaseOutageDoesNotBreakLivenessOrTwig(): void
    {
        $client = HttpClient::createForBaseUri('http://app:8080', ['max_duration' => 5]);
        foreach (['/', '/health/live'] as $path) {
            self::assertSame(200, $client->request('GET', $path)->getStatusCode());
        }
        $started = microtime(true);
        $response = $client->request('GET', '/health/ready');
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('Unavailable', $response->getContent(false));
        self::assertLessThan(5.0, microtime(true) - $started);
    }

    public function testBusValidationPrecedesDatabaseAccessDuringOutage(): void
    {
        $client = HttpClient::createForBaseUri('http://app:8080', ['max_duration' => 5]);
        self::assertSame(422, $client->request('POST', '/_demo/tasks', ['json' => ['title' => '']])->getStatusCode());
        $response = $client->request('POST', '/_demo/tasks', ['json' => ['title' => 'outage-must-not-persist']]);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'operation_failed'], $response->toArray(false));
        self::assertSame(200, $client->request('GET', '/health/live')->getStatusCode());
    }
}
