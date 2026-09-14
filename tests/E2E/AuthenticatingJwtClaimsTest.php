<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Fixtures\Authenticating\JwtHttp;
use App\Tests\Fixtures\Authenticating\JwtTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

/** Run in the test app or isolated runner with its actual read-only test key mount. */
final class AuthenticatingJwtClaimsTest extends AuthenticatingTestCase
{
    public function testRuntimeKeyIsRsa3072AndIndependentlyVerifiesNativeIssuedSignature(): void
    {
        $account = $this->account();
        $token = JwtHttp::token(JwtHttp::login($account['email'], $account['password']));
        $public = @file_get_contents('/app/var/jwt/active/public.pem');
        self::assertTrue(is_string($public), 'Claim verification requires the isolated runtime key volume.');
        $key = openssl_pkey_get_public($public);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertTrue(is_array($details) && OPENSSL_KEYTYPE_RSA === $details['type'] && 3072 === $details['bits']);
        [$header, $payload, $signature] = explode('.', $token);
        self::assertSame(1, openssl_verify($header.'.'.$payload, (string) base64_decode(strtr($signature, '-_', '+/')), $key, OPENSSL_ALGO_SHA256));
        // This positive control proves invalid-claim tests use a trusted key and
        // the live account, rather than passing because every fixture is invalid.
        JwtHttp::identity(JwtHttp::me(JwtTokens::mint($account['id'])), $account['id'], $account['email']);
    }

    /** @param array<string, mixed> $claims
     * @param list<string> $remove
     */
    #[DataProvider('invalidClaims')]
    public function testSignedInvalidClaimsAreRejected(string $mode, array $claims, array $remove): void
    {
        $account = $this->account();
        $now = time();
        foreach ($claims as $name => $value) {
            if (is_string($value) && str_starts_with($value, 'now:')) {
                $claims[$name] = $now + (int) substr($value, 4);
            } elseif ('issuer:dev' === $value) {
                $claims[$name] = 'urn:donmario:'.(getenv('APP_INSTANCE_ID') ?: 'local').':dev';
            }
        }
        $claims += ['iat' => $now, 'nbf' => $now, 'exp' => $now + 900];
        $token = JwtTokens::mint($account['id'], $claims, $remove, $mode);
        JwtHttp::denied(JwtHttp::me($token), secrets: [$token, $account['hash']]);
    }

    /** @return iterable<string, array{string, array<string, mixed>, list<string>}> */
    public static function invalidClaims(): iterable
    {
        foreach (['sub', 'iss', 'aud', 'iat', 'nbf', 'exp'] as $claim) {
            yield 'missing '.$claim => ['RS256', [], [$claim]];
        }
        yield 'untrusted signing key' => ['wrong-key', [], []];
        yield 'unsigned none algorithm' => ['none', [], []];
        yield 'RSA public key HMAC confusion' => ['HS256', [], []];
        yield 'unexpected RSA algorithm' => ['RS512', [], []];
        yield 'other project issuer' => ['RS256', ['iss' => 'urn:donmario:other-project:test'], []];
        yield 'other environment issuer' => ['RS256', ['iss' => 'issuer:dev'], []];
        yield 'other API audience' => ['RS256', ['aud' => 'urn:donmario:other-project:test:api'], []];
        yield 'empty audience' => ['RS256', ['aud' => []], []];
        yield 'non-UUID subject' => ['RS256', ['sub' => 'someone@example.test'], []];
        yield 'object subject' => ['RS256', ['sub' => ['id' => 'invalid']], []];
        yield 'unknown UUID' => ['RS256', ['sub' => 'a3a750be-1fda-451e-9bfc-26c111a34ac1'], []];
        yield 'expired' => ['RS256', ['iat' => 'now:-901', 'nbf' => 'now:-901', 'exp' => 'now:-1'], []];
        yield 'issued in future' => ['RS256', ['iat' => 'now:120', 'nbf' => 'now:120', 'exp' => 'now:1020'], []];
        yield 'not yet valid' => ['RS256', ['nbf' => 'now:120'], []];
        yield 'excessive lifetime' => ['RS256', ['exp' => 'now:901'], []];
        yield 'expiry before issuance' => ['RS256', ['exp' => 'now:-1'], []];
        yield 'nbf before issuance' => ['RS256', ['nbf' => 'now:-10'], []];
        foreach (['iat', 'nbf', 'exp'] as $claim) {
            foreach (['null' => null, 'array' => [], 'boolean' => true] as $type => $value) {
                yield $type.' '.$claim => ['RS256', [$claim => $value], []];
            }
        }
        yield 'non-numeric expiry' => ['RS256', ['exp' => 'not-a-date'], []];
        yield 'unexpected permission claim' => ['RS256', ['roles' => ['ROLE_ADMIN']], []];
    }

    public function testMalformedAndTamperedTokensAreRejectedWithoutReflectingThem(): void
    {
        $account = $this->account();
        $token = JwtTokens::mint($account['id']);
        [$header, $payload, $signature] = explode('.', $token);
        $changedSignature = ('A' === $signature[0] ? 'B' : 'A').substr($signature, 1);
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue(is_array($claims));
        $claims['sub'] = Uuid::v7()->toRfc4122();
        $changedPayload = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        foreach (['not-a-jwt', 'a.b.c', $header.'.'.$payload, $token.'.extra', $header.'.'.$payload.'.'.$changedSignature, $header.'.'.$changedPayload.'.'.$signature] as $invalid) {
            JwtHttp::denied(JwtHttp::me($invalid), secrets: [$invalid]);
        }
    }

    public function testRealShortLivedSignedTokenExpiresAtZeroSkewAndCredentialsRenewAccess(): void
    {
        $account = $this->account();
        $now = time();
        // Issue 896 seconds ago, so this ordinary 900-second lifetime has four
        // seconds remaining. The production TTL and wall clock are not mocked.
        $expires = $now + 4;
        $token = JwtTokens::mint($account['id'], ['iat' => $expires - 900, 'nbf' => $expires - 900, 'exp' => $expires]);
        JwtHttp::identity(JwtHttp::me($token), $account['id'], $account['email']);
        self::assertLessThan($expires, time(), 'Expiry positive control missed its bounded live window.');
        while (time() < $expires) {
            usleep(20000);
        }
        JwtHttp::denied(JwtHttp::me($token), secrets: [$token]);
        $renewed = JwtHttp::token(JwtHttp::login($account['email'], $account['password']));
        JwtHttp::identity(JwtHttp::me($renewed), $account['id'], $account['email']);
    }
}
