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
}
