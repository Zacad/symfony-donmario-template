<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\Authenticating\Application\RegisterAccount\RegisterAccountCommand;
use App\Tests\Fixtures\Authenticating\Browser;
use App\Tests\Fixtures\Authenticating\JwtHttp;
use App\Tests\Fixtures\Authenticating\JwtState;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Test-app only. A real bound 127.0.0.2 source separates this budget from other phases. */
final class AuthenticatingJwtThrottleTest extends AuthenticatingTestCase
{
    public function testSeedCombinedNormalizedWebApiFailureBudgets(): void
    {
        self::assertSame('http://127.0.0.1:8080', getenv('E2E_BASE_URL'));
        $email = 'jwt-throttle-'.bin2hex(random_bytes(8)).'@example.test';
        $password = Browser::secret();
        $id = $this->dispatch(new RegisterAccountCommand($email, $password));
        self::assertInstanceOf(Uuid::class, $id);
        for ($i = 0; $i < 4; ++$i) {
            if (0 === $i % 2) {
                $browser = $this->browser();
                Browser::login($browser, ' '.strtoupper($email).' ', Browser::secret());
                self::assertTrue(Browser::redirectedTo($browser, '/login'));
            } else {
                JwtHttp::denied($this->login("\t".strtoupper($email).' ', Browser::secret()));
            }
        }
        $token = JwtHttp::token($this->login($email, $password));
        JwtHttp::identity(JwtHttp::me($token), $id->toRfc4122(), $email);
        JwtHttp::denied($this->login($email, Browser::secret()));
        JwtHttp::denied($this->login(' '.strtoupper($email).' ', $password), 429);
        $browser = $this->browser();
        Browser::login($browser, $email, $password);
        self::assertTrue(Browser::redirectedTo($browser, '/login'), 'JSON failures must also exhaust the web limiter.');
        JwtState::save(['id' => $id->toRfc4122(), 'email' => $email, 'password' => $password, 'token' => $token, 'seeded_at' => (string) time()], 'throttle');
    }

    public function testCombinedLimiterSurvivesAppRecreationAndCacheClear(): void
    {
        $state = JwtState::load('throttle');
        self::assertLessThan(55, time() - (int) $state['seeded_at'], 'Run the restart phase before the native local window expires.');
        JwtHttp::denied($this->login(strtoupper($state['email']), $state['password']), 429);
        $browser = $this->browser();
        Browser::login($browser, $state['email'], $state['password']);
        self::assertTrue(Browser::redirectedTo($browser, '/login'), 'Cache clear or recreation reset the shared failure budget.');
        JwtHttp::identity(JwtHttp::me($state['token']), $state['id'], $state['email']);
    }

    public function testCombinedLimiterRecoversAfterExistingRealExpiryPhase(): void
    {
        $state = JwtState::load('throttle');
        self::assertGreaterThanOrEqual(130, time() - (int) $state['seeded_at'], 'Schedule after the existing web throttle real-time expiry wait.');
        $token = JwtHttp::token($this->login($state['email'], $state['password']));
        JwtHttp::identity(JwtHttp::me($token), $state['id'], $state['email']);
        $browser = $this->browser();
        Browser::login($browser, $state['email'], $state['password']);
        self::assertTrue(Browser::redirectedTo($browser, '/account'));
        $this->connection->executeStatement('DELETE FROM public.authenticating_account WHERE id = ?', [$state['id']]);
    }

    private function browser(): HttpBrowser
    {
        $browser = new HttpBrowser(HttpClient::createForBaseUri('http://127.0.0.1:8080', ['bindto' => '127.0.0.2', 'max_duration' => 15]));
        $browser->setServerParameter('HTTP_HOST', '127.0.0.1:8080');
        $browser->followRedirects(false);

        return $browser;
    }

    private function login(string $email, #[\SensitiveParameter] string $password): ResponseInterface
    {
        return JwtHttp::request('POST', '/api/login', ['bindto' => '127.0.0.2', 'json' => ['email' => $email, 'password' => $password]]);
    }
}
