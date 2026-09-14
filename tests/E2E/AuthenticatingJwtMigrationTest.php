<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Fixtures\Authenticating\Browser;
use App\Tests\Fixtures\Authenticating\JwtHttp;
use Symfony\Component\HttpClient\HttpClient;

final class AuthenticatingJwtMigrationTest extends AuthenticatingTestCase
{
    public function testLateDatabaseWriteFailureRollsBackAndDeliversNoJwtOrSessionMutation(): void
    {
        $account = $this->account();
        $web = $this->account('-web');
        $browser = Browser::create();
        Browser::login($browser, $web['email'], $web['password']);
        self::assertTrue(Browser::redirectedTo($browser, '/account'));
        $session = Browser::session($browser);
        $this->connection->executeStatement("CREATE FUNCTION public.authenticating_jwt_refuse_upgrade() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'JWT fixture write refusal'; END \$\$");
        try {
            $this->connection->executeStatement('CREATE TRIGGER authenticating_jwt_refuse_upgrade BEFORE UPDATE OF password_hash ON public.authenticating_account FOR EACH ROW WHEN (OLD.email = '.$this->connection->quote($account['email']).') EXECUTE FUNCTION public.authenticating_jwt_refuse_upgrade()');
            try {
                $response = JwtHttp::request('POST', '/api/login', ['headers' => ['Cookie' => Browser::cookieName().'='.$session], 'json' => ['email' => $account['email'], 'password' => $account['password']]]);
                JwtHttp::denied($response, 503, [$account['password'], $account['hash'], 'JWT fixture write refusal']);
                self::assertTrue(hash_equals($account['hash'], $this->storedHash($account['id'])), 'Failed migration changed the committed hash.');
                $browser->request('GET', '/account');
                self::assertSame(200, $browser->getResponse()->getStatusCode(), 'Stateless API failure invalidated the independent web session.');
                self::assertTrue(str_contains((string) $browser->getResponse()->getContent(), $web['id']));
                self::assertTrue(hash_equals($session, Browser::session($browser)));
            } finally {
                $this->connection->executeStatement('DROP TRIGGER authenticating_jwt_refuse_upgrade ON public.authenticating_account');
            }
        } finally {
            $this->connection->executeStatement('DROP FUNCTION public.authenticating_jwt_refuse_upgrade()');
        }
        $token = JwtHttp::token(JwtHttp::login($account['email'], $account['password']));
        JwtHttp::identity(JwtHttp::me($token), $account['id'], $account['email']);
    }

    public function testConcurrentNativeMigrationOnlyIssuesJwtToTheCommittedCasWinner(): void
    {
        $account = $this->account();
        $http = HttpClient::createForBaseUri(getenv('E2E_BASE_URL') ?: 'http://app:8080', ['max_duration' => 25, 'max_redirects' => 0]);
        $responses = [];
        $this->connection->beginTransaction();
        try {
            $this->connection->fetchOne('SELECT id FROM public.authenticating_account WHERE id = ? FOR UPDATE', [$account['id']]);
            for ($i = 0; $i < 2; ++$i) {
                $responses[] = $http->request('POST', '/api/login', ['json' => ['email' => $account['email'], 'password' => $account['password']]]);
            }
            $deadline = microtime(true) + 10;
            $blocked = 0;
            do {
                foreach ($http->stream($responses, 0.02) as $chunk) {
                    if (!$chunk->isTimeout()) {
                        self::assertFalse($chunk->isLast(), 'Issuance completed before both migrations reached the real SQL barrier.');
                    }
                }
                $this->connection->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
                $blocked = $this->connection->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock' AND query LIKE 'UPDATE public.authenticating_account SET password_hash%'");
                if (2 === $blocked) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $blocked, 'Both native migrations must wait on PostgreSQL before the barrier is released.');
        } finally {
            $this->connection->rollBack();
        }
        $statuses = [];
        foreach ($responses as $response) {
            $status = $response->getStatusCode();
            $statuses[] = $status;
            if (200 === $status) {
                $token = JwtHttp::token($response);
                JwtHttp::identity(JwtHttp::me($token), $account['id'], $account['email']);
            } else {
                JwtHttp::denied($response, 401, [$account['password'], $account['hash']]);
            }
        }
        sort($statuses);
        self::assertSame([200, 401], $statuses, 'Exactly one CAS winner may receive a JWT; the loser must not authenticate.');
        $hash = $this->storedHash($account['id']);
        self::assertFalse(hash_equals($account['hash'], $hash));
        self::assertSame(13, password_get_info($hash)['options']['cost'] ?? null);
        self::assertTrue(password_verify($account['password'], $hash));
    }
}
