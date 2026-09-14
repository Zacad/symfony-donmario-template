<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authenticating\Domain\AccountCredentials;
use App\Module\Authenticating\Domain\AccountRepository;
use App\Module\Authenticating\Infrastructure\Framework\Lexik\EventListener\JwtClaimsListener;
use App\Module\Authenticating\Infrastructure\Framework\Lexik\EventListener\JwtFailureListener;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\ApiAuthenticationFailureHandler;
use App\Module\Authenticating\UI\Http\Security\BearerAccountUserProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\LcobucciJWTEncoder;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\JWTAuthenticator;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWSProvider\LcobucciJWSProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTManager;
use Lexik\Bundle\JWTAuthenticationBundle\Services\KeyLoader\KeyLoaderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\AuthorizationHeaderTokenExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;

final class AuthenticatingJwtTest extends TestCase
{
    private const string ISSUER = 'urn:donmario:unit:test';
    private const string AUDIENCE = self::ISSUER.':api';
    private static string $private;
    private static string $public;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $private = '';
        if (false === $key || !openssl_pkey_export($key, $private)) {
            throw new \RuntimeException('Cannot generate disposable verification key.');
        }
        $details = openssl_pkey_get_details($key);
        if (false === $details) {
            throw new \RuntimeException('Cannot inspect disposable verification key.');
        }
        self::assertIsString($private);
        self::assertIsString($details['key']);
        self::$private = $private;
        self::$public = $details['key'];
    }

    public function testNativeIssuanceContainsOnlyApprovedClaimsAndLiveUuidLookup(): void
    {
        $id = Uuid::v7();
        $user = new AccountPrincipal($id, 'email-canary@example.test', 'password-hash-canary');
        $manager = $this->manager();
        $token = $manager->create($user);
        $claims = $manager->parse($token);
        self::assertEqualsCanonicalizing(['sub', 'iss', 'aud', 'iat', 'nbf', 'exp'], array_keys($claims));
        self::assertSame($id->toRfc4122(), $claims['sub']);
        self::assertSame(self::ISSUER, $claims['iss']);
        self::assertSame([self::AUDIENCE], $claims['aud']);
        self::assertIsInt($claims['iat']);
        self::assertIsInt($claims['exp']);
        self::assertSame($claims['iat'], $claims['nbf']);
        self::assertSame(900, $claims['exp'] - $claims['iat']);
        self::assertStringNotContainsString('canary', json_encode($claims, JSON_THROW_ON_ERROR));
        $encodedHeader = base64_decode(explode('.', $token)[0], true);
        self::assertIsString($encodedHeader);
        $header = json_decode($encodedHeader, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($header);
        self::assertSame('JWT', $header['typ']);
        self::assertSame('RS256', $header['alg']);

        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects(self::never())->method('findCredentialsByEmail');
        $accounts->expects(self::exactly(2))->method('findCredentialsById')->with(self::equalTo($id))
            ->willReturnOnConsecutiveCalls(new AccountCredentials($id, 'changed@example.test', 'changed-hash'), null);
        $authenticator = $this->authenticator(new BearerAccountUserProvider($accounts));
        $request = Request::create('/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $principal = $authenticator->authenticate($request)->getUser();
        self::assertInstanceOf(AccountPrincipal::class, $principal);
        self::assertSame('changed@example.test', $principal->email());
        self::assertSame('changed-hash', $principal->getPassword());
        $this->expectException(AuthenticationException::class);
        $authenticator->authenticate($request)->getUser();
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidClaims(): iterable
    {
        yield 'different application' => ['iss', 'urn:donmario:another:test'];
        yield 'different environment' => ['iss', 'urn:donmario:unit:dev'];
        yield 'issuer array' => ['iss', [self::ISSUER]];
        yield 'audience mismatch' => ['aud', ['another-api']];
        yield 'extra audience' => ['aud', [self::AUDIENCE, 'another-api']];
        yield 'un-normalized audience' => ['aud', self::AUDIENCE];
        yield 'malformed UUID' => ['sub', 'member@example.test'];
        yield 'UUID array' => ['sub', ['identity']];
        yield 'UUID alternate format' => ['sub', 'urn:uuid:01994750-4620-7000-8000-000000000001'];
        yield 'roles forbidden' => ['roles', ['ROLE_ADMIN']];
        yield 'password-derived metadata forbidden' => ['fingerprint', 'canary'];
        yield 'missing claim' => ['sub', null];
        yield 'string normalized timestamp' => ['iat', '1'];
        yield 'float normalized timestamp' => ['iat', 1.5];
        yield 'future issuance' => ['iat', PHP_INT_MAX];
        yield 'negative issuance' => ['iat', -1];
        yield 'nbf differs from issuance' => ['nbf', 1];
        yield 'expired' => ['exp', 1];
        yield 'unbounded lifetime' => ['exp', PHP_INT_MAX];
    }

    #[DataProvider('invalidClaims')]
    public function testNormalizedClaimsRejectUnexpectedIdentityAndLifecycle(string $claim, mixed $value): void
    {
        $claims = $this->claims();
        if (null === $value) {
            unset($claims[$claim]);
        } else {
            $claims[$claim] = $value;
        }
        $event = new JWTDecodedEvent($claims);
        $this->claimsListener()->onDecoded($event);
        self::assertFalse($event->isValid());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokens(): iterable
    {
        yield 'malformed' => ['malformed'];
        yield 'tampered signature' => ['tampered'];
        yield 'wrong configured algorithm' => ['algorithm'];
        yield 'wrong issuer' => ['issuer'];
        yield 'excess lifetime' => ['lifetime'];
        yield 'missing nbf' => ['missing'];
        yield 'expired' => ['expired'];
        yield 'future nbf' => ['future'];
        yield 'roles claim' => ['roles'];
    }

    #[DataProvider('invalidTokens')]
    public function testNativeValidationRejectsBeforeAccountLookup(string $variant): void
    {
        $claims = $this->claims();
        $claims['nbf'] = new \DateTimeImmutable('@'.$claims['nbf']);
        match ($variant) {
            'issuer' => $claims['iss'] = 'other',
            'lifetime' => ++$claims['exp'],
            'expired' => $claims['exp'] = time() - 1,
            'future' => $claims['nbf'] = new \DateTimeImmutable('+1 hour'),
            'roles' => $claims['roles'] = ['ROLE_ADMIN'],
            default => null,
        };
        if ('missing' === $variant) {
            unset($claims['nbf']);
        }
        $token = $this->encoder('algorithm' === $variant ? 'RS384' : 'RS256')->encode($claims);
        if ('malformed' === $variant) {
            $token = 'not.a.jwt';
        } elseif ('tampered' === $variant) {
            $parts = explode('.', $token);
            self::assertCount(3, $parts);
            $signature = $parts[2];
            self::assertNotSame('', $signature);
            $parts[2] = ('A' === $signature[0] ? 'B' : 'A').substr($signature, 1);
            $token = implode('.', $parts);
        }
        $provider = $this->createMock(UserProviderInterface::class);
        $provider->expects(self::never())->method('loadUserByIdentifier');
        $authenticator = $this->authenticator($provider);
        $request = Request::create('/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        try {
            $authenticator->authenticate($request)->getUser();
            self::fail('Invalid bearer token authenticated.');
        } catch (AuthenticationException $failure) {
            $response = $authenticator->onAuthenticationFailure($request, $failure);
            self::assertNotNull($response);
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('{"error":"Invalid credentials."}', $response->getContent());
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertFalse($request->hasSession());
        }
    }

    public function testNativeAdditionalPublicKeySupportsExplicitOverlapAndRemoval(): void
    {
        $oldToken = $this->manager()->create(new AccountPrincipal(Uuid::v7(), 'member@example.test', 'hash'));
        $newKey = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($newKey);
        $details = openssl_pkey_get_details($newKey);
        self::assertNotFalse($details);
        $loader = $this->createStub(KeyLoaderInterface::class);
        $loader->method('loadKey')->willReturn($details['key']);
        $loader->method('getAdditionalPublicKeys')->willReturn([self::$public]);
        $overlap = new LcobucciJWTEncoder(new LcobucciJWSProvider($loader, 'RS256', 900, 0));
        self::assertArrayHasKey('sub', $overlap->decode($oldToken));
        $retired = $this->createStub(KeyLoaderInterface::class);
        $retired->method('loadKey')->willReturn($details['key']);
        $retired->method('getAdditionalPublicKeys')->willReturn([]);
        $this->expectException(JWTDecodeFailureException::class);
        (new LcobucciJWTEncoder(new LcobucciJWSProvider($retired, 'RS256', 900, 0)))->decode($oldToken);
    }

    public function testNativeExtractorOnlyUsesAuthorizationHeader(): void
    {
        $provider = $this->createMock(UserProviderInterface::class);
        $provider->expects(self::never())->method('loadUserByIdentifier');
        $authenticator = $this->authenticator($provider);
        $request = Request::create('/api/me?bearer=token', 'POST', ['bearer' => 'token'], ['BEARER' => 'token'], content: '{"token":"token"}');
        self::assertFalse($authenticator->supports($request));
        $request->headers->set('Authorization', 'Basic token');
        self::assertFalse($authenticator->supports($request));
        $request->headers->set('Authorization', 'Bearer token');
        self::assertTrue($authenticator->supports($request));
    }

    /** @return array{sub: string, iss: string, aud: list<string>, iat: int, nbf: int, exp: int} */
    private function claims(): array
    {
        $now = time();

        return ['sub' => Uuid::v7()->toRfc4122(), 'iss' => self::ISSUER, 'aud' => [self::AUDIENCE], 'iat' => $now, 'nbf' => $now, 'exp' => $now + 900];
    }

    private function claimsListener(): JwtClaimsListener
    {
        return new JwtClaimsListener(self::ISSUER, self::AUDIENCE);
    }

    private function dispatcher(): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        $claims = $this->claimsListener();
        $dispatcher->addListener(Events::JWT_CREATED, $claims->onCreated(...));
        $dispatcher->addListener(Events::JWT_DECODED, $claims->onDecoded(...));
        $failure = new JwtFailureListener(new ApiAuthenticationFailureHandler(new TokenStorage()));
        foreach ([Events::JWT_INVALID, Events::JWT_EXPIRED, Events::JWT_NOT_FOUND] as $event) {
            $dispatcher->addListener($event, $failure);
        }

        return $dispatcher;
    }

    private function encoder(string $algorithm = 'RS256'): LcobucciJWTEncoder
    {
        $loader = $this->createStub(KeyLoaderInterface::class);
        $loader->method('loadKey')->willReturnCallback(static fn (string $type): string => KeyLoaderInterface::TYPE_PRIVATE === $type ? self::$private : self::$public);
        $loader->method('getPassphrase')->willReturn('');
        $loader->method('getAdditionalPublicKeys')->willReturn([]);

        return new LcobucciJWTEncoder(new LcobucciJWSProvider($loader, $algorithm, 900, 0));
    }

    private function manager(): JWTManager
    {
        return new JWTManager($this->encoder(), $this->dispatcher(), 'sub');
    }

    /** @param UserProviderInterface<UserInterface> $provider */
    private function authenticator(UserProviderInterface $provider): JWTAuthenticator
    {
        return new JWTAuthenticator($this->manager(), $this->dispatcher(), new AuthorizationHeaderTokenExtractor('Bearer', 'Authorization'), $provider);
    }
}
