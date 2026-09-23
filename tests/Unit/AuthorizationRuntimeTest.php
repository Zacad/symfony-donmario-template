<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceQuery;
use App\Module\Authenticating\Application\GetAccountIdentity\GetAccountIdentityQuery;
use App\Module\Authenticating\Application\RegisterAccount\RegisterAccountCommand;
use App\Module\Authenticating\Application\UpgradePasswordHash\UpgradePasswordHashCommand;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AuthenticatingVoter;
use App\Platform\Authorization\Actor;
use App\Platform\Authorization\ActorKind;
use App\Platform\Authorization\AuthorizationDenied;
use App\Platform\Authorization\AuthorizationMiddleware;
use App\Platform\Authorization\AuthorizationToken;
use App\Platform\Authorization\ExecutionContext;
use App\Platform\Authorization\PublicAccessVoter;
use App\Platform\Event\ApplicationEvent;
use App\Platform\Messaging\EventPolicyMiddleware;
use App\Platform\Messaging\InvocationContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Strategy\UnanimousStrategy;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Uid\Uuid;

final class AuthorizationRuntimeTest extends TestCase
{
    public function testExecutionContextPinsHttpActorWithoutGlobalTokenBridge(): void
    {
        $invocation = new InvocationContext();
        $requests = new RequestStack();
        $requests->push(new Request());
        $tokens = new TokenStorage();
        $id = Uuid::v7();
        $user = new InMemoryUser($id->toRfc4122(), null, ['ROLE_USER']);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $context = new ExecutionContext($invocation, $requests, $tokens, new AuthenticationTrustResolver());

        $root = $context->enter();
        $nested = $context->enter();
        self::assertSame($root->actor, $nested->actor);
        self::assertSame(ActorKind::Account, $root->actor->kind);
        self::assertTrue($id->equals($root->actor->accountId));
        self::assertNotSame($tokens->getToken(), $root);
        $context->leave();
        $context->leave();
    }

    public function testExecutionContextRejectsFrameUnderflow(): void
    {
        $context = new ExecutionContext(new InvocationContext(), new RequestStack(), new TokenStorage(), new AuthenticationTrustResolver());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('authorization.context: Execution frame underflow.');
        $context->leave();
    }

    public function testAuthorizationDecisionFramesBlockWritesWithFixedDiagnostics(): void
    {
        $context = new InvocationContext();
        $execution = new ExecutionContext($context, new RequestStack(), new TokenStorage(), new AuthenticationTrustResolver());
        $context->enterAuthorizationDecision();
        self::assertTrue($context->inAuthorizationDecision());
        self::assertTrue($execution->enter()->supportRead);

        try {
            $context->enter('command');
            self::fail('Authorization decisions must not dispatch commands.');
        } catch (\LogicException $failure) {
            self::assertSame('authorization.decision_write: Authorization decisions cannot dispatch commands.', $failure->getMessage());
        }

        try {
            $context->assertEventDispatchAllowed();
            self::fail('Authorization decisions must not dispatch events.');
        } catch (\LogicException $failure) {
            self::assertSame('authorization.decision_write: Authorization decisions cannot dispatch events.', $failure->getMessage());
        }

        $execution->leave();
        $context->leaveAuthorizationDecision();
        self::assertFalse($context->inAuthorizationDecision());
    }

    public function testMiddlewareUsesOneAttributeAndMapsDenialToFixedFailure(): void
    {
        $invocation = new InvocationContext();
        $execution = new ExecutionContext($invocation, new RequestStack(), new TokenStorage(), new AuthenticationTrustResolver());
        $manager = new AccessDecisionManager([new RuntimeVoter(false)], new UnanimousStrategy(false));
        $middleware = new AuthorizationMiddleware($invocation, $execution, $manager, [RuntimeMessage::class => RuntimeVoter::class]);
        $stack = $this->createStub(StackInterface::class);
        $stack->method('next')->willReturnSelf();
        $this->expectException(AuthorizationDenied::class);
        $this->expectExceptionMessage('Access denied.');
        $middleware->handle(new Envelope(new RuntimeMessage()), $stack);
    }

    public function testCredentialFreeTokenCannotLeakOrMutateFacts(): void
    {
        $id = Uuid::v7();
        $token = new AuthorizationToken(new Actor(ActorKind::Account, $id), false);
        self::assertSame('authorization-token', (string) $token);
        self::assertStringNotContainsString($id->toRfc4122(), (string) $token);
        self::assertSame([], $token->getRoleNames());
        self::assertNull($token->getUser());
        $this->expectException(\LogicException::class);
        serialize($token);
    }

    public function testAllAbstainDenies(): void
    {
        $invocation = new InvocationContext();
        $middleware = new AuthorizationMiddleware(
            $invocation,
            new ExecutionContext($invocation, new RequestStack(), new TokenStorage(), new AuthenticationTrustResolver()),
            new AccessDecisionManager([], new UnanimousStrategy(false)),
            [RuntimeMessage::class => RuntimeVoter::class],
        );
        $stack = $this->createStub(StackInterface::class);
        $this->expectException(AuthorizationDenied::class);
        $middleware->handle(new Envelope(new RuntimeMessage()), $stack);
    }

    public function testAuthenticatingVoterUsesItsClassAndCompilerPermissionMap(): void
    {
        $permissions = array_fill_keys([
            CheckAccountExistenceQuery::class,
            GetAccountIdentityQuery::class,
            RegisterAccountCommand::class,
            UpgradePasswordHashCommand::class,
        ], null);
        $manager = new AccessDecisionManager([new AuthenticatingVoter($permissions)], new UnanimousStrategy(false));
        $id = Uuid::v7();

        self::assertTrue($manager->decide(
            new AuthorizationToken(new Actor(ActorKind::Operator, scope: 'accounts'), false),
            [AuthenticatingVoter::class],
            new RegisterAccountCommand('user@example.test', 'not evaluated'),
        ));
        self::assertTrue($manager->decide(
            new AuthorizationToken(new Actor(ActorKind::Authentication, $id), false),
            [AuthenticatingVoter::class],
            new UpgradePasswordHashCommand($id, 'old', 'new'),
        ));
        self::assertTrue($manager->decide(
            new AuthorizationToken(new Actor(ActorKind::Account, $id), false),
            [AuthenticatingVoter::class],
            new GetAccountIdentityQuery($id),
        ));
        self::assertTrue($manager->decide(
            new AuthorizationToken(new Actor(ActorKind::Anonymous), true),
            [AuthenticatingVoter::class],
            new CheckAccountExistenceQuery([$id]),
        ));
        self::assertFalse($manager->decide(
            new AuthorizationToken(new Actor(ActorKind::Operator, scope: 'accounts'), false),
            [RuntimeVoter::class],
            new RegisterAccountCommand('user@example.test', 'not evaluated'),
        ));
    }

    public function testPublicAccessVoterAllowsEveryInternalActorForOnlyInventoriedMessages(): void
    {
        $manager = new AccessDecisionManager([new PublicAccessVoter([RuntimeMessage::class => null])], new UnanimousStrategy(false));
        foreach ([
            new Actor(ActorKind::Anonymous),
            new Actor(ActorKind::Account, Uuid::v7()),
            new Actor(ActorKind::Operator, scope: 'any'),
            new Actor(ActorKind::Authentication, Uuid::v7()),
        ] as $actor) {
            self::assertTrue($manager->decide(new AuthorizationToken($actor, false), [PublicAccessVoter::class], new RuntimeMessage()));
        }

        $user = new InMemoryUser('member', null, ['ROLE_USER']);
        self::assertFalse($manager->decide(new UsernamePasswordToken($user, 'main', $user->getRoles()), [PublicAccessVoter::class], new RuntimeMessage()));
        self::assertFalse($manager->decide(new AuthorizationToken(new Actor(ActorKind::Anonymous), false), [PublicAccessVoter::class], new RuntimeNestedMessage()));
        self::assertFalse($manager->decide(new AuthorizationToken(new Actor(ActorKind::Anonymous), false), [RuntimeVoter::class], new RuntimeMessage()));
    }

    public function testEventFrameRetainsAndRestoresPinnedActor(): void
    {
        $invocation = new InvocationContext();
        $requests = new RequestStack();
        $requests->push(new Request());
        $tokens = new TokenStorage();
        $first = Uuid::v7();
        $user = new InMemoryUser($first->toRfc4122(), null, ['ROLE_USER']);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $execution = new ExecutionContext($invocation, $requests, $tokens, new AuthenticationTrustResolver());
        $root = $execution->enter();

        $replacement = Uuid::v7();
        $replacementUser = new InMemoryUser($replacement->toRfc4122(), null, ['ROLE_USER']);
        $tokens->setToken(new UsernamePasswordToken($replacementUser, 'main', $replacementUser->getRoles()));
        $terminal = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $envelope;
            }
        };
        $stack = $this->createStub(StackInterface::class);
        $stack->method('next')->willReturn($terminal);
        new EventPolicyMiddleware([RuntimeEvent::class => 'event'], $execution)->handle(new Envelope(new RuntimeEvent()), $stack);

        $afterEvent = $execution->enter();
        self::assertSame($root->actor, $afterEvent->actor);
        self::assertTrue($first->equals($afterEvent->actor->accountId));
        $execution->leave();
        $execution->leave();
    }
}

final readonly class RuntimeMessage
{
}

final readonly class RuntimeNestedMessage
{
}

final readonly class RuntimeEvent extends ApplicationEvent
{
}

/** @extends Voter<string, RuntimeMessage> */
final class RuntimeVoter extends Voter
{
    public function __construct(private readonly bool $allowed)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::class === $attribute && $subject instanceof RuntimeMessage;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token, ?Vote $vote = null): bool
    {
        return $token instanceof AuthorizationToken && $this->allowed;
    }
}
