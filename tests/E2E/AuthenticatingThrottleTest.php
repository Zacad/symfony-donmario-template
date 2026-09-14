<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Fixtures\Authenticating\Browser;
use Symfony\Component\HttpClient\HttpClient;

/** Run inside app against loopback: its IP budget is independent from the runner's. */
final class AuthenticatingThrottleTest extends AuthenticatingTestCase
{
    public function testNativeLocalAndGlobalFailureBudgetsWithConcurrentAccountingAndRealExpiry(): void
    {
        self::assertSame('http://localhost:8080', getenv('E2E_BASE_URL'));
        $account = $this->account();
        $started = time();
        for ($i = 0; $i < 4; ++$i) {
            $browser = Browser::create();
            Browser::login($browser, 0 === $i % 2 ? strtoupper($account['email']) : $account['email'], Browser::secret());
            self::assertTrue(Browser::redirectedTo($browser, '/login'));
        }
        $browser = Browser::create();
        Browser::login($browser, $account['email'], $account['password']);
        self::assertTrue(Browser::redirectedTo($browser, '/account'), 'Four failures must leave one local attempt.');
        $browser = Browser::create();
        Browser::login($browser, $account['email'], Browser::secret());
        self::assertTrue(Browser::redirectedTo($browser, '/login'));
        $browser = Browser::create();
        Browser::login($browser, strtoupper($account['email']), $account['password']);
        self::assertTrue(Browser::redirectedTo($browser, '/login'), 'Five failures must block the correct password with fresh cookies.');

        // Six failures so far (including the rejected attempt). Concurrent failures
        // exercise locked counter updates, not a claimed in-flight password-check cap.
        $http = HttpClient::createForBaseUri('http://localhost:8080', ['max_duration' => 30, 'max_redirects' => 0]);
        $responses = [];
        for ($i = 0; $i < 4; ++$i) {
            $browser = Browser::create();
            $token = Browser::token($browser);
            $responses[] = $http->request('POST', '/login', ['headers' => ['Cookie' => Browser::cookieName().'='.Browser::session($browser)], 'body' => ['_username' => $this->prefix.'-parallel'.$i.'@example.test', '_password' => Browser::secret(), '_csrf_token' => $token]]);
        }
        foreach ($responses as $response) {
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/login', parse_url($response->getHeaders(false)['location'][0], PHP_URL_PATH));
        }
        for ($i = 0; $i < 14; ++$i) {
            $browser = Browser::create();
            Browser::login($browser, $this->prefix.'-global'.$i.'@example.test', Browser::secret());
            self::assertTrue(Browser::redirectedTo($browser, '/login'));
        }
        $global = $this->account('-global-valid');
        $browser = Browser::create();
        Browser::login($browser, $global['email'], $global['password']);
        self::assertTrue(Browser::redirectedTo($browser, '/account'), 'Twenty-four global failures must leave one attempt.');
        $browser = Browser::create();
        Browser::login($browser, $this->prefix.'-last@example.test', Browser::secret());
        self::assertTrue(Browser::redirectedTo($browser, '/login'));
        $browser = Browser::create();
        Browser::login($browser, $global['email'], $global['password']);
        self::assertTrue(Browser::redirectedTo($browser, '/login'), 'Twenty-five global failures must block a different valid identifier.');

        // One bounded real wait proves both intervals without clock mocks or a reset
        // endpoint. The same wait expires the separate runner restart-test budget.
        // Sliding windows retain a weighted previous interval. Include enough of
        // the next window for the saturated 25-failure budget to leave tokens.
        $remaining = $started + 332 - time();
        self::assertGreaterThanOrEqual(0, $remaining, 'Throttle scenario exceeded its bounded real-time window.');
        if ($remaining > 0) {
            sleep($remaining);
        }
        foreach ([$account, $global] as $recovered) {
            $browser = Browser::create();
            Browser::login($browser, $recovered['email'], $recovered['password']);
            self::assertTrue(Browser::redirectedTo($browser, '/account'), 'Native counters did not recover after real expiry.');
        }
    }
}
