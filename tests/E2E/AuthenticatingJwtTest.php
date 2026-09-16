<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\Authenticating\Application\UpgradePasswordHash\UpgradePasswordHashCommand;
use App\Tests\Fixtures\Authenticating\Browser;
use App\Tests\Fixtures\Authenticating\JwtHttp;
use Symfony\Component\Uid\Uuid;

final class AuthenticatingJwtTest extends AuthenticatingTestCase
{
    public function testCliProvisionJsonLoginAndMinimalLiveIdentity(): void
    {
        $email = $this->prefix.'@example.test';
        $password = Browser::secret();
        $process = $this->provision('  '.strtoupper($email).'  ', $password."\n");
        $this->safeOutput($process, $password);
        self::assertTrue($process->isSuccessful(), 'CLI provisioning failed; private output withheld.');
        $id = $this->connection->fetchOne('SELECT id FROM public.authenticating_account WHERE email = ?', [$email]);
        self::assertIsString($id);
        $before = time();
        $token = JwtHttp::token(JwtHttp::login("\t ".strtoupper($email).' ', $password));
        $parts = explode('.', $token);
        $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        self::assertTrue(is_array($header) && 'RS256' === ($header['alg'] ?? null) && 'JWT' === ($header['typ'] ?? null));
        self::assertTrue(is_array($claims), 'JWT payload is not an object.');
        $keys = array_keys($claims);
        sort($keys);
        self::assertSame(['aud', 'exp', 'iat', 'iss', 'nbf', 'sub'], $keys, 'JWT must not encode credentials, email or permissions.');
        $issuer = 'urn:donmario:'.(getenv('APP_INSTANCE_ID') ?: 'local').':test';
        self::assertTrue($id === $claims['sub']);
        self::assertTrue($issuer === $claims['iss']);
        self::assertTrue($issuer.':api' === $claims['aud'] || [$issuer.':api'] === $claims['aud']);
        self::assertTrue(is_int($claims['iat']) && $claims['iat'] >= $before && $claims['iat'] <= time());
        self::assertTrue($claims['nbf'] === $claims['iat'] && $claims['iat'] + 900 === $claims['exp']);
        JwtHttp::identity(JwtHttp::me($token), $id, $email);
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            JwtHttp::denied(JwtHttp::request($method, '/api/me', ['headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']]), 405, [$token]);
        }
        JwtHttp::denied(JwtHttp::request('GET', '/api/me'));
    }

    public function testJsonPasswordSpacesArePreservedRatherThanNormalized(): void
    {
        $password = '  '.Browser::secret().'  ';
        $account = $this->account(password: $password);
        JwtHttp::denied(JwtHttp::login($account['email'], trim($password)), secrets: [$password]);
        $token = JwtHttp::token(JwtHttp::login(' '.strtoupper($account['email']).' ', $password));
        JwtHttp::identity(JwtHttp::me($token), $account['id'], $account['email']);
    }

    public function testNativeJsonLoginRehashCompletesBeforeJwtIssuance(): void
    {
        $account = $this->account();
        $token = JwtHttp::token(JwtHttp::login($account['email'], $account['password']));
        $hash = $this->storedHash($account['id']);
        self::assertFalse(hash_equals($account['hash'], $hash));
        self::assertSame(13, password_get_info($hash)['options']['cost'] ?? null);
        self::assertTrue(password_verify($account['password'], $hash));
        JwtHttp::identity(JwtHttp::me($token), $account['id'], $account['email']);
        JwtHttp::identity(JwtHttp::me($token), $account['id'], $account['email']);
        self::assertTrue(hash_equals($hash, $this->storedHash($account['id'])), 'Bearer lookup must not hash passwords.');
    }

    public function testUnknownAndWrongPasswordAreIndistinguishableWithoutHashMigration(): void
    {
        $account = $this->account();
        $bodies = [];
        foreach ([$account['email'], $this->prefix.'-unknown@example.test'] as $email) {
            $wrong = Browser::secret();
            $response = JwtHttp::login($email, $wrong);
            JwtHttp::denied($response, secrets: [$wrong, $account['hash'], $email]);
            $bodies[] = $response->getContent(false);
        }
        self::assertTrue(hash_equals($bodies[0], $bodies[1]), 'Credential failures reveal whether the account exists.');
        self::assertTrue(hash_equals($account['hash'], $this->storedHash($account['id'])));
    }

    public function testWebCookieAndBearerIdentitiesStayIndependentIncludingLogout(): void
    {
        $web = $this->account('-web');
        $api = $this->account('-api');
        $browser = Browser::create();
        Browser::login($browser, $web['email'], $web['password']);
        self::assertTrue(Browser::redirectedTo($browser, '/account'));
        $session = Browser::session($browser);
        $cookie = Browser::cookieName().'='.$session;
        $token = JwtHttp::token(JwtHttp::request('POST', '/api/login', ['headers' => ['Cookie' => $cookie], 'json' => ['email' => $api['email'], 'password' => $api['password']]]));
        JwtHttp::denied(JwtHttp::request('GET', '/api/me', ['headers' => ['Cookie' => $cookie]]));
        JwtHttp::identity(JwtHttp::request('GET', '/api/me', ['headers' => ['Cookie' => $cookie, 'Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']]), $api['id'], $api['email']);
        JwtHttp::denied(JwtHttp::request('GET', '/api/me', ['headers' => ['Cookie' => $cookie, 'Authorization' => 'Bearer malformed']]));
        $browser->request('GET', '/account');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertTrue(str_contains((string) $browser->getResponse()->getContent(), $web['id']));
        self::assertFalse(str_contains((string) $browser->getResponse()->getContent(), $api['id']));
        self::assertTrue(hash_equals($session, Browser::session($browser)));
        $webWithoutCookie = JwtHttp::request('GET', '/account', ['headers' => ['Authorization' => 'Bearer '.$token]]);
        self::assertSame(302, $webWithoutCookie->getStatusCode());
        self::assertSame('/login', parse_url($webWithoutCookie->getHeaders(false)['location'][0], PHP_URL_PATH));
        $logout = Browser::token($browser, '/account');
        $browser->request('POST', '/logout', ['_csrf_token' => $logout]);
        self::assertTrue(Browser::redirectedTo($browser, '/login'));
        JwtHttp::identity(JwtHttp::me($token), $api['id'], $api['email']);
    }

    public function testLiveUuidLookupTracksEmailAndDeletionButPasswordChangeDoesNotRevokeJwt(): void
    {
        $account = $this->account();
        $token = JwtHttp::token(JwtHttp::login($account['email'], $account['password']));
        $renamed = $this->prefix.'-renamed@example.test';
        $this->connection->executeStatement('UPDATE public.authenticating_account SET email = ? WHERE id = ?', [$renamed, $account['id']]);
        JwtHttp::identity(JwtHttp::me($token), $account['id'], $renamed);
        $current = $this->storedHash($account['id']);
        $replacementPassword = Browser::secret();
        $replacementHash = password_hash($replacementPassword, PASSWORD_BCRYPT, ['cost' => 4]);
        self::assertTrue($this->dispatch(new UpgradePasswordHashCommand(Uuid::fromString($account['id']), $current, $replacementHash)));
        self::assertFalse($this->dispatch(new UpgradePasswordHashCommand(Uuid::fromString($account['id']), $current, $account['hash'])));
        JwtHttp::identity(JwtHttp::me($token), $account['id'], $renamed);
        JwtHttp::denied(JwtHttp::login($renamed, $account['password']));
        $renewed = JwtHttp::token(JwtHttp::login($renamed, $replacementPassword));
        JwtHttp::identity(JwtHttp::me($renewed), $account['id'], $renamed);
        $this->connection->executeStatement('DELETE FROM public.authenticating_account WHERE id = ?', [$account['id']]);
        JwtHttp::denied(JwtHttp::me($token), secrets: [$token, $account['hash']]);
        JwtHttp::denied(JwtHttp::me($renewed), secrets: [$renewed]);
        $recreated = $this->account('-renamed');
        self::assertFalse($recreated['id'] === $account['id']);
        JwtHttp::denied(JwtHttp::me($token), secrets: [$token]);
    }

    public function testOnlyAuthorizationHeaderAuthenticatesAndOversizedHeaderIsRejected(): void
    {
        $account = $this->account();
        $token = JwtHttp::token(JwtHttp::login($account['email'], $account['password']));
        foreach (['BEARER', 'access_token', 'token'] as $cookie) {
            JwtHttp::denied(JwtHttp::request('GET', '/api/me', ['headers' => ['Cookie' => $cookie.'='.$token]]), secrets: [$token]);
        }
        foreach (['access_token', 'bearer', 'token'] as $parameter) {
            JwtHttp::denied(JwtHttp::request('GET', '/api/me', ['query' => [$parameter => $token]]), secrets: [$token]);
            JwtHttp::denied(JwtHttp::request('GET', '/api/me', ['json' => [$parameter => $token]]), secrets: [$token]);
        }
        foreach (['Basic '.$token, $token, 'Bearer malformed', 'Bearer '.str_repeat('a', 8193)] as $header) {
            JwtHttp::denied(JwtHttp::request('GET', '/api/me', ['headers' => ['Authorization' => $header]]), secrets: [$token]);
        }
        JwtHttp::identity(JwtHttp::me($token), $account['id'], $account['email']);
    }

    public function testPublicStaticOpenApiDocumentsLoginAndBearerIdentity(): void
    {
        $response = JwtHttp::request('GET', '/api/docs.json');
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse(isset($response->getHeaders(false)['set-cookie']));
        $schema = JwtHttp::json($response);
        self::assertTrue(is_string($schema['openapi'] ?? null));
        $login = self::documentNode($schema, 'paths', '/api/login', 'post');
        $me = self::documentNode($schema, 'paths', '/api/me', 'get');
        $requestSchema = self::documentNode($login, 'requestBody', 'content', 'application/json', 'schema');
        self::assertEqualsCanonicalizing(['email', 'password'], $requestSchema['required'] ?? []);
        $responseSchema = self::documentNode($login, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertEqualsCanonicalizing(['access_token', 'token_type', 'expires_in'], $responseSchema['required'] ?? []);
        self::documentNode($me, 'responses', '200');
        self::assertTrue([] === ($login['security'] ?? []));
        self::assertNotEmpty($me['security'] ?? $schema['security'] ?? []);
        $schemes = self::documentNode($schema, 'components', 'securitySchemes');
        self::assertTrue([] !== array_filter($schemes, static fn (mixed $scheme): bool => is_array($scheme) && 'http' === ($scheme['type'] ?? null) && is_string($scheme['scheme'] ?? null) && 'bearer' === strtolower($scheme['scheme'])));
        $withBadToken = JwtHttp::request('GET', '/api/docs.json', ['headers' => ['Authorization' => 'Bearer malformed']]);
        self::assertSame(200, $withBadToken->getStatusCode(), 'Public schema must not depend on authentication.');
        self::assertTrue(hash_equals($response->getContent(false), $withBadToken->getContent(false)));
    }

    /** @param array<array-key, mixed> $document
     * @return array<array-key, mixed>
     */
    private static function documentNode(array $document, string ...$path): array
    {
        $value = $document;
        foreach ($path as $key) {
            self::assertTrue(is_array($value) && array_key_exists($key, $value), 'OpenAPI document is missing a required node.');
            $value = $value[$key];
        }
        self::assertTrue(is_array($value), 'OpenAPI node must be an object or array.');

        return $value;
    }
}
