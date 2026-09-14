<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Fixtures\Authenticating\Browser;
use App\Tests\Fixtures\Authenticating\State;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AuthenticatingDatabaseDownTest extends TestCase
{
    public function testCliOutageUsesOperationStatusWithoutCredentialDiagnostics(): void
    {
        $state = State::load();
        $process = new Process(['php', 'bin/console', 'app:account:provision', $state['email'], '--password-stdin', '--no-interaction', '-vvv'], dirname(__DIR__, 2), timeout: 20);
        $process->setInput($state['password']);
        self::assertSame(1, $process->run());
        $output = $process->getOutput().$process->getErrorOutput();
        foreach ([$state['password'], $state['hash'], 'SQLSTATE', 'Stack trace'] as $private) {
            self::assertFalse(str_contains($output, $private), 'Unavailable CLI exposed credential or database details.');
        }
    }

    public function testAuthenticatedDatabaseOutageIsGenericAndPublicLivenessIsIndependent(): void
    {
        $state = State::load();
        // Exercise the still-authenticated session before /account's outage
        // handler invalidates it; otherwise this would only prove anonymous health.
        foreach (['/', '/health/live'] as $path) {
            $public = Browser::create($state['session']);
            $public->request('GET', $path);
            self::assertSame(200, $public->getResponse()->getStatusCode(), 'Public route must not refresh the authenticated principal.');
        }
        $browser = Browser::create($state['session']);
        $started = microtime(true);
        $browser->request('GET', '/account');
        self::assertSame(503, $browser->getResponse()->getStatusCode());
        self::assertLessThan(12, microtime(true) - $started);
        $body = (string) $browser->getResponse()->getContent();
        foreach (['email', 'id', 'password', 'hash'] as $field) {
            self::assertFalse(str_contains($body, $state[$field]), 'Outage response exposed authentication data.');
        }
        foreach (['SQLSTATE', 'postgresql://', 'Stack trace', 'password_hash'] as $internal) {
            self::assertFalse(str_contains($body, $internal));
        }
        self::assertTrue(str_contains(Browser::header($browser, 'Cache-Control'), 'no-store'));
        $browser->request('GET', '/health/ready');
        self::assertSame(503, $browser->getResponse()->getStatusCode());
    }

    public function testLoginPostDuringDatabaseOutageHasNoAuthenticationFallback(): void
    {
        $state = State::load();
        $browser = Browser::create();
        $token = Browser::token($browser);
        $started = microtime(true);
        $browser->request('POST', '/login', ['_username' => $state['email'], '_password' => $state['password'], '_csrf_token' => $token]);
        self::assertSame(503, $browser->getResponse()->getStatusCode());
        self::assertLessThan(12, microtime(true) - $started);
        $body = (string) $browser->getResponse()->getContent();
        foreach ([$state['email'], $state['id'], $state['password'], $state['hash'], $token, 'SQLSTATE', 'postgresql://', 'Stack trace'] as $private) {
            self::assertFalse(str_contains($body, $private), 'Login outage response exposed private authentication data.');
        }
        self::assertTrue(str_contains(Browser::header($browser, 'Cache-Control'), 'no-store'));
        $browser->request('GET', '/account');
        self::assertTrue(Browser::redirectedTo($browser, '/login'), 'Unavailable account lookup must not authenticate using cached or fallback credentials.');
    }
}
