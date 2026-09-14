<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\Authenticating\Application\RegisterAccount\RegisterAccountCommand;
use App\Tests\Fixtures\Authenticating\Browser;
use App\Tests\Fixtures\Authenticating\State;
use Symfony\Component\Uid\Uuid;

final class AuthenticatingPersistenceTest extends AuthenticatingTestCase
{
    public function testSeedPersistentSessionAndLimiter(): void
    {
        $password = Browser::secret();
        $email = 'auth-persistence-'.bin2hex(random_bytes(8)).'@example.test';
        $id = $this->commands()->dispatch(new RegisterAccountCommand($email, $password));
        self::assertInstanceOf(Uuid::class, $id);
        $browser = Browser::create();
        Browser::login($browser, $email, $password);
        self::assertTrue(Browser::redirectedTo($browser, '/account'));
        $browser->request('GET', '/account');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $hash = $this->storedHash($id->toRfc4122());
        for ($i = 0; $i < 5; ++$i) {
            $failed = Browser::create();
            Browser::login($failed, 0 === $i % 2 ? strtoupper($email) : $email, Browser::secret());
            self::assertTrue(Browser::redirectedTo($failed, '/login'));
        }
        $csrf = Browser::token($browser, '/account');
        State::save(['id' => $id->toRfc4122(), 'email' => $email, 'password' => $password, 'hash' => $hash, 'session' => Browser::session($browser), 'csrf' => $csrf]);
    }

    public function testSessionAndCountersSurviveAppRecreationAndCacheClear(): void
    {
        $state = State::load();
        $browser = Browser::create($state['session']);
        $browser->request('GET', '/account');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertTrue(str_contains((string) $browser->getResponse()->getContent(), $state['id']));
        $fresh = Browser::create();
        Browser::login($fresh, strtoupper($state['email']), $state['password']);
        self::assertTrue(Browser::redirectedTo($fresh, '/login'), 'Fresh cookies must not bypass persisted normalized-identifier failures.');
    }

    public function testExpiredLimiterAndDatabaseRecoveryKeepTheSameAccount(): void
    {
        $state = State::load();
        self::assertSame($state['email'], $this->connection->fetchOne('SELECT email FROM public.authenticating_account WHERE id = ?', [$state['id']]));
        self::assertTrue(hash_equals($state['hash'], $this->storedHash($state['id'])));
        $browser = Browser::create();
        Browser::login($browser, $state['email'], $state['password']);
        self::assertTrue(Browser::redirectedTo($browser, '/account'));
        $browser->request('GET', '/account');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertTrue(str_contains((string) $browser->getResponse()->getContent(), $state['id']));
    }
}
