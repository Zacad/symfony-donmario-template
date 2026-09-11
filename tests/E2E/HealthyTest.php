<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

final class HealthyTest extends TestCase
{
    public function testTwigAndCompiledStylesheetAreServedOverHttp(): void
    {
        $client = HttpClient::createForBaseUri('http://app:8080', ['max_duration' => 5]);
        $response = $client->request('GET', '/');
        self::assertSame(200, $response->getStatusCode());
        $html = $response->getContent();
        self::assertStringContainsString('<h1>DonMario application template</h1>', $html);
        if (1 !== preg_match('/href="([^"\s]+\.css)"/', $html, $matches)) {
            self::fail('The Twig page must link a local stylesheet.');
        }
        $stylesheet = $client->request('GET', $matches[1]);
        self::assertSame(200, $stylesheet->getStatusCode());
        self::assertStringContainsString('text/css', $stylesheet->getHeaders()['content-type'][0]);
        self::assertStringContainsString('color-scheme', $stylesheet->getContent());
    }

    public function testHealthAndWebrootConfinement(): void
    {
        $client = HttpClient::createForBaseUri('http://app:8080', ['max_duration' => 5]);
        foreach (['/health/live', '/health/ready'] as $path) {
            $response = $client->request('GET', $path);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('OK', $response->getContent());
            self::assertStringContainsString('no-store', $response->getHeaders()['cache-control'][0]);
        }
        foreach (['/.env', '/composer.json', '/.git/config', '/var/docker/local.env'] as $path) {
            self::assertSame(404, $client->request('GET', $path)->getStatusCode());
        }
    }

    public function testSnapshotIsReadOnlyAndContainsNoLocalCredentials(): void
    {
        self::assertFalse(is_writable('/app/composer.json'));
        self::assertFalse(is_writable('/app/vendor/autoload.php'));
        self::assertTrue(is_writable('/app/var'));
        self::assertFileDoesNotExist('/app/var/docker/local.env');
        self::assertFileDoesNotExist('/app/.env.local');
        self::assertFileDoesNotExist('/app/.git/config');
        foreach (['build-context-canary.pem', 'build-context-canary.key', 'config/jwt/build-context-canary.pem', 'config/jwt/build-context-canary.key'] as $canary) {
            self::assertFileDoesNotExist('/app/'.$canary);
        }
        self::assertFalse(getenv('POSTGRES_PASSWORD'));
        self::assertNotSame(0, posix_geteuid());
    }
}
