<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Fixtures\Authenticating\JwtHttp;
use App\Tests\Fixtures\Authenticating\JwtState;
use PHPUnit\Framework\TestCase;

/** Deliberately no database test base or kernel boot while PostgreSQL is stopped. */
final class AuthenticatingJwtDatabaseDownTest extends TestCase
{
    public function testLoginAndPreviouslyValidBearerFailClosedDuringDatabaseOutage(): void
    {
        $state = JwtState::load();
        $started = microtime(true);
        JwtHttp::denied(JwtHttp::me($state['token']), 503, array_values($state));
        self::assertLessThan(12, microtime(true) - $started);
        $started = microtime(true);
        JwtHttp::denied(JwtHttp::login($state['email'], $state['password']), 503, array_values($state));
        self::assertLessThan(12, microtime(true) - $started);
    }

    public function testMalformedBearerIsRejectedBeforeDatabaseLookupAndDocsStayPublic(): void
    {
        $state = JwtState::load();
        // Actual trusted signature plus wrong issuer must fail before the live
        // account provider; otherwise the unavailable database produces 503.
        JwtHttp::denied(JwtHttp::me($state['invalid_token']), secrets: [$state['invalid_token']]);
        JwtHttp::denied(JwtHttp::me('malformed'));
        JwtHttp::denied(JwtHttp::request('GET', '/api/me'));
        foreach (['/api/docs.json', '/health/live'] as $path) {
            $response = JwtHttp::request('GET', $path, ['headers' => ['Authorization' => 'Bearer malformed']]);
            self::assertSame(200, $response->getStatusCode());
            self::assertFalse(isset($response->getHeaders(false)['set-cookie']));
        }
        self::assertSame(503, JwtHttp::request('GET', '/health/ready')->getStatusCode());
    }
}
