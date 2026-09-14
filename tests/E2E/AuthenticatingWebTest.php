<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\Authenticating\Application\UpgradePasswordHash\UpgradePasswordHashCommand;
use App\Tests\Fixtures\Authenticating\Browser;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Uid\Uuid;

final class AuthenticatingWebTest extends AuthenticatingTestCase
{
    public function testNativeLoginRotatesSessionRehashesAndLogoutInvalidatesReplay(): void
    {
        $account = $this->account();
        $chosen = bin2hex(random_bytes(16));
        $browser = Browser::create($chosen);
        $token = Browser::token($browser);
        $anonymous = Browser::session($browser);
        self::assertFalse(hash_equals($chosen, $anonymous), 'Strict session mode accepted an attacker-chosen identifier.');
        $browser->request('POST', '/login', ['_username' => strtoupper($account['email']), '_password' => $account['password'], '_csrf_token' => $token, '_target_path' => 'https://hostile.invalid/steal']);
        self::assertTrue(Browser::redirectedTo($browser, '/account'), 'Login must use its fixed local success target.');
        $authenticated = Browser::session($browser);
        self::assertFalse(hash_equals($anonymous, $authenticated), 'Login did not rotate the session ID.');
        $setCookie = strtolower(Browser::header($browser, 'Set-Cookie'));
        self::assertTrue(str_contains($setCookie, 'httponly'));
        self::assertTrue(str_contains($setCookie, 'samesite=lax'));
        self::assertFalse(str_contains($setCookie, 'domain='), 'Cookie must remain host-only.');
        self::assertFalse(str_contains($setCookie, 'secure'), 'Local HTTP needs an HTTP-usable cookie.');
        $browser->request('GET', '/account');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $body = (string) $browser->getResponse()->getContent();
        self::assertTrue(str_contains($body, $account['id']));
        self::assertTrue(str_contains($body, $account['email']));
        self::assertFalse(str_contains($body, $account['password']));
        self::assertFalse(str_contains($body, $account['hash']));
        self::assertTrue(str_contains(Browser::header($browser, 'Cache-Control'), 'no-store'));
        $upgraded = $this->storedHash($account['id']);
        self::assertFalse(hash_equals($account['hash'], $upgraded));
        self::assertSame(13, password_get_info($upgraded)['options']['cost'] ?? null);
        self::assertTrue(password_verify($account['password'], $upgraded));
        $browser->request('GET', '/account');
        self::assertSame(200, $browser->getResponse()->getStatusCode(), 'The rehashed session must refresh successfully.');
        $renamed = $this->prefix.'-renamed@example.test';
        $this->connection->executeStatement('UPDATE public.authenticating_account SET email = ? WHERE id = ?', [$renamed, $account['id']]);
        $browser->request('GET', '/account');
        self::assertSame(200, $browser->getResponse()->getStatusCode(), 'Session refresh must look up the stable UUID rather than the old email.');
        self::assertTrue(str_contains((string) $browser->getResponse()->getContent(), $renamed));
        $old = Browser::create($anonymous);
        $old->request('GET', '/account');
        self::assertTrue(Browser::redirectedTo($old, '/login'));

        $browser->request('GET', '/logout');
        self::assertSame(405, $browser->getResponse()->getStatusCode());
        $browser->request('POST', '/logout', ['_csrf_token' => Browser::secret()]);
        self::assertContains($browser->getResponse()->getStatusCode(), [400, 403]);
        $logout = Browser::token($browser, '/account');
        $browser->request('POST', '/logout', ['_csrf_token' => $logout, '_target_path' => 'https://hostile.invalid/steal']);
        self::assertTrue(Browser::redirectedTo($browser, '/login'));
        $replay = Browser::create($authenticated);
        $replay->request('GET', '/account');
        self::assertTrue(Browser::redirectedTo($replay, '/login'), 'A logged-out cookie must not authenticate.');
        $browser->request('POST', '/logout', ['_csrf_token' => $logout]);
        self::assertNotSame(200, $browser->getResponse()->getStatusCode());
    }

    public function testUnknownAndWrongPasswordFailuresAreIndistinguishableAndDoNotRehash(): void
    {
        $account = $this->account();
        $messages = [];
        foreach ([$account['email'], $this->prefix.'-unknown@example.test'] as $email) {
            $browser = Browser::create();
            $password = Browser::secret();
            Browser::login($browser, $email, $password);
            self::assertTrue(Browser::redirectedTo($browser, '/login'));
            $crawler = $browser->followRedirect();
            $body = (string) $browser->getResponse()->getContent();
            self::assertFalse(str_contains($body, $password));
            self::assertFalse(str_contains($body, $account['hash']));
            $messages[] = $crawler->filter('[role="alert"]')->text();
            $browser->request('GET', '/account');
            self::assertTrue(Browser::redirectedTo($browser, '/login'));
        }
        self::assertSame($messages[0], $messages[1]);
        self::assertTrue(hash_equals($account['hash'], $this->storedHash($account['id'])));
    }

    public function testCrossSessionCsrfAndMalformedFormsCannotAuthenticate(): void
    {
        $account = $this->account();
        $other = Browser::create();
        $crossSession = Browser::token($other);
        $cases = [
            ['_username' => $account['email'], '_password' => $account['password']],
            ['_username' => $account['email'], '_password' => $account['password'], '_csrf_token' => $crossSession],
            ['_username' => [$account['email']], '_password' => $account['password'], '_csrf_token' => $crossSession],
            ['_username' => $account['email'], '_password' => [$account['password']], '_csrf_token' => $crossSession],
            ['_username' => $account['email'], '_password' => $account['password'], '_csrf_token' => [$crossSession]],
        ];
        foreach ($cases as $parameters) {
            $browser = Browser::create();
            Browser::token($browser);
            $browser->request('POST', '/login', $parameters + ['_failure_path' => 'https://hostile.invalid/steal', '_target_path' => '//hostile.invalid/steal']);
            self::assertContains($browser->getResponse()->getStatusCode(), [302, 400, 403]);
            self::assertFalse(Browser::redirectedTo($browser, '/account'));
            self::assertFalse(str_contains((string) $browser->getResponse()->getContent(), $account['password']));
            $browser->request('GET', '/account');
            self::assertTrue(Browser::redirectedTo($browser, '/login'));
        }
    }

    public function testUntrustedLoginPostsCannotLogOutOrReplaceAnAuthenticatedSession(): void
    {
        $account = $this->account();
        $submitted = $this->account('-submitted');
        $browser = Browser::create();
        Browser::login($browser, $account['email'], $account['password']);
        self::assertTrue(Browser::redirectedTo($browser, '/account'));
        $sessionId = Browser::session($browser);
        $validToken = Browser::token($browser);
        $otherToken = Browser::token(Browser::create());
        $credentials = ['_username' => $submitted['email'], '_password' => $submitted['password']];
        $cases = [
            'missing CSRF' => [$credentials, 302],
            'invalid CSRF' => [$credentials + ['_csrf_token' => Browser::secret()], 302],
            'other session CSRF' => [$credentials + ['_csrf_token' => $otherToken], 302],
            'array username' => [['_username' => [$submitted['email']], '_password' => $submitted['password'], '_csrf_token' => $otherToken], 400],
            'array password' => [['_username' => $submitted['email'], '_password' => [$submitted['password']], '_csrf_token' => $otherToken], 400],
            'array CSRF' => [$credentials + ['_csrf_token' => [$otherToken]], 400],
            'valid CSRF but wrong password' => [['_username' => $submitted['email'], '_password' => Browser::secret(), '_csrf_token' => $validToken], 302],
        ];
        foreach ($cases as $case => [$parameters, $status]) {
            $browser->request('POST', '/login', $parameters);
            self::assertSame($status, $browser->getResponse()->getStatusCode(), $case);
            if (302 === $status) {
                self::assertTrue(Browser::redirectedTo($browser, '/login'), $case);
            }
            self::assertTrue(hash_equals($sessionId, Browser::session($browser)), $case.' changed the authenticated session ID.');
            $browser->request('GET', '/account');
            self::assertSame(200, $browser->getResponse()->getStatusCode(), $case.' logged the user out.');
            $body = (string) $browser->getResponse()->getContent();
            self::assertTrue(str_contains($body, $account['id']), $case.' lost the original identity.');
            self::assertFalse(str_contains($body, $submitted['id']), $case.' replaced the original identity.');
        }
        self::assertTrue(hash_equals($submitted['hash'], $this->storedHash($submitted['id'])), 'Rejected login attempts must not migrate the submitted account password.');
    }

    public function testCaddyRejectsOversizeAuthenticationPostsBeforePhpIncludingTrailingSlash(): void
    {
        $http = HttpClient::createForBaseUri('http://app:8080', ['max_duration' => 10, 'max_redirects' => 0]);
        foreach (['/login', '/login/', '/login//', '/logout', '/logout/', '/logout//'] as $path) {
            foreach ([false, true] as $chunked) {
                $body = '_username=oversize%40example.test&_password='.str_repeat('x', 17000);
                $options = ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'body' => $chunked ? (static function () use ($body): \Generator { yield $body; })() : $body];
                $response = $http->request('POST', $path, $options);
                self::assertSame($chunked ? 411 : 413, $response->getStatusCode(), 'Ingress must reject oversize framed bodies and require bounded framing for streamed forms.');
                self::assertFalse(isset($response->getHeaders(false)['set-cookie']), 'Oversized bodies must not start a PHP session.');
            }
        }
        foreach (['/index.php/login', '/index.php/login/', '/index.php/logout', '/index.php/logout/'] as $path) {
            foreach ([false, true] as $chunked) {
                $body = '_password='.str_repeat('x', 17000);
                $response = $http->request('POST', $path, ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'body' => $chunked ? (static function () use ($body): \Generator { yield $body; })() : $body]);
                self::assertSame(404, $response->getStatusCode(), 'External front-controller aliases must be rejected before PHP reads the body.');
                self::assertFalse(isset($response->getHeaders(false)['set-cookie']), 'Front-controller aliases must not create sessions.');
            }
        }
        $response = $http->request('POST', '/login', ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'body' => '_password='.str_repeat('x', 16384 - strlen('_password='))]);
        self::assertSame(400, $response->getStatusCode(), 'Exactly 16 KiB reaches the application malformed-input guard.');
    }

    public function testPasswordReplacementAndDeletionInvalidateUuidRefreshedSessions(): void
    {
        $account = $this->account();
        $browser = Browser::create();
        Browser::login($browser, $account['email'], $account['password']);
        self::assertTrue(Browser::redirectedTo($browser, '/account'));
        $current = $this->storedHash($account['id']);
        $replacement = password_hash(Browser::secret(), PASSWORD_BCRYPT, ['cost' => 4]);
        self::assertTrue($this->commands()->dispatch(new UpgradePasswordHashCommand(Uuid::fromString($account['id']), $current, $replacement)));
        self::assertFalse($this->commands()->dispatch(new UpgradePasswordHashCommand(Uuid::fromString($account['id']), $current, $account['hash'])), 'Stale CAS must not overwrite the replacement.');
        self::assertTrue(hash_equals($replacement, $this->storedHash($account['id'])));
        $browser->request('GET', '/account');
        self::assertTrue(Browser::redirectedTo($browser, '/login'));

        $deleted = $this->account('-delete');
        $browser = Browser::create();
        Browser::login($browser, $deleted['email'], $deleted['password']);
        self::assertTrue(Browser::redirectedTo($browser, '/account'));
        $this->connection->executeStatement('DELETE FROM public.authenticating_account WHERE id = ?', [$deleted['id']]);
        $browser->request('GET', '/account');
        self::assertTrue(Browser::redirectedTo($browser, '/login'));
    }

    public function testFailedNativeRehashRollsBackAndClearsAuthentication(): void
    {
        $account = $this->account();
        $previous = $this->account('-previous');
        $browser = Browser::create();
        Browser::login($browser, $previous['email'], $previous['password']);
        self::assertTrue(Browser::redirectedTo($browser, '/account'));
        $previousSession = Browser::session($browser);
        // Real PostgreSQL failure, confined to this isolated account and removed
        // even on assertion failure. No production service/route is replaced.
        $this->connection->executeStatement("CREATE FUNCTION public.authenticating_test_refuse_upgrade() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'authentication fixture refusal'; END \$\$");
        try {
            $this->connection->executeStatement('CREATE TRIGGER authenticating_test_refuse_upgrade BEFORE UPDATE OF password_hash ON public.authenticating_account FOR EACH ROW WHEN (OLD.email = '.$this->connection->quote($account['email']).') EXECUTE FUNCTION public.authenticating_test_refuse_upgrade()');
            try {
                Browser::login($browser, $account['email'], $account['password']);
                self::assertSame(503, $browser->getResponse()->getStatusCode());
                $body = (string) $browser->getResponse()->getContent();
                foreach ([$account['password'], $account['hash'], 'SQLSTATE', 'authentication fixture refusal'] as $private) {
                    self::assertFalse(str_contains($body, $private), 'Rehash failure exposed private details.');
                }
                self::assertTrue(hash_equals($account['hash'], $this->storedHash($account['id'])), 'Failed upgrade changed the committed hash.');
                $browser->request('GET', '/account');
                self::assertTrue(Browser::redirectedTo($browser, '/login'), 'Failed password migration retained authentication.');
                $replay = Browser::create($previousSession);
                $replay->request('GET', '/account');
                self::assertTrue(Browser::redirectedTo($replay, '/login'), 'Late migration failure left the previous session reusable.');
            } finally {
                $this->connection->executeStatement('DROP TRIGGER authenticating_test_refuse_upgrade ON public.authenticating_account');
            }
        } finally {
            $this->connection->executeStatement('DROP FUNCTION public.authenticating_test_refuse_upgrade()');
        }
        $browser = Browser::create();
        Browser::login($browser, $account['email'], $account['password']);
        self::assertTrue(Browser::redirectedTo($browser, '/account'), 'Login did not recover after the database write failure.');
    }

    public function testConcurrentNativeRehashRejectsTheRealLateCasLoserAndClearsItsSession(): void
    {
        $account = $this->account();
        $previous = $this->account('-previous');
        $browsers = [];
        $tokens = [];
        $oldSessions = [];
        for ($i = 0; $i < 2; ++$i) {
            $browser = Browser::create();
            Browser::login($browser, $previous['email'], $previous['password']);
            self::assertTrue(Browser::redirectedTo($browser, '/account'));
            $tokens[] = Browser::token($browser);
            $oldSessions[] = Browser::session($browser);
            $browsers[] = $browser;
        }
        $http = HttpClient::createForBaseUri('http://app:8080', ['max_duration' => 20, 'max_redirects' => 0]);
        $responses = [];
        $this->connection->beginTransaction();
        try {
            // Plain credential SELECTs remain visible, while both native CAS UPDATEs
            // wait on this row. No request can observe the winner's hash beforehand.
            $this->connection->fetchOne('SELECT id FROM public.authenticating_account WHERE id = ? FOR UPDATE', [$account['id']]);
            foreach ($browsers as $index => $browser) {
                $responses[] = $http->request('POST', '/login', ['headers' => ['Cookie' => Browser::cookieName().'='.$oldSessions[$index]], 'body' => ['_username' => $account['email'], '_password' => $account['password'], '_csrf_token' => $tokens[$index]]]);
            }
            $deadline = microtime(true) + 10;
            $blocked = 0;
            do {
                foreach ($http->stream($responses, 0.02) as $chunk) {
                    if (!$chunk->isTimeout()) {
                        self::assertFalse($chunk->isLast(), 'A login completed before both native CAS updates reached the PostgreSQL barrier.');
                    }
                }
                $this->connection->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
                $blocked = $this->connection->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock' AND query LIKE 'UPDATE public.authenticating_account SET password_hash%'");
                if (2 === $blocked) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $blocked, 'Both HTTP logins must reach the real PostgreSQL CAS barrier within ten seconds.');
        } finally {
            $this->connection->rollBack();
        }
        $targets = [];
        foreach ($responses as $index => $response) {
            self::assertSame(302, $response->getStatusCode());
            $headers = $response->getHeaders(false);
            $target = parse_url($headers['location'][0], PHP_URL_PATH);
            self::assertContains($target, ['/account', '/login']);
            $targets[] = $target;
            self::assertFalse(str_contains($response->getContent(false), $account['password']));
            $browsers[$index]->getCookieJar()->updateFromSetCookie($headers['set-cookie'] ?? [], 'http://app:8080/login');
            $browsers[$index]->request('GET', '/account');
            if ('/account' === $target) {
                self::assertSame(200, $browsers[$index]->getResponse()->getStatusCode());
                self::assertTrue(str_contains((string) $browsers[$index]->getResponse()->getContent(), $account['id']));
            } else {
                self::assertTrue(Browser::redirectedTo($browsers[$index], '/login'), 'A late CAS conflict retained authentication.');
                $replay = Browser::create($oldSessions[$index]);
                $replay->request('GET', '/account');
                self::assertTrue(Browser::redirectedTo($replay, '/login'), 'The late CAS loser left its previous authenticated session reusable.');
            }
        }
        sort($targets);
        self::assertSame(['/account', '/login'], $targets);
        $hash = $this->storedHash($account['id']);
        self::assertSame(13, password_get_info($hash)['options']['cost'] ?? null);
        self::assertTrue(password_verify($account['password'], $hash));
        self::assertFalse(hash_equals($account['hash'], $hash));
    }
}
