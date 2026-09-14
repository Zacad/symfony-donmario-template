<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authenticating\Application\UpgradePasswordHash\UpgradePasswordHashCommand;
use App\Module\Authenticating\Domain\AccountCredentials;
use App\Module\Authenticating\Domain\AccountRepository;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\EventListener\AuthenticationExceptionListener;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\EventListener\AuthenticationRequestListener;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\LoginFailureHandler;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\SecurityLogger;
use App\Module\Authenticating\UI\Http\Security\AccountUserProvider;
use App\Platform\Messaging\CommandBus;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\LogoutException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\EventListener\PasswordMigratingListener;
use Symfony\Component\Security\Http\Firewall\ContextListener;
use Symfony\Component\Uid\Uuid;

final class AuthenticatingSecurityTest extends TestCase
{
    private const string HASH = 'full-password-hash-canary-that-must-never-reach-session-or-logs';

    public function testPrincipalSerializesOnlyIdentityAndNativeFingerprint(): void
    {
        $user = new AccountPrincipal(Uuid::v7(), 'member@example.test', self::HASH);
        $serialized = serialize($user);
        self::assertStringNotContainsString(self::HASH, $serialized);
        self::assertStringContainsString(hash('crc32c', self::HASH), $serialized);
        self::assertSame([], $user->getRoles());
        self::assertSame($user->id()->toRfc4122(), $user->getUserIdentifier());
        self::assertSame(self::HASH, $user->getPassword());
        $restored = unserialize($serialized);
        self::assertInstanceOf(AccountPrincipal::class, $restored);
        self::assertSame($user->getUserIdentifier(), $restored->getUserIdentifier());
        self::assertSame($user->email(), $restored->email());
        self::assertSame($serialized, serialize($restored), 'An already-fingerprinted principal must not be fingerprinted twice.');

        $hasher = $this->createStub(PasswordHasherInterface::class);
        $factory = new PasswordHasherFactory(['authenticating.password' => $hasher]);
        self::assertSame($factory->getPasswordHasher('authenticating.password'), $factory->getPasswordHasher($user));
    }

    /** @return iterable<string, array{?string, string, bool}> */
    public static function refreshedCredentials(): iterable
    {
        yield 'unchanged' => [self::HASH, 'member@example.test', true];
        yield 'email changed but UUID remains identity' => [self::HASH, 'renamed@example.test', true];
        yield 'password changed' => ['replacement-full-hash', 'member@example.test', false];
        yield 'deleted UUID is not resolved by recycled email' => [null, 'member@example.test', false];
    }

    #[DataProvider('refreshedCredentials')]
    public function testNativeContextRefreshesByUuidAndComparesFingerprint(?string $hash, string $email, bool $authenticated): void
    {
        $id = Uuid::v7();
        $original = new AccountPrincipal($id, 'member@example.test', self::HASH);
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects(self::never())->method('findCredentialsByEmail');
        $accounts->expects(self::once())->method('findCredentialsById')->with(self::equalTo($id))
            ->willReturn(null === $hash ? null : new AccountCredentials($id, $email, $hash));
        $provider = new AccountUserProvider($accounts, new CommandBus(new MessageBus()));
        $request = $this->request('/account');
        $request->getSession()->set('_security_main', serialize(new UsernamePasswordToken($original, 'main', [])));
        $request->cookies->set($request->getSession()->getName(), $request->getSession()->getId());
        $tokens = new TokenStorage();
        $listener = new ContextListener($tokens, [$provider], 'main');
        $listener->authenticate($this->requestEvent($request));
        self::assertSame($authenticated, null !== $tokens->getToken());
        if ($authenticated) {
            $user = $tokens->getToken()?->getUser();
            self::assertInstanceOf(AccountPrincipal::class, $user);
            self::assertSame($hash, $user->getPassword(), 'Native refresh restores the full hash only in request memory.');
            self::assertSame($email, $user->email());
        }
    }

    /** @return iterable<string, array{bool, bool, int}> */
    public static function upgradeOutcomes(): iterable
    {
        yield 'committed' => [true, false, 302];
        yield 'CAS conflict aborts login' => [false, false, 302];
        yield 'database failure aborts login' => [false, true, 503];
    }

    #[DataProvider('upgradeOutcomes')]
    public function testNativePasswordMigrationPublishesHashOnlyAfterSuccessfulCommand(bool $result, bool $databaseFailure, int $status): void
    {
        $user = new AccountPrincipal(Uuid::v7(), 'member@example.test', self::HASH);
        $called = false;
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            UpgradePasswordHashCommand::class => [static function (UpgradePasswordHashCommand $command) use ($user, $result, $databaseFailure, &$called): bool {
                self::assertSame(self::HASH, $user->getPassword());
                self::assertSame($user->id(), $command->accountId);
                self::assertSame(self::HASH, $command->expectedPasswordHash);
                self::assertSame('replacement-hash', $command->newPasswordHash);
                $called = true;
                if ($databaseFailure) {
                    throw new \RuntimeException('database-password-canary');
                }

                return $result;
            }],
        ]))]);
        $provider = new AccountUserProvider($this->createStub(AccountRepository::class), new CommandBus($bus));
        $hasher = $this->createStub(PasswordHasherInterface::class);
        $hasher->method('needsRehash')->willReturn(true);
        $hasher->method('hash')->willReturn('replacement-hash');
        $migration = new PasswordMigratingListener(new PasswordHasherFactory(['authenticating.password' => $hasher]));
        $request = $this->request('/login', 'POST');
        $tokens = new TokenStorage();
        $token = new UsernamePasswordToken($user, 'main', []);
        $tokens->setToken($token); // Native AuthenticatorManager sets this before migration.
        $previous = new UsernamePasswordToken(new AccountPrincipal(Uuid::v7(), 'previous@example.test', self::HASH), 'main', []);
        $request->getSession()->set('_security_main', serialize($previous));
        $previousSessionId = $request->getSession()->getId();
        $passport = new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user), [new PasswordUpgradeBadge('plaintext-canary', $provider)]);
        $event = new LoginSuccessEvent($this->createStub(AuthenticatorInterface::class), $passport, $token, $request, new Response('', 302), 'main', $previous);
        $logs = new TestHandler();
        try {
            $migration->onLoginSuccess($event);
            self::assertTrue($result);
            self::assertSame('replacement-hash', $user->getPassword());
            self::assertSame($token, $tokens->getToken());
        } catch (\Throwable $failure) {
            self::assertFalse($result);
            self::assertNull($failure->getPrevious(), 'Database exceptions cannot be attached to the safe failure.');
            $exception = $this->exceptionEvent($request, $failure);
            (new AuthenticationExceptionListener(new LoginFailureHandler($tokens), new Logger('test', [$logs])))($exception);
            self::assertSame($status, $exception->getResponse()?->getStatusCode());
            self::assertNull($tokens->getToken());
            self::assertFalse($request->getSession()->has('_security_main'));
            self::assertNotSame($previousSessionId, $request->getSession()->getId());
            self::assertSame(self::HASH, $user->getPassword());
            self::assertStringNotContainsString('database-password-canary', serialize($request->getSession()->all()));
            self::assertStringNotContainsString('database-password-canary', json_encode($logs->getRecords(), JSON_THROW_ON_ERROR));
        }
        self::assertTrue($called);
    }

    /** @return iterable<string, array{AuthenticationException}> */
    public static function normalAuthenticationFailures(): iterable
    {
        yield 'bad credentials' => [new BadCredentialsException(self::HASH)];
        yield 'invalid or missing CSRF' => [new InvalidCsrfTokenException(self::HASH)];
        yield 'throttled' => [new TooManyLoginAttemptsAuthenticationException(1)];
    }

    #[DataProvider('normalAuthenticationFailures')]
    public function testNormalFailurePreservesAuthenticationAndNeverStoresException(AuthenticationException $failure): void
    {
        $request = $this->request('/login?_failure_path=https://evil.test', 'POST');
        $request->request->set('_failure_path', 'https://evil.test');
        $request->headers->set('Referer', 'https://evil.test');
        $tokens = new TokenStorage();
        $token = new UsernamePasswordToken(new AccountPrincipal(Uuid::v7(), 'member@example.test', self::HASH), 'main', []);
        $tokens->setToken($token);
        $request->getSession()->set('_security_main', 'old-token');
        $request->getSession()->set('csrf-token', 'keep');
        $sessionId = $request->getSession()->getId();
        $response = (new LoginFailureHandler($tokens))->onAuthenticationFailure($request, $failure);
        self::assertSame('/login', $response->headers->get('Location'));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame($token, $tokens->getToken());
        self::assertSame($sessionId, $request->getSession()->getId());
        self::assertSame(['_security_main' => 'old-token', 'csrf-token' => 'keep', LoginFailureHandler::ERROR_KEY => 'bad_credentials'], $request->getSession()->all());
    }

    public function testInvalidLogoutCsrfPreservesExistingAuthentication(): void
    {
        $request = $this->request('/logout', 'POST');
        $tokens = new TokenStorage();
        $token = new UsernamePasswordToken(new AccountPrincipal(Uuid::v7(), 'member@example.test', self::HASH), 'main', []);
        $tokens->setToken($token);
        $event = $this->exceptionEvent($request, new LogoutException('Invalid CSRF token.'));
        (new AuthenticationExceptionListener(new LoginFailureHandler($tokens), new Logger('test')))($event);
        self::assertSame(403, $event->getResponse()?->getStatusCode());
        self::assertSame($token, $tokens->getToken());
    }

    public function testRequestGuardNormalizesBeforeLimiterAndDiscardsRedirectInputs(): void
    {
        $request = $this->request('/login?_target_path=https://evil.test', 'POST', '_username=%20MEMBER%40EXAMPLE.TEST%20&_password=long+password+example&_csrf_token=token&_failure_path=https%3A%2F%2Fevil.test');
        $request->getSession()->set('_security_main', 'old-token');
        $request->getSession()->set('csrf-token', 'keep');
        $request->cookies->set($request->getSession()->getName(), $request->getSession()->getId());
        $event = $this->requestEvent($request);
        (new AuthenticationRequestListener())($event);
        self::assertNull($event->getResponse());
        self::assertSame(['_username' => 'member@example.test', '_password' => 'long password example', '_csrf_token' => 'token'], $request->request->all());
        self::assertSame([], $request->query->all());
        self::assertSame('old-token', $request->getSession()->get('_security_main'));
        self::assertSame('keep', $request->getSession()->get('csrf-token'));
    }

    /** @return iterable<string, array{string, string, string, string, int}> */
    public static function invalidRequests(): iterable
    {
        yield 'array username' => ['/login', 'POST', '_username[]=member', 'application/x-www-form-urlencoded', 400];
        yield 'duplicate password' => ['/login', 'POST', '_password=a&_password=b', 'application/x-www-form-urlencoded', 400];
        yield 'password too long' => ['/login', 'POST', '_password='.str_repeat('x', 4097), 'application/x-www-form-urlencoded', 400];
        yield 'oversized body' => ['/login', 'POST', str_repeat('x', 16385), 'application/x-www-form-urlencoded', 413];
        yield 'token too long' => ['/logout', 'POST', '_csrf_token='.str_repeat('x', 513), 'application/x-www-form-urlencoded', 400];
        yield 'json' => ['/login', 'POST', '{}', 'application/json', 415];
        yield 'multipart' => ['/login', 'POST', '', 'multipart/form-data', 415];
        yield 'query password' => ['/login?_password=secret', 'GET', '', '', 400];
        yield 'NUL password' => ['/login', 'POST', '_password=abc%00def', 'application/x-www-form-urlencoded', 400];
        yield 'GET logout' => ['/logout', 'GET', '', '', 405];
        yield 'login trailing slash' => ['/login/', 'POST', '', 'application/x-www-form-urlencoded', 404];
        yield 'logout trailing slash' => ['/logout/', 'POST', '', 'application/x-www-form-urlencoded', 404];
        yield 'encoded login path' => ['/%6cogin', 'POST', '', 'application/x-www-form-urlencoded', 404];
    }

    #[DataProvider('invalidRequests')]
    public function testInputGuardRejectsBeforeNativeFirewall(string $path, string $method, string $body, string $contentType, int $status): void
    {
        $request = $this->request($path, $method, $body);
        $request->headers->set('Content-Type', $contentType);
        $request->getSession()->set('_security_main', 'old-token');
        $request->getSession()->set('csrf-token', 'keep');
        $sessionId = $request->getSession()->getId();
        $request->cookies->set($request->getSession()->getName(), $sessionId);
        $event = $this->requestEvent($request);
        (new AuthenticationRequestListener())($event);
        self::assertSame($status, $event->getResponse()?->getStatusCode());
        self::assertStringNotContainsString($body ?: 'plaintext-canary', $event->getResponse()->getContent() ?: '');
        self::assertSame($sessionId, $request->getSession()->getId());
        self::assertSame(['_security_main' => 'old-token', 'csrf-token' => 'keep'], $request->getSession()->all());
    }

    public function testSecurityLoggerNeverStringifiesMessagesOrLogsCredentialContext(): void
    {
        $logs = new TestHandler();
        $message = new class implements \Stringable {
            public function __toString(): string
            {
                throw new \LogicException('Must not stringify');
            }
        };
        (new SecurityLogger(new Logger('security', [$logs])))->error($message, ['exception' => new \RuntimeException(self::HASH), 'token' => $message]);
        self::assertSame('Web security activity.', $logs->getRecords()[0]->message);
        self::assertSame(['component' => 'authenticating'], $logs->getRecords()[0]->context);
    }

    private function request(string $path, string $method = 'GET', string $body = ''): Request
    {
        $request = Request::create($path, $method, server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: $body);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function requestEvent(Request $request): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function exceptionEvent(Request $request, \Throwable $failure): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }
}
