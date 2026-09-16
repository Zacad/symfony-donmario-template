<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\Authorization\Actor;
use App\Platform\Authorization\ActorKind;
use App\Platform\Authorization\AuthenticationExecution;
use App\Platform\Authorization\AuthorizationDenied;
use App\Platform\Authorization\AuthorizationMiddleware;
use App\Platform\Authorization\ExecutionContext;
use App\Platform\Authorization\OperatorExecution;
use App\Platform\Authorization\PolicyContext;
use App\Platform\Event\ApplicationEvent;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\EventBus;
use App\Platform\Messaging\InvocationContext;
use App\Platform\Messaging\InvocationMiddleware;
use App\Platform\Messaging\QueryBus;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Uid\Uuid;

final class AuthorizationRuntimeTest extends TestCase
{
    private const PAYLOAD = 'private-authorization-runtime-canary';

    private InvocationContext $invocation;
    private ExecutionContext $execution;
    private RequestStack $requests;
    private TokenStorage $tokens;

    protected function setUp(): void
    {
        $this->invocation = new InvocationContext();
        $this->requests = new RequestStack();
        $this->tokens = new TokenStorage();
        $this->execution = new ExecutionContext($this->invocation, $this->requests, $this->tokens, new AuthenticationTrustResolver());
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function rootDecisions(): iterable
    {
        foreach (['command', 'query'] as $kind) {
            foreach ([false, true] as $allowed) {
                foreach ([false, true] as $authenticated) {
                    yield $kind.' '.($allowed ? 'allow' : 'deny').' '.($authenticated ? 'account' : 'anonymous') => [$kind, $allowed, $authenticated];
                }
            }
        }
    }

    #[DataProvider('rootDecisions')]
    public function testRootDecisionPrecedesHandlerAndDenialHasOnlyFixedPublicDetails(string $kind, bool $allowed, bool $authenticated): void
    {
        if ($authenticated) {
            $this->requests->push(new Request());
            $this->authenticate(Uuid::v7());
        }
        $journey = [];
        $dispatch = $this->root($kind, static function (object $message, PolicyContext $context) use ($allowed, &$journey): bool {
            self::assertFalse($context->supportRead);
            self::assertNull($context->caller);
            $journey[] = 'policy';

            return $allowed;
        }, static function () use (&$journey): string {
            $journey[] = 'handler';

            return 'result';
        });

        if ($allowed) {
            self::assertSame('result', $dispatch());
            self::assertSame(['policy', 'handler'], $journey);
        } else {
            try {
                $dispatch();
                self::fail('A denied invocation must not return a handler result.');
            } catch (AuthorizationDenied $failure) {
                self::assertSame('Access denied.', $failure->getMessage());
                self::assertSame(0, $failure->getCode());
                self::assertNull($failure->getPrevious());
                self::assertSame(['authenticated' => $authenticated], get_object_vars($failure));
                self::assertStringNotContainsString(self::PAYLOAD, $failure->getMessage());
            }
            self::assertSame(['policy'], $journey);
        }
    }

    /** @return iterable<string, array{bool, string, bool}> */
    public static function httpIdentities(): iterable
    {
        yield 'HTTP full UUID identity' => [true, 'full', true];
        yield 'HTTP remembered identity' => [true, 'remembered', false];
        yield 'HTTP missing token' => [true, 'missing', false];
        yield 'HTTP non-UUID identity' => [true, 'invalid', false];
        yield 'CLI stale full token' => [false, 'full', false];
        yield 'CLI stale remembered token' => [false, 'remembered', false];
        yield 'CLI missing token' => [false, 'missing', false];
    }

    #[DataProvider('httpIdentities')]
    public function testOnlyFullyAuthenticatedHttpUuidSuppliesAnAccountActor(bool $http, string $token, bool $account): void
    {
        $id = Uuid::v7();
        if ($http) {
            $this->requests->push(new Request());
        }
        $user = new InMemoryUser('invalid' === $token ? 'email@example.test' : $id->toRfc4122(), null, ['ROLE_USER']);
        $this->tokens->setToken(match ($token) {
            'missing' => null,
            'remembered' => new RememberMeToken($user, 'main'),
            default => new UsernamePasswordToken($user, 'main', $user->getRoles()),
        });
        $actor = $this->readActor();
        self::assertSame($account ? ActorKind::Account : ActorKind::Anonymous, $actor->kind);
        self::assertSame($account, $actor->isAccount());
        self::assertEquals($account ? $id : null, $actor->accountId);
        self::assertNull($actor->scope);

        // Reusing the runtime after an HTTP request must not reuse its actor.
        if ($http) {
            $this->requests->pop();
            self::assertSame(ActorKind::Anonymous, $this->readActor()->kind);
        }
    }

    public function testActorIsImmutableAndPinnedAcrossPolicyAndHandlerNestedQueries(): void
    {
        $original = Uuid::v7();
        $replacement = Uuid::v7();
        $this->requests->push(new Request());
        $this->authenticate($original);
        $seen = [];
        $queries = new QueryBus($this->bus('query', [
            AuthorizationRuntimeSupportQuery::class => static function () use (&$seen): \Closure {
                return static function (object $message, PolicyContext $context) use (&$seen): bool {
                    $seen[] = $context;

                    return true;
                };
            },
        ], [AuthorizationRuntimeSupportQuery::class => static fn (): string => 'support']));
        $dispatch = $this->root('command', function (object $message, PolicyContext $context) use ($queries, $replacement, &$seen): bool {
            $seen[] = $context;
            // A later token change, including a subrequest, cannot change this root's identity.
            $this->requests->push(new Request());
            $this->authenticate($replacement);
            self::assertSame('support', $queries->ask(new AuthorizationRuntimeSupportQuery()));

            return true;
        }, static function () use ($queries): string {
            self::assertSame('support', $queries->ask(new AuthorizationRuntimeSupportQuery()));

            return 'handled';
        });
        self::assertSame('handled', $dispatch());
        self::assertCount(3, $seen);
        [$root, $policyRead, $handlerRead] = $seen;
        self::assertSame($root->actor, $policyRead->actor);
        self::assertSame($root->actor, $handlerRead->actor);
        self::assertEquals($original, $root->actor->accountId);
        self::assertFalse($root->supportRead);
        self::assertNull($root->caller);
        self::assertTrue($policyRead->supportRead);
        self::assertFalse($handlerRead->supportRead);
        self::assertSame(AuthorizationRuntimeCommand::class, $policyRead->caller);
        self::assertSame(AuthorizationRuntimeCommand::class, $handlerRead->caller);
        foreach (['kind' => ActorKind::Operator, 'accountId' => $replacement, 'scope' => 'accounts'] as $property => $value) {
            try {
                new \ReflectionProperty(Actor::class, $property)->setValue($root->actor, $value);
                self::fail('Policy code must not be able to mutate the actor.');
            } catch (\Error $failure) {
                self::assertStringContainsString('readonly property', $failure->getMessage());
            }
        }
        self::assertEquals($original, $root->actor->accountId);
        $this->requests->pop();
        self::assertEquals($replacement, $this->readActor()->accountId);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function scopedExecutions(): iterable
    {
        foreach (['accounts', 'assignments', 'tasks', 'authentication'] as $scope) {
            yield $scope.' success' => [$scope, false];
            yield $scope.' failure' => [$scope, true];
        }
    }

    #[DataProvider('scopedExecutions')]
    public function testExplicitScopeOverridesHttpIdentityAndRestoresItOnSuccessOrFailure(string $scope, bool $fail): void
    {
        $httpId = Uuid::v7();
        $boundId = Uuid::v7();
        $this->requests->push(new Request());
        $this->authenticate($httpId);
        $failure = new \RuntimeException(self::PAYLOAD);
        $dispatch = $this->root('query', static function (object $message, PolicyContext $context) use ($scope, $boundId): bool {
            self::assertSame('authentication' === $scope ? ActorKind::Authentication : ActorKind::Operator, $context->actor->kind);
            self::assertSame('authentication' === $scope ? $boundId : null, $context->actor->accountId);
            self::assertSame('authentication' === $scope ? null : $scope, $context->actor->scope);
            self::assertSame('authentication' !== $scope, $context->actor->isOperator($scope));
            self::assertFalse($context->actor->isAccount());

            return true;
        }, static function () use ($fail, $failure): string {
            if ($fail) {
                throw $failure;
            }

            return 'scoped result';
        });
        try {
            self::assertSame('scoped result', $this->inScope($scope, $boundId, $dispatch));
            self::assertFalse($fail);
        } catch (\RuntimeException $caught) {
            self::assertTrue($fail);
            self::assertSame($failure, $caught);
        }
        $restored = $this->readActor();
        self::assertSame(ActorKind::Account, $restored->kind);
        self::assertEquals($httpId, $restored->accountId);
        self::assertNull($restored->scope);
        // A fresh, differently bound authentication operation must also be possible.
        $next = Uuid::v7();
        self::assertSame($next, $this->inScope('authentication', $next, fn (): Actor => $this->readActor())->accountId);
    }

    /** @return iterable<string, array{string, string}> */
    public static function nestedScopes(): iterable
    {
        foreach (['tasks', 'authentication'] as $outer) {
            foreach (['accounts', 'authentication'] as $inner) {
                yield $outer.' to '.$inner => [$outer, $inner];
            }
        }
    }

    #[DataProvider('nestedScopes')]
    public function testNestedOverridesAreRefusedAndLeaveTheOuterActorUnchanged(string $outer, string $inner): void
    {
        $id = Uuid::v7();
        $this->inScope($outer, $id, function () use ($outer, $inner, $id): void {
            $before = $this->readActor();
            $effects = [];
            try {
                $this->inScope($inner, Uuid::v7(), static function () use (&$effects): void {
                    $effects[] = 'privileged callback';
                });
                self::fail('Nested authority changes must fail even between bus calls.');
            } catch (\LogicException $failure) {
                self::assertSame('authorization.context: Authority cannot change during execution.', $failure->getMessage());
            }
            self::assertSame([], $effects);
            $after = $this->readActor();
            self::assertSame($before, $after);
            self::assertSame('authentication' === $outer ? $id : null, $after->accountId);
        });
        self::assertSame(ActorKind::Anonymous, $this->readActor()->kind);
    }

    /** @return iterable<string, array{string, string}> */
    public static function rootScopes(): iterable
    {
        foreach (['command', 'query'] as $kind) {
            foreach (['accounts', 'authentication'] as $scope) {
                yield $kind.' to '.$scope => [$kind, $scope];
            }
        }
    }

    #[DataProvider('rootScopes')]
    public function testCaughtAuthorityChangeInsidePolicyStillDeniesTheRootHandler(string $kind, string $scope): void
    {
        $dispatch = $this->root($kind, function (object $message, PolicyContext $context) use ($scope): bool {
            $effects = [];
            try {
                $this->inScope($scope, Uuid::v7(), static function () use (&$effects): void {
                    $effects[] = 'privileged callback';
                });
                self::fail('The authority change must throw.');
            } catch (\LogicException $failure) {
                self::assertSame('authorization.context: Authority cannot change during execution.', $failure->getMessage());
            }
            self::assertSame([], $effects);
            self::assertSame(ActorKind::Anonymous, $context->actor->kind);

            return true;
        }, static fn () => self::fail('Catching the scope error must not admit the handler.'));
        try {
            $dispatch();
            self::fail('The caught policy failure must invalidate the root.');
        } catch (\LogicException $failure) {
            self::assertSame('authorization.context: Authority cannot change during execution.', $failure->getMessage());
        }
        self::assertSame(ActorKind::Anonymous, $this->readActor()->kind);
    }

    public function testUnknownOperatorScopeNeverRunsItsCallback(): void
    {
        $effects = [];
        try {
            $this->inScope('unrestricted', Uuid::v7(), static function () use (&$effects): void {
                $effects[] = 'privileged callback';
            });
            self::fail('Unknown operator scope must fail.');
        } catch (\LogicException $failure) {
            self::assertSame('authorization.context: Unknown operator scope.', $failure->getMessage());
        }
        self::assertSame([], $effects);
        self::assertSame(ActorKind::Anonymous, $this->readActor()->kind);
    }

    #[DataProvider('rootScopes')]
    public function testHandlerCannotEstablishANewAuthorityScope(string $kind, string $scope): void
    {
        $dispatch = $this->root($kind, static fn (): bool => true, function () use ($scope): void {
            $this->inScope($scope, Uuid::v7(), static function (): void {
                self::fail('A handler must not execute a newly privileged callback.');
            });
        });
        try {
            $dispatch();
            self::fail('Authority must remain fixed after the policy has returned.');
        } catch (\LogicException $failure) {
            self::assertSame('authorization.context: Authority cannot change during execution.', $failure->getMessage());
        }
        self::assertSame(ActorKind::Anonymous, $this->readActor()->kind);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function policyWrites(): iterable
    {
        foreach (['command', 'query'] as $root) {
            foreach (['construction', 'invocation', 'support query handler'] as $phase) {
                foreach (['command', 'event'] as $write) {
                    yield $root.' '.$phase.' attempts '.$write => [$root, $phase, $write];
                }
            }
        }
    }

    #[DataProvider('policyWrites')]
    public function testPolicyConstructionAndInvocationCannotWriteEvenThroughNestedQuery(string $kind, string $phase, string $write): void
    {
        $effects = [];
        $commands = new CommandBus($this->bus('command', [
            AuthorizationRuntimeWriteCommand::class => static fn () => static fn (): bool => true,
        ], [AuthorizationRuntimeWriteCommand::class => static function () use (&$effects): string {
            $effects[] = 'command';

            return 'written';
        }]));
        $events = new EventBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            AuthorizationRuntimeEvent::class => [static function () use (&$effects): void {
                $effects[] = 'event';
            }],
        ]))]), $this->invocation);
        $attempt = static function () use ($write, $commands, $events): void {
            try {
                if ('command' === $write) {
                    $commands->dispatch(new AuthorizationRuntimeWriteCommand());
                } else {
                    $events->dispatch(new AuthorizationRuntimeEvent());
                }
                self::fail('A policy write must fail before dispatch effects.');
            } catch (\LogicException $failure) {
                self::assertSame('authorization.policy_write: Policies cannot dispatch '.('command' === $write ? 'commands.' : 'events.'), $failure->getMessage());
            }
        };
        $queries = new QueryBus($this->bus('query', [
            AuthorizationRuntimeSupportQuery::class => static fn () => static fn (): bool => true,
        ], [AuthorizationRuntimeSupportQuery::class => static function () use ($attempt): bool {
            $attempt();

            return true;
        }]));
        $factory = static fn () => new AuthorizationRuntimePolicy(
            'construction' === $phase ? $attempt : static function (): void {},
            static function () use ($phase, $attempt, $queries): bool {
                if ('invocation' === $phase) {
                    $attempt();
                } elseif ('support query handler' === $phase) {
                    self::assertTrue($queries->ask(new AuthorizationRuntimeSupportQuery()));
                }

                return true;
            },
        );
        $dispatch = $this->rootFactory($kind, $factory, static fn () => self::fail('A caught policy write must never permit the target handler.'));
        try {
            $dispatch();
            self::fail('The root must retain the caught write failure.');
        } catch (\LogicException $failure) {
            self::assertSame('authorization.policy_write: Policies cannot dispatch '.('command' === $write ? 'commands.' : 'events.'), $failure->getMessage());
        }
        self::assertSame([], $effects);
        // This independent command proves failure and restrictive policy scope both unwind.
        self::assertSame('written', $commands->dispatch(new AuthorizationRuntimeWriteCommand()));
        self::assertSame(['command'], $effects);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function nestedFailures(): iterable
    {
        foreach (['command', 'query'] as $kind) {
            yield $kind.' nested policy denial' => [$kind, true];
            yield $kind.' nested handler failure' => [$kind, false];
        }
    }

    #[DataProvider('nestedFailures')]
    public function testCaughtNestedQueryFailureNeverAdmitsRootAndIndependentInvocationRecovers(string $kind, bool $deny): void
    {
        $failure = new \RuntimeException(self::PAYLOAD);
        $commands = new CommandBus($this->bus('command', [
            AuthorizationRuntimeWriteCommand::class => static fn () => static fn (): bool => true,
        ], [AuthorizationRuntimeWriteCommand::class => static fn () => self::fail('Unwinding a support policy must not enable writes in the outer policy.')]));
        $queries = new QueryBus($this->bus('query', [
            AuthorizationRuntimeSupportQuery::class => static fn () => static fn (): bool => !$deny,
        ], [AuthorizationRuntimeSupportQuery::class => static function () use ($deny, $failure): never {
            self::assertFalse($deny, 'A denied support query must not invoke its handler.');
            throw $failure;
        }]));
        $caught = null;
        $dispatch = $this->root($kind, static function () use ($queries, $commands, &$caught): bool {
            try {
                $queries->ask(new AuthorizationRuntimeSupportQuery());
                self::fail('The nested query must fail.');
            } catch (\RuntimeException $failure) {
                $caught = $failure;
            }
            try {
                $commands->dispatch(new AuthorizationRuntimeWriteCommand());
                self::fail('The outer policy must remain read-only after a nested failure.');
            } catch (\LogicException $writeFailure) {
                self::assertSame('authorization.policy_write: Policies cannot dispatch commands.', $writeFailure->getMessage());
            }

            return true;
        }, static fn () => self::fail('A policy cannot authorize by swallowing a failed support query.'));
        try {
            $dispatch();
            self::fail('The root must propagate the retained nested failure.');
        } catch (\RuntimeException $propagated) {
            self::assertSame($caught, $propagated);
            if ($deny) {
                self::assertInstanceOf(AuthorizationDenied::class, $propagated);
                self::assertSame('Access denied.', $propagated->getMessage());
                self::assertNull($propagated->getPrevious());
            } else {
                self::assertSame($failure, $propagated);
            }
        }
        $recover = $this->root($kind, static function (object $message, PolicyContext $context): bool {
            self::assertFalse($context->supportRead);
            self::assertNull($context->caller);

            return true;
        }, static fn (): string => 'recovered');
        self::assertSame('recovered', $recover());
    }

    /**
     * Uses real invocation and authorization middleware. Only the lazy ORM reset is
     * stubbed: these tests exercise admission and effects without a database transaction.
     *
     * @param array<class-string, \Closure(): mixed> $factories
     * @param array<class-string, callable>          $handlers
     */
    private function bus(string $kind, array $factories, array $handlers): MessageBus
    {
        return new MessageBus([
            new InvocationMiddleware($this->invocation, self::createStub(ManagerRegistry::class), $kind, new NullLogger()),
            new AuthorizationMiddleware($this->invocation, $this->execution, new ServiceLocator($factories)),
            new HandleMessageMiddleware(new HandlersLocator(array_map(static fn (callable $handler): array => [$handler], $handlers))),
        ]);
    }

    /**
     * @param \Closure(): mixed $factory
     *
     * @return \Closure(): mixed
     */
    private function rootFactory(string $kind, \Closure $factory, callable $handler): \Closure
    {
        $message = 'command' === $kind ? new AuthorizationRuntimeCommand(self::PAYLOAD) : new AuthorizationRuntimeQuery(self::PAYLOAD);
        $bus = $this->bus($kind, [$message::class => $factory], [$message::class => $handler]);

        return 'command' === $kind
            ? static fn () => new CommandBus($bus)->dispatch($message)
            : static fn () => new QueryBus($bus)->ask($message);
    }

    /** @return \Closure(): mixed */
    private function root(string $kind, \Closure $policy, callable $handler): \Closure
    {
        return $this->rootFactory($kind, static fn () => $policy, $handler);
    }

    private function readActor(): Actor
    {
        $actor = null;
        $dispatch = $this->root('query', static function (object $message, PolicyContext $context) use (&$actor): bool {
            $actor = $context->actor;

            return true;
        }, static fn (): string => 'identity observed');
        self::assertSame('identity observed', $dispatch());
        self::assertInstanceOf(Actor::class, $actor);

        return $actor;
    }

    private function authenticate(Uuid $id): void
    {
        $this->tokens->setToken(new UsernamePasswordToken(new InMemoryUser($id->toRfc4122(), null, ['ROLE_USER']), 'main', ['ROLE_USER']));
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function inScope(string $scope, Uuid $id, callable $operation): mixed
    {
        return 'authentication' === $scope
            ? new AuthenticationExecution($this->execution)->run($id, $operation)
            : new OperatorExecution($this->execution)->run($scope, $operation);
    }
}

final readonly class AuthorizationRuntimeCommand
{
    public function __construct(public string $payload)
    {
    }
}

final readonly class AuthorizationRuntimeQuery
{
    public function __construct(public string $payload)
    {
    }
}

final readonly class AuthorizationRuntimeSupportQuery
{
}

final readonly class AuthorizationRuntimeWriteCommand
{
}

final readonly class AuthorizationRuntimeEvent extends ApplicationEvent
{
}

final readonly class AuthorizationRuntimePolicy
{
    /**
     * @param \Closure(): void $construct
     * @param \Closure(): bool $invoke
     */
    public function __construct(\Closure $construct, private \Closure $invoke)
    {
        $construct();
    }

    public function __invoke(object $message, PolicyContext $context): bool
    {
        return ($this->invoke)();
    }
}
