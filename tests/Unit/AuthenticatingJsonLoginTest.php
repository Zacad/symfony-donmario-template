<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authenticating\Application\UpgradePasswordHash\UpgradePasswordHashCommand;
use App\Module\Authenticating\Domain\AccountCredentials;
use App\Module\Authenticating\Domain\AccountRepository;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\EventListener\ApiAuthenticationExceptionListener;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\EventListener\ApiAuthenticationRequestListener;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\EventListener\JsonLoginIdentifierListener;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\ApiAuthenticationFailureHandler;
use App\Module\Authenticating\UI\Api\LoginController;
use App\Module\Authenticating\UI\Http\Security\AccountUserProvider;
use App\Platform\Authorization\AuthenticationExecution;
use App\Platform\Authorization\ExecutionContext;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\InvocationContext;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RateLimiter\RequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Http\Authentication\AuthenticatorManager;
use Symfony\Component\Security\Http\Authenticator\JsonLoginAuthenticator;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\EventListener\CheckCredentialsListener;
use Symfony\Component\Security\Http\EventListener\LoginThrottlingListener;
use Symfony\Component\Security\Http\EventListener\PasswordMigratingListener;
use Symfony\Component\Security\Http\HttpUtils;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Uid\Uuid;

final class AuthenticatingJsonLoginTest extends TestCase
{
    /** @return iterable<string, array{bool, bool, bool, bool, int}> */
    public static function loginOutcomes(): iterable
    {
        yield 'native migration then issuance' => [true, true, false, false, 200];
        yield 'bad password' => [false, true, false, false, 401];
        yield 'CAS conflict' => [true, false, false, false, 401];
        yield 'migration transaction failure' => [true, false, true, false, 503];
        yield 'signing failure after committed migration' => [true, true, false, true, 503];
    }

    #[DataProvider('loginOutcomes')]
    public function testNativeLoginFinishesMigrationBeforeIssuingAndPreservesBrowserSession(bool $validPassword, bool $upgraded, bool $migrationFailure, bool $signingFailure, int $status): void
    {
        $id = Uuid::v7();
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects(self::once())->method('findCredentialsByEmail')->with('member@example.test')
            ->willReturn(new AccountCredentials($id, 'member@example.test', 'original-hash'));
        $migrated = false;
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            UpgradePasswordHashCommand::class => [static function (UpgradePasswordHashCommand $command) use ($upgraded, $migrationFailure, &$migrated): bool {
                self::assertSame('original-hash', $command->expectedPasswordHash);
                self::assertSame('replacement-hash', $command->newPasswordHash);
                if ($migrationFailure) {
                    throw new \RuntimeException('migration-secret-canary');
                }
                $migrated = $upgraded;

                return $upgraded;
            }],
        ]))]);
        $authentication = new AuthenticationExecution(new ExecutionContext(new InvocationContext(), new RequestStack(), new TokenStorage(), new AuthenticationTrustResolver()));
        $provider = new AccountUserProvider($accounts, new CommandBus($bus), $authentication);
        $tokens = new TokenStorage();
        $failures = new ApiAuthenticationFailureHandler($tokens);
        $authenticator = new JsonLoginAuthenticator(new HttpUtils(), $provider, failureHandler: $failures, options: ['check_path' => '/api/login', 'username_path' => 'email', 'password_path' => 'password']);
        $hasher = $this->createStub(PasswordHasherInterface::class);
        $hasher->method('verify')->willReturn($validPassword);
        $hasher->method('needsRehash')->willReturn(true);
        $hasher->method('hash')->willReturn('replacement-hash');
        $factory = new PasswordHasherFactory(['authenticating.password' => $hasher]);
        $events = new EventDispatcher();
        $events->addListener(CheckPassportEvent::class, new JsonLoginIdentifierListener(), 2100);
        $events->addSubscriber(new CheckCredentialsListener($factory));
        $events->addSubscriber(new PasswordMigratingListener($factory));
        $request = Request::create('/api/login', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"email":" MEMBER@EXAMPLE.TEST ","password":"plaintext-secret-canary"}');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $session->set('_security_main', 'existing-browser-login');
        $session->set('csrf', 'existing-csrf');
        $sessionId = $session->getId();
        $stack = new RequestStack();
        $stack->push($request);
        $limiter = $this->createMock(RequestRateLimiterInterface::class);
        $limiter->expects(self::once())->method('consume')->willReturnCallback(static function (Request $request): RateLimit {
            self::assertSame('member@example.test', $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME));

            return new RateLimit(4, new \DateTimeImmutable(), true, 5);
        });
        $events->addSubscriber(new LoginThrottlingListener($stack, $limiter));
        $manager = new AuthenticatorManager([$authenticator], $tokens, $events, 'api_login');
        $jwt = $this->createMock(JWTTokenManagerInterface::class);
        $jwt->expects($validPassword && $upgraded && !$migrationFailure ? self::once() : self::never())->method('create')
            ->willReturnCallback(static function (AccountPrincipal $user) use (&$migrated, $signingFailure): string {
                self::assertTrue($migrated, 'Signing must happen strictly after committed native migration.');
                self::assertSame('replacement-hash', $user->getPassword());
                if ($signingFailure) {
                    throw new \RuntimeException('signing-secret-canary');
                }

                return 'issued-token';
            });
        $logs = new TestHandler();
        try {
            self::assertTrue($manager->supports($request));
            $response = $manager->authenticateRequest($request);
            if (null === $response) {
                $user = $tokens->getToken()?->getUser();
                self::assertInstanceOf(AccountPrincipal::class, $user);
                $response = (new LoginController($jwt))($user);
            }
        } catch (\Throwable $failure) {
            $exception = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
            (new ApiAuthenticationExceptionListener($failures, new Logger('test', [$logs])))($exception);
            $response = $exception->getResponse();
        }
        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame([], $response->headers->getCookies());
        self::assertSame($sessionId, $session->getId());
        self::assertSame(['_security_main' => 'existing-browser-login', 'csrf' => 'existing-csrf'], $session->all());
        self::assertStringNotContainsString('secret-canary', $response->getContent() ?: '');
        self::assertStringNotContainsString('secret-canary', json_encode($logs->getRecords(), JSON_THROW_ON_ERROR));
        if (200 === $status) {
            $content = $response->getContent();
            self::assertIsString($content);
            self::assertSame(['access_token' => 'issued-token', 'token_type' => 'Bearer', 'expires_in' => 900], json_decode($content, true, flags: JSON_THROW_ON_ERROR));
        } else {
            self::assertNull($tokens->getToken());
            self::assertStringNotContainsString('access_token', $response->getContent() ?: '');
        }
    }

    /** @return iterable<string, array{string, string, string, string, int}> */
    public static function invalidRequests(): iterable
    {
        yield 'method' => ['/api/login', 'GET', '', 'application/json', 405];
        yield 'trailing slash' => ['/api/login/', 'POST', '{}', 'application/json', 404];
        yield 'encoded path' => ['/api/%6cogin', 'POST', '{}', 'application/json', 404];
        yield 'query credentials' => ['/api/login?password=canary', 'POST', '{}', 'application/json', 400];
        yield 'form content' => ['/api/login', 'POST', 'email=member', 'application/x-www-form-urlencoded', 415];
        yield 'json syntax' => ['/api/login', 'POST', '{', 'application/json', 400];
        yield 'array document' => ['/api/login', 'POST', '[]', 'application/json', 400];
        yield 'missing field' => ['/api/login', 'POST', '{"email":"member"}', 'application/json', 400];
        yield 'non-string password' => ['/api/login', 'POST', '{"email":"member","password":[]}', 'application/json', 400];
        yield 'extra field' => ['/api/login', 'POST', '{"email":"member","password":"canary","remember_me":true}', 'application/json', 400];
        yield 'NUL' => ['/api/login', 'POST', '{"email":"member","password":"canary\\u0000"}', 'application/json', 400];
        yield 'password bounds' => ['/api/login', 'POST', json_encode(['email' => 'member', 'password' => str_repeat('x', 4097)], JSON_THROW_ON_ERROR), 'application/json', 400];
        yield 'body bounds' => ['/api/login', 'POST', str_repeat('x', 16385), 'application/json', 413];
    }

    #[DataProvider('invalidRequests')]
    public function testGuardRejectsBeforeAuthenticationWithoutSession(string $path, string $method, string $body, string $type, int $status): void
    {
        $request = Request::create($path, $method, server: ['CONTENT_TYPE' => $type], content: $body);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new ApiAuthenticationRequestListener(new ApiAuthenticationFailureHandler(new TokenStorage())))($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        self::assertFalse($request->hasSession());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertStringNotContainsString('canary', $response->getContent() ?: '');
    }

    public function testGuardPreservesNativeJsonBodyAndAcceptsExactTokenBound(): void
    {
        $body = '{"email":" MEMBER@EXAMPLE.TEST ","password":" preserved spaces "}';
        $request = Request::create('/api/login', 'POST', server: ['CONTENT_TYPE' => 'application/json; charset=utf-8'], content: $body);
        $guard = new ApiAuthenticationRequestListener(new ApiAuthenticationFailureHandler(new TokenStorage()));
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $guard($event);
        self::assertNull($event->getResponse());
        self::assertSame($body, $request->getContent());
        self::assertFalse($request->hasSession());
        $request = Request::create('/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('x', 8192)]);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $guard($event);
        self::assertNull($event->getResponse());
        $request->headers->set('Authorization', 'Bearer '.str_repeat('x', 8193));
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $guard($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
    }
}
