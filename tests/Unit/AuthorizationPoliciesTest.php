<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use ApiPlatform\Metadata\Get;
use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistencePolicy;
use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceQuery;
use App\Module\Authenticating\Application\GetAccountIdentity\GetAccountIdentityPolicy;
use App\Module\Authenticating\Application\GetAccountIdentity\GetAccountIdentityQuery;
use App\Module\Authenticating\Application\RegisterAccount\RegisterAccountCommand;
use App\Module\Authenticating\Application\RegisterAccount\RegisterAccountPolicy;
use App\Module\Authenticating\Application\UpgradePasswordHash\UpgradePasswordHashCommand;
use App\Module\Authenticating\Application\UpgradePasswordHash\UpgradePasswordHashPolicy;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use App\Module\Authenticating\UI\Api\AccountIdentityProvider;
use App\Module\Authorizing\Application\ChangeAccountAssignments\ChangeAccountAssignmentsCommand;
use App\Module\Authorizing\Application\ChangeAccountAssignments\ChangeAccountAssignmentsPolicy;
use App\Module\Authorizing\Application\EvaluatePermissions\EvaluatePermissionsPolicy;
use App\Module\Authorizing\Application\EvaluatePermissions\EvaluatePermissionsQuery;
use App\Module\Authorizing\Application\EvaluatePermissions\EvaluatePermissionsResult;
use App\Module\Authorizing\Application\EvaluatePermissions\PermissionDecisionResult;
use App\Module\Authorizing\Application\ListAccountAssignments\ListAccountAssignmentsPolicy;
use App\Module\Authorizing\Application\ListAccountAssignments\ListAccountAssignmentsQuery;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskPolicy;
use App\Module\TaskTracking\Application\GetTask\GetTaskPolicy;
use App\Module\TaskTracking\Application\GetTask\GetTaskQuery;
use App\Module\TaskTracking\UI\Http\TaskController;
use App\Platform\Authorization\Actor;
use App\Platform\Authorization\ActorKind;
use App\Platform\Authorization\AuthorizationDenied;
use App\Platform\Authorization\PolicyContext;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\QueryBus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class AuthorizationPoliciesTest extends TestCase
{
    public function testIdentityAndUpgradeRequireTheirExactActorAndTarget(): void
    {
        $id = Uuid::v7();
        $other = Uuid::v7();
        $identity = new GetAccountIdentityPolicy();
        $upgrade = new UpgradePasswordHashPolicy();
        $query = new GetAccountIdentityQuery($id);
        $command = new UpgradePasswordHashCommand($id, 'old', 'new');
        foreach ([new Actor(ActorKind::Anonymous), new Actor(ActorKind::Account), new Actor(ActorKind::Account, $other), new Actor(ActorKind::Operator, scope: 'accounts')] as $actor) {
            self::assertFalse($identity($query, $this->context($actor)));
            self::assertFalse($upgrade($command, $this->context($actor)));
        }
        self::assertTrue($identity($query, $this->context(new Actor(ActorKind::Account, Uuid::fromString($id->toRfc4122())))));
        self::assertFalse($identity($query, $this->context(new Actor(ActorKind::Authentication, $id))));
        self::assertFalse($upgrade($command, $this->context(new Actor(ActorKind::Account, $id))));
        self::assertFalse($upgrade($command, $this->context(new Actor(ActorKind::Authentication, $other))));
        self::assertFalse($upgrade($command, $this->context(new Actor(ActorKind::Authentication))));
        self::assertTrue($upgrade($command, $this->context(new Actor(ActorKind::Authentication, $id))));
    }

    public function testRegistrationRequiresAccountsOperator(): void
    {
        $policy = new RegisterAccountPolicy();
        $command = new RegisterAccountCommand('member@example.test', 'private password');
        foreach ([new Actor(ActorKind::Anonymous), new Actor(ActorKind::Account, Uuid::v7()), new Actor(ActorKind::Authentication, Uuid::v7()), new Actor(ActorKind::Operator, scope: 'tasks'), new Actor(ActorKind::Operator, scope: 'assignments')] as $actor) {
            self::assertFalse($policy($command, $this->context($actor)));
        }
        self::assertTrue($policy($command, $this->context(new Actor(ActorKind::Operator, scope: 'accounts'))));
    }

    public function testFoundationReadsRequireSupportScopeOrExactApprovedCaller(): void
    {
        $id = Uuid::v7();
        $actor = new Actor(ActorKind::Account, $id);
        $evaluate = new EvaluatePermissionsPolicy();
        $existence = new CheckAccountExistencePolicy();
        $query = new EvaluatePermissionsQuery([]);
        $exists = new CheckAccountExistenceQuery([$id]);
        self::assertFalse($evaluate($query, $this->context($actor)));
        self::assertFalse($existence($exists, $this->context($actor)));
        self::assertTrue($evaluate($query, new PolicyContext($actor, true, CreateTaskCommand::class)));
        self::assertTrue($existence($exists, new PolicyContext($actor, true, CreateTaskCommand::class)));
        foreach ([EvaluatePermissionsQuery::class, ChangeAccountAssignmentsCommand::class] as $caller) {
            self::assertTrue($existence($exists, new PolicyContext($actor, false, $caller)));
        }
        self::assertFalse($existence($exists, new PolicyContext($actor, false, ListAccountAssignmentsQuery::class)));
        foreach (['accounts', 'tasks', 'assignments'] as $scope) {
            $context = $this->context(new Actor(ActorKind::Operator, scope: $scope));
            self::assertSame('assignments' === $scope, $evaluate($query, $context));
            self::assertFalse($existence($exists, $context));
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function permissionCases(): iterable
    {
        foreach (['change', 'list', 'create', 'view'] as $operation) {
            yield $operation.' allowed' => [$operation, true];
            yield $operation.' denied' => [$operation, false];
        }
    }

    #[DataProvider('permissionCases')]
    public function testPermissionChecksUseActorRatherThanTargetAndExactResource(string $operation, bool $allowed): void
    {
        $actorId = Uuid::v7();
        $target = Uuid::v7();
        $calls = 0;
        $queries = new QueryBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            EvaluatePermissionsQuery::class => [static function (EvaluatePermissionsQuery $query) use ($actorId, $target, $operation, $allowed, &$calls): EvaluatePermissionsResult {
                ++$calls;
                self::assertCount(1, $query->checks);
                $check = $query->checks[0];
                self::assertTrue($actorId->equals($check->accountId));
                self::assertFalse($target->equals($check->accountId));
                self::assertSame(match ($operation) {
                    'create' => 'task_tracking.task.create',
                    'view' => 'task_tracking.task.view',
                    default => 'authorizing.manage',
                }, $check->permission);
                self::assertSame('view' === $operation ? 'resource' : 'global', $check->scope);
                self::assertSame('view' === $operation ? 'task_tracking.task' : null, $check->resourceType);
                self::assertEquals('view' === $operation ? $target : null, $check->resourceId);

                return new EvaluatePermissionsResult([new PermissionDecisionResult($allowed)]);
            }],
        ]))]));
        [$policy, $message, $scope] = match ($operation) {
            'change' => [new ChangeAccountAssignmentsPolicy($queries), new ChangeAccountAssignmentsCommand($target, []), 'assignments'],
            'list' => [new ListAccountAssignmentsPolicy($queries), new ListAccountAssignmentsQuery($target), 'assignments'],
            'create' => [new CreateTaskPolicy($queries), new CreateTaskCommand('Example'), 'tasks'],
            default => [new GetTaskPolicy($queries), new GetTaskQuery($target->toRfc4122()), 'tasks'],
        };
        self::assertSame($allowed, $policy($message, $this->context(new Actor(ActorKind::Account, $actorId))));
        self::assertSame(1, $calls);
        foreach ([new Actor(ActorKind::Anonymous), new Actor(ActorKind::Account), new Actor(ActorKind::Authentication, $actorId), new Actor(ActorKind::Operator, scope: 'accounts')] as $actor) {
            self::assertFalse($policy($message, $this->context($actor)));
        }
        self::assertTrue($policy($message, $this->context(new Actor(ActorKind::Operator, scope: $scope))));
        self::assertFalse($policy($message, $this->context(new Actor(ActorKind::Operator, scope: 'tasks' === $scope ? 'assignments' : 'tasks'))));
        self::assertSame(1, $calls, 'Missing actors and operator scopes must not perform permission reads.');
    }

    public function testInvalidTaskIdDoesNotIssuePermissionRead(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        self::assertFalse(new GetTaskPolicy(new QueryBus($bus))(new GetTaskQuery('invalid'), $this->context(new Actor(ActorKind::Account, Uuid::v7()))));
    }

    /** @return iterable<string, array{bool, int}> */
    public static function denials(): iterable
    {
        yield 'anonymous' => [false, 401];
        yield 'authenticated' => [true, 403];
    }

    #[DataProvider('denials')]
    public function testHttpAdaptersMapDenialToFixedUncacheableResponse(bool $authenticated, int $status): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(3))->method('dispatch')->willThrowException(new AuthorizationDenied($authenticated));
        $controller = new TaskController(new CommandBus($bus), new QueryBus($bus), $this->createStub(UrlGeneratorInterface::class), new NullLogger());
        $request = Request::create('/_demo/tasks', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"title":"Example"}');
        foreach ([$controller->create($request), $controller->show(Uuid::v7()->toRfc4122())] as $response) {
            self::assertSame($status, $response->getStatusCode());
            $content = $response->getContent();
            self::assertIsString($content);
            self::assertSame(['error' => 'Access denied.'], json_decode($content, true, 512, JSON_THROW_ON_ERROR));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        }
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new AccountPrincipal(Uuid::v7(), 'member@example.test', 'hash'), 'api'));
        try {
            new AccountIdentityProvider($tokens, new QueryBus($bus))->provide(new Get());
            self::fail('Expected authorization denial.');
        } catch (HttpExceptionInterface $failure) {
            self::assertSame($status, $failure->getStatusCode());
            self::assertSame('Access denied.', $failure->getMessage());
            self::assertSame('no-store', $failure->getHeaders()['Cache-Control']);
        }
    }

    private function context(Actor $actor): PolicyContext
    {
        return new PolicyContext($actor, false, null);
    }
}
