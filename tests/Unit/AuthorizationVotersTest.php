<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authenticating\Application\CheckAccountExistence\AccountExistenceResult;
use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceQuery;
use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceResult;
use App\Module\Authenticating\Application\RegisterAccount\RegisterAccountCommand;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AuthenticatingVoter;
use App\Module\Authorizing\Application\ChangeSubjectAssignments\ChangeSubjectAssignmentsCommand;
use App\Module\Authorizing\Application\DefineRole\DefineRoleCommand;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EntitlementDecisionResult;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsQuery;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsResult;
use App\Module\Authorizing\Infrastructure\Framework\Symfony\Security\AuthorizingVoter;
use App\Module\TaskTracking\Application\CompleteTask\CompleteTaskCommand;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Application\GetTask\GetTaskQuery;
use App\Module\TaskTracking\Application\ListTasks\ListTasksQuery;
use App\Module\TaskTracking\Domain\Task;
use App\Module\TaskTracking\Domain\TaskPermission;
use App\Module\TaskTracking\Domain\TaskRepository;
use App\Module\TaskTracking\Infrastructure\Framework\Symfony\Security\TaskTrackingVoter;
use App\Platform\Authorization\Actor;
use App\Platform\Authorization\ActorKind;
use App\Platform\Authorization\AuthorizationToken;
use App\Platform\Messaging\QueryBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Strategy\UnanimousStrategy;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Uid\Uuid;

final class AuthorizationVotersTest extends TestCase
{
    public function testAuthenticatingVoterEnforcesExactActorsAndSupportReads(): void
    {
        $manager = $this->manager(new AuthenticatingVoter([
            RegisterAccountCommand::class => null,
            CheckAccountExistenceQuery::class => null,
        ]));
        $message = new RegisterAccountCommand('member@example.test', 'private password');
        self::assertTrue($manager->decide($this->token(new Actor(ActorKind::Operator, scope: 'accounts')), [AuthenticatingVoter::class], $message));
        self::assertFalse($manager->decide($this->token(new Actor(ActorKind::Operator, scope: 'tasks')), [AuthenticatingVoter::class], $message));

        $exists = new CheckAccountExistenceQuery([Uuid::v7()]);
        self::assertTrue($manager->decide($this->token(new Actor(ActorKind::Anonymous), true), [AuthenticatingVoter::class], $exists));
        self::assertFalse($manager->decide($this->token(new Actor(ActorKind::Anonymous)), [AuthenticatingVoter::class], $exists));
        self::assertFalse($manager->decide($this->token(new Actor(ActorKind::Operator, scope: 'accounts')), [TaskTrackingVoter::class], $message));
    }

    public function testAuthorizingManagementAndCatalogueUseGlobalEntitlementsAndExactVoterAttribute(): void
    {
        $account = Uuid::v7();
        $queries = $this->permissionBus($account, 'authorizing.manage', true);
        $manager = $this->manager(new AuthorizingVoter([
            ChangeSubjectAssignmentsCommand::class => 'authorizing.manage',
            DefineRoleCommand::class => 'authorizing.catalogue.manage',
            EvaluateSubjectEntitlementsQuery::class => null,
        ], $queries));
        $target = Uuid::v7();
        self::assertTrue($manager->decide($this->token(new Actor(ActorKind::Operator, scope: 'assignments')), [AuthorizingVoter::class], new ChangeSubjectAssignmentsCommand($target, [])));
        self::assertFalse($manager->decide($this->token(new Actor(ActorKind::Operator, scope: 'assignments')), [AuthorizingVoter::class], new DefineRoleCommand('example.reader', 'Reader', ['authorizing.manage'])));
        self::assertTrue($manager->decide($this->token(new Actor(ActorKind::Operator, scope: 'catalogue')), [AuthorizingVoter::class], new DefineRoleCommand('example.reader', 'Reader', ['authorizing.manage'])));
        self::assertTrue($manager->decide($this->token(new Actor(ActorKind::Account, $account)), [AuthorizingVoter::class], new ChangeSubjectAssignmentsCommand($target, [])));
        self::assertFalse($manager->decide($this->token(new Actor(ActorKind::Anonymous)), [AuthorizingVoter::class], new EvaluateSubjectEntitlementsQuery([])));
        self::assertTrue($manager->decide($this->token(new Actor(ActorKind::Anonymous), true), [AuthorizingVoter::class], new EvaluateSubjectEntitlementsQuery([])));
        self::assertFalse($manager->decide($this->token(new Actor(ActorKind::Operator, scope: 'assignments')), [AuthenticatingVoter::class], new ChangeSubjectAssignmentsCommand($target, [])));
    }

    public function testTaskVoterRequiresGlobalPermissionAndExactOwnershipWhileOperatorsBypass(): void
    {
        $account = Uuid::v7();
        $other = Uuid::v7();
        $owned = new Task('Owned', $account);
        $foreign = new Task('Foreign', $other);
        $unowned = new Task('Unowned');
        $permissions = [
            CreateTaskCommand::class => TaskPermission::Create->value,
            GetTaskQuery::class => TaskPermission::View->value,
            ListTasksQuery::class => TaskPermission::View->value,
            CompleteTaskCommand::class => TaskPermission::Complete->value,
        ];
        $token = $this->token(new Actor(ActorKind::Account, $account));

        $manager = $this->manager(new TaskTrackingVoter($permissions, $this->taskBus($account, true, true), $this->tasks($owned)));
        self::assertTrue($manager->decide($token, [TaskTrackingVoter::class], new CreateTaskCommand('Example', $account)));
        self::assertFalse($manager->decide($token, [TaskTrackingVoter::class], new CreateTaskCommand('Example', $other)));
        self::assertTrue($manager->decide($token, [TaskTrackingVoter::class], new GetTaskQuery($owned->id()->toRfc4122())));
        self::assertTrue($manager->decide($token, [TaskTrackingVoter::class], new CompleteTaskCommand($owned->id()->toRfc4122())));
        self::assertTrue($manager->decide($token, [TaskTrackingVoter::class], new ListTasksQuery($account)));
        self::assertFalse($manager->decide($token, [TaskTrackingVoter::class], new ListTasksQuery($other)));
        self::assertFalse($manager->decide($token, [TaskTrackingVoter::class], new ListTasksQuery()));

        foreach ([$foreign, $unowned, null] as $task) {
            $denied = $this->manager(new TaskTrackingVoter($permissions, $this->taskBus($account, true, true), $this->tasks($task)));
            self::assertFalse($denied->decide($token, [TaskTrackingVoter::class], new GetTaskQuery(Uuid::v7()->toRfc4122())));
        }
        $withoutCapability = $this->manager(new TaskTrackingVoter($permissions, $this->taskBus($account, true, false), $this->tasks($owned)));
        self::assertFalse($withoutCapability->decide($token, [TaskTrackingVoter::class], new GetTaskQuery($owned->id()->toRfc4122())));
        $missingAccount = $this->manager(new TaskTrackingVoter($permissions, $this->taskBus($account, false, true), $this->tasks($owned)));
        self::assertFalse($missingAccount->decide($token, [TaskTrackingVoter::class], new GetTaskQuery($owned->id()->toRfc4122())));

        $operator = $this->token(new Actor(ActorKind::Operator, scope: 'tasks'));
        self::assertTrue($manager->decide($operator, [TaskTrackingVoter::class], new CreateTaskCommand('Unowned')));
        self::assertTrue($manager->decide($operator, [TaskTrackingVoter::class], new ListTasksQuery()));
        self::assertTrue($manager->decide($operator, [TaskTrackingVoter::class], new GetTaskQuery(Uuid::v7()->toRfc4122())));
        self::assertFalse($manager->decide($operator, [AuthenticatingVoter::class], new GetTaskQuery($owned->id()->toRfc4122())));
    }

    public function testSupportedVoterSubjectRejectsAnyNonAuthorizationToken(): void
    {
        $message = new RegisterAccountCommand('member@example.test', 'private password');
        $manager = $this->manager(new AuthenticatingVoter([$message::class => null]));
        $user = new InMemoryUser('member', null, ['ROLE_USER']);
        self::assertFalse($manager->decide(new UsernamePasswordToken($user, 'main', $user->getRoles()), [AuthenticatingVoter::class], $message));
    }

    private function manager(VoterInterface $voter): AccessDecisionManager
    {
        return new AccessDecisionManager([$voter], new UnanimousStrategy(false));
    }

    private function token(Actor $actor, bool $supportRead = false): AuthorizationToken
    {
        return new AuthorizationToken($actor, $supportRead);
    }

    private function permissionBus(Uuid $subjectId, string $permission, bool $allowed): QueryBus
    {
        return new QueryBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            EvaluateSubjectEntitlementsQuery::class => [static function (EvaluateSubjectEntitlementsQuery $query) use ($subjectId, $permission, $allowed): EvaluateSubjectEntitlementsResult {
                self::assertCount(1, $query->checks);
                self::assertTrue($subjectId->equals($query->checks[0]->subjectId));
                self::assertSame($permission, $query->checks[0]->permission);

                return new EvaluateSubjectEntitlementsResult([new EntitlementDecisionResult($allowed)]);
            }],
        ]))]));
    }

    private function taskBus(Uuid $accountId, bool $exists, bool $allowed): QueryBus
    {
        return new QueryBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            CheckAccountExistenceQuery::class => [static fn (): CheckAccountExistenceResult => new CheckAccountExistenceResult([new AccountExistenceResult($accountId, $exists)])],
            EvaluateSubjectEntitlementsQuery::class => [static function (EvaluateSubjectEntitlementsQuery $query) use ($accountId, $allowed): EvaluateSubjectEntitlementsResult {
                self::assertCount(1, $query->checks);
                self::assertTrue($accountId->equals($query->checks[0]->subjectId));
                self::assertContains($query->checks[0]->permission, array_column(TaskPermission::cases(), 'value'));

                return new EvaluateSubjectEntitlementsResult([new EntitlementDecisionResult($allowed)]);
            }],
        ]))]));
    }

    private function tasks(?Task $task): TaskRepository
    {
        $repository = $this->createStub(TaskRepository::class);
        $repository->method('find')->willReturn($task);

        return $repository;
    }
}
