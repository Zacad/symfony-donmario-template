<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Fixtures\Authenticating\JwtHttp;
use App\Tests\Fixtures\Authenticating\JwtState;
use PHPUnit\Framework\TestCase;

/** The orchestrator removes only active/private.pem, retaining public trust. */
final class AuthenticatingJwtSigningDownTest extends TestCase
{
    public function testMissingPrivateKeyCannotIssueButExistingBearerStillUsesPublicKey(): void
    {
        $state = JwtState::load();
        JwtHttp::denied(JwtHttp::login($state['email'], $state['password']), 503, array_values($state));
        JwtHttp::identity(JwtHttp::me($state['token']), $state['id'], $state['email']);
        self::assertSame(200, JwtHttp::request('GET', '/health/live')->getStatusCode());
        self::assertSame(200, JwtHttp::request('GET', '/api/docs.json')->getStatusCode());
    }
}
