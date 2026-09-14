<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\Authenticating\Application\RegisterAccount\RegisterAccountCommand;
use App\Tests\Fixtures\Authenticating\Browser;
use App\Tests\Fixtures\Authenticating\JwtHttp;
use App\Tests\Fixtures\Authenticating\JwtState;
use App\Tests\Fixtures\Authenticating\JwtTokens;
use Symfony\Component\Uid\Uuid;

/** Filter each phase explicitly; the test runner owns app/key/database transitions. */
final class AuthenticatingJwtPersistenceTest extends AuthenticatingTestCase
{
    public function testSeedTokenForRestartRotationAndOutages(): void
    {
        $email = 'jwt-persistence-'.bin2hex(random_bytes(8)).'@example.test';
        $password = Browser::secret();
        $id = $this->commands()->dispatch(new RegisterAccountCommand($email, $password));
        self::assertInstanceOf(Uuid::class, $id);
        $token = JwtHttp::token(JwtHttp::login($email, $password));
        JwtHttp::identity(JwtHttp::me($token), $id->toRfc4122(), $email);
        $invalid = JwtTokens::mint($id->toRfc4122(), ['iss' => 'urn:donmario:wrong-project:test']);
        JwtState::save(['id' => $id->toRfc4122(), 'email' => $email, 'password' => $password, 'hash' => $this->storedHash($id->toRfc4122()), 'token' => $token, 'original_token' => $token, 'invalid_token' => $invalid]);
    }

    public function testTokenSurvivesAppRecreationAndCacheClear(): void
    {
        $state = JwtState::load();
        JwtHttp::identity(JwtHttp::me($state['token']), $state['id'], $state['email']);
        self::assertTrue(hash_equals($state['hash'], $this->storedHash($state['id'])));
    }

    public function testRotationOverlapAcceptsOldAndNewTokens(): void
    {
        $state = JwtState::load();
        self::assertGreaterThan(time(), JwtTokens::expiresAt($state['original_token']), 'Rotation must exercise an unexpired old token.');
        JwtHttp::identity(JwtHttp::me($state['original_token']), $state['id'], $state['email']);
        $new = JwtHttp::token(JwtHttp::login($state['email'], $state['password']));
        JwtHttp::identity(JwtHttp::me($new), $state['id'], $state['email']);
        self::assertFalse(hash_equals($state['original_token'], $new));
        JwtState::save(array_replace($state, ['token' => $new, 'invalid_token' => JwtTokens::mint($state['id'], ['iss' => 'urn:donmario:wrong-project:test'])]));
    }

    public function testRetiringOldKeyRejectsOldTokenButKeepsNewToken(): void
    {
        $state = JwtState::load();
        self::assertGreaterThan(time(), JwtTokens::expiresAt($state['original_token']), 'Expiry must not masquerade as old-key retirement.');
        JwtHttp::denied(JwtHttp::me($state['original_token']), secrets: [$state['original_token']]);
        JwtHttp::identity(JwtHttp::me($state['token']), $state['id'], $state['email']);
    }

    public function testDatabaseAndSigningRecoveryKeepIdentityAndAllowNewIssuance(): void
    {
        $state = JwtState::load();
        JwtHttp::identity(JwtHttp::me($state['token']), $state['id'], $state['email']);
        self::assertTrue(hash_equals($state['hash'], $this->storedHash($state['id'])));
        $new = JwtHttp::token(JwtHttp::login($state['email'], $state['password']));
        JwtHttp::identity(JwtHttp::me($new), $state['id'], $state['email']);
        JwtState::save(array_replace($state, ['token' => $new]));
    }

    public function testEmergencyRotationRejectsUnexpiredReplayAndAllowsNewIssuance(): void
    {
        $state = JwtState::load();
        self::assertGreaterThan(time(), JwtTokens::expiresAt($state['token']), 'Emergency rejection must not be caused by expiry.');
        JwtHttp::denied(JwtHttp::me($state['token']), secrets: [$state['token']]);
        JwtHttp::denied(JwtHttp::me($state['original_token']), secrets: [$state['original_token']]);
        $new = JwtHttp::token(JwtHttp::login($state['email'], $state['password']));
        JwtHttp::identity(JwtHttp::me($new), $state['id'], $state['email']);
        JwtState::save(array_replace($state, ['token' => $new, 'invalid_token' => JwtTokens::mint($state['id'], ['iss' => 'urn:donmario:wrong-project:test'])]));
    }
}
