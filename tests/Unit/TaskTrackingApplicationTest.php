<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authenticating\Application\CheckAccountExistence\AccountExistenceResult;
use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceQuery;
use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceResult;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EntitlementDecisionResult;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsQuery;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsResult;
use App\Module\TaskTracking\Application\CompleteTask\CompleteTaskCommand;
use App\Module\TaskTracking\Application\CompleteTask\CompleteTaskHandler;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskHandler;
use App\Module\TaskTracking\Application\CreateTask\TaskCreatedEvent;
use App\Module\TaskTracking\Application\GetTask\GetTaskHandler;
use App\Module\TaskTracking\Application\GetTask\GetTaskQuery;
use App\Module\TaskTracking\Application\ListTasks\ListTasksHandler;
use App\Module\TaskTracking\Application\ListTasks\ListTasksQuery;
use App\Module\TaskTracking\Application\ListTasks\ListTasksResult;
use App\Module\TaskTracking\Application\ListTasks\TaskListItemResult;
use App\Module\TaskTracking\Domain\InvalidTaskInput;
use App\Module\TaskTracking\Domain\Task;
use App\Module\TaskTracking\Domain\TaskListCursor;
use App\Module\TaskTracking\Domain\TaskPermission;
use App\Module\TaskTracking\Domain\TaskRepository;
use App\Module\TaskTracking\Infrastructure\Framework\Symfony\Security\TaskTrackingVoter;
use App\Platform\Authorization\Actor;
use App\Platform\Authorization\ActorKind;
use App\Platform\Authorization\AuthorizationToken;
use App\Platform\Messaging\EventBus;
use App\Platform\Messaging\InvocationContext;
use App\Platform\Messaging\QueryBus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validation;

final class TaskTrackingApplicationTest extends TestCase
{
    public function testOwnedCreationOnlyPersistsAndPublishesItsEvent(): void
    {
        $owner = Uuid::v7();
        $steps = [];
        $task = null;
        $repository = $this->createMock(TaskRepository::class);
        $repository->expects(self::once())->method('add')->willReturnCallback(static function (Task $added) use (&$steps, &$task, $owner): void {
            $steps[] = 'add';
            $task = $added;
            self::assertSame($owner, $task->ownerAccountId());
        });
        $events = $this->eventBus(static function (TaskCreatedEvent $event) use (&$steps, &$task): void {
            self::assertInstanceOf(Task::class, $task);
            self::assertSame(['add'], $steps);
            self::assertSame($task->id(), $event->taskId);
            $steps[] = 'event';
        });

        $id = new CreateTaskHandler($repository, $events)(new CreateTaskCommand('Owned', $owner));
        self::assertInstanceOf(Task::class, $task);
        self::assertSame($task->id(), $id);
        self::assertSame(['add', 'event'], $steps);
    }

    public function testOwnerlessCreationPersistsAndPublishesItsEvent(): void
    {
        $repository = $this->createMock(TaskRepository::class);
        $repository->expects(self::once())->method('add')->with(self::callback(static fn (Task $task): bool => null === $task->ownerAccountId()));
        $published = null;
        $id = new CreateTaskHandler($repository, $this->eventBus(static function (TaskCreatedEvent $event) use (&$published): void {
            $published = $event->taskId;
        }))(new CreateTaskCommand('Ownerless'));
        self::assertSame($id, $published);
    }

    public function testCompletionUsesLockingLookupAndPreservesRepeatedResultAndExpandedGet(): void
    {
        $task = new Task('Finish', Uuid::v7());
        $task->releaseEvents();
        $repository = $this->createMock(TaskRepository::class);
        $repository->expects(self::exactly(2))->method('findForCompletion')->with($task->id())->willReturn($task);
        $repository->expects(self::once())->method('find')->with($task->id())->willReturn($task);
        $handler = new CompleteTaskHandler($repository);
        $first = $handler(new CompleteTaskCommand($task->id()->toRfc4122()));
        self::assertNotNull($first);
        self::assertTrue($first->changed);
        self::assertSame('UTC', $first->completedAt->getTimezone()->getName());
        self::assertSame('000000', $first->completedAt->format('u'));
        $second = $handler(new CompleteTaskCommand($task->id()->toRfc4122()));
        self::assertNotNull($second);
        self::assertFalse($second->changed);
        self::assertSame($first->completedAt, $second->completedAt);
        $shown = new GetTaskHandler($repository)(new GetTaskQuery($task->id()->toRfc4122()));
        self::assertNotNull($shown);
        self::assertSame($task->ownerAccountId(), $shown->ownerAccountId);
        self::assertSame($first->completedAt, $shown->completedAt);
        self::assertSame([], $task->releaseEvents());
    }

    public function testMissingCompletionReturnsNull(): void
    {
        $id = Uuid::v7();
        $repository = $this->createMock(TaskRepository::class);
        $repository->expects(self::once())->method('findForCompletion')->with($id)->willReturn(null);
        self::assertNull(new CompleteTaskHandler($repository)(new CompleteTaskCommand($id->toRfc4122())));
    }

    public function testOperatorListingUsesOwningLookaheadAndCursor(): void
    {
        $tasks = [new Task('First'), new Task('Second'), new Task('Lookahead')];
        $repository = $this->createMock(TaskRepository::class);
        $repository->expects(self::once())->method('findPage')->with(2, null, null)->willReturn($tasks);
        $result = new ListTasksHandler($repository)(new ListTasksQuery(limit: 2));
        self::assertSame(['First', 'Second'], array_column($result->tasks, 'title'));
        self::assertTrue($tasks[1]->id()->equals(TaskListCursor::decode($result->next, null)));
    }

    public function testOwnerListingUsesOneFilteredRepositoryPage(): void
    {
        $owner = Uuid::v7();
        $after = Uuid::v7();
        $task = new Task('Last', $owner);
        $task->complete(new \DateTimeImmutable('2026-09-16T12:00:00Z'));
        $repository = $this->createMock(TaskRepository::class);
        $repository->expects(self::once())->method('findPage')->with(50, $after, $owner)->willReturn([$task]);
        $result = new ListTasksHandler($repository)(new ListTasksQuery($owner, after: TaskListCursor::encode($owner, $after)));
        self::assertCount(1, $result->tasks);
        self::assertSame($task->completedAt(), $result->tasks[0]->completedAt);
        self::assertSame($owner, $result->tasks[0]->ownerAccountId);
        self::assertNull($result->next);
    }

    public function testNativeVoterRequiresGlobalCapabilityAndExactTaskOwnership(): void
    {
        $account = Uuid::v7();
        $foreignOwner = Uuid::v7();
        $owned = new Task('Owned', $account);
        $foreign = new Task('Foreign', $foreignOwner);
        $unowned = new Task('Unowned');
        $tasks = $this->createStub(TaskRepository::class);
        $tasks->method('find')->willReturnCallback(static fn (Uuid $id): ?Task => match ($id->toRfc4122()) {
            $owned->id()->toRfc4122() => $owned,
            $foreign->id()->toRfc4122() => $foreign,
            $unowned->id()->toRfc4122() => $unowned,
            default => null,
        });
        $manager = $this->voterManager($tasks, true);
        $token = new AuthorizationToken(new Actor(ActorKind::Account, $account), false);

        self::assertTrue($manager->decide($token, [TaskTrackingVoter::class], new CreateTaskCommand('Self', $account)));
        self::assertFalse($manager->decide($token, [TaskTrackingVoter::class], new CreateTaskCommand('Unowned')));
        self::assertTrue($manager->decide($token, [TaskTrackingVoter::class], new ListTasksQuery($account)));
        self::assertFalse($manager->decide($token, [TaskTrackingVoter::class], new ListTasksQuery($foreignOwner)));
        self::assertTrue($manager->decide($token, [TaskTrackingVoter::class], new GetTaskQuery($owned->id()->toRfc4122())));
        self::assertTrue($manager->decide($token, [TaskTrackingVoter::class], new CompleteTaskCommand($owned->id()->toRfc4122())));
        foreach ([$foreign, $unowned] as $inaccessible) {
            self::assertFalse($manager->decide($token, [TaskTrackingVoter::class], new GetTaskQuery($inaccessible->id()->toRfc4122())));
            self::assertFalse($manager->decide($token, [TaskTrackingVoter::class], new CompleteTaskCommand($inaccessible->id()->toRfc4122())));
        }
        self::assertFalse($this->voterManager($tasks, false)->decide($token, [TaskTrackingVoter::class], new GetTaskQuery($owned->id()->toRfc4122())));
    }

    public function testTaskOperatorListsAnyOwnerWithoutBusinessReads(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $voter = new TaskTrackingVoter($this->voterPermissions(), new QueryBus($bus), $this->createStub(TaskRepository::class));
        $manager = new AccessDecisionManager([$voter]);
        $token = new AuthorizationToken(new Actor(ActorKind::Operator, scope: 'tasks'), false);

        self::assertTrue($manager->decide($token, [TaskTrackingVoter::class], new ListTasksQuery()));
        self::assertTrue($manager->decide($token, [TaskTrackingVoter::class], new ListTasksQuery(Uuid::v7())));
    }

    /** @return iterable<string, array{ListTasksQuery}> */
    public static function invalidLists(): iterable
    {
        yield 'zero' => [new ListTasksQuery(limit: 0)];
        yield 'over limit' => [new ListTasksQuery(limit: 101)];
        yield 'invalid cursor' => [new ListTasksQuery(after: 'bad-cursor')];
        yield 'target mismatch' => [new ListTasksQuery(after: TaskListCursor::encode(Uuid::v7(), Uuid::v7()))];
    }

    #[DataProvider('invalidLists')]
    public function testInvalidListFailsBeforeAnyReads(ListTasksQuery $query): void
    {
        $repository = $this->createMock(TaskRepository::class);
        $repository->expects(self::never())->method('findPage');
        $this->expectException(InvalidTaskInput::class);
        new ListTasksHandler($repository)($query);
    }

    public function testNativeValidationRejectsInvalidPageAndTaskData(): void
    {
        $validator = Validation::createValidatorBuilder()->addYamlMapping(dirname(__DIR__, 2).'/src/Module/TaskTracking/Resources/config/validation.yaml')->getValidator();
        self::assertCount(0, $validator->validate(new CreateTaskCommand('Valid', Uuid::v7())));
        self::assertGreaterThan(0, count($validator->validate(new CreateTaskCommand("invalid\0title"))));
        self::assertGreaterThan(0, count($validator->validate(new CompleteTaskCommand('bad-id'))));
        self::assertGreaterThan(0, count($validator->validate(new ListTasksQuery(limit: 101))));
        self::assertGreaterThan(0, count($validator->validate(new ListTasksQuery(after: str_repeat('a', 513)))));
    }

    public function testNativeResultMetadataBoundsCollectionsAndCascadesIntoItems(): void
    {
        $validator = Validation::createValidatorBuilder()->addYamlMapping(dirname(__DIR__, 2).'/src/Module/TaskTracking/Resources/config/validation.yaml')->getValidator();
        $item = new TaskListItemResult(Uuid::v7(), 'Valid', Uuid::v7(), new \DateTimeImmutable('2026-09-16T00:00:00Z'));
        self::assertCount(0, $validator->validate(new ListTasksResult(array_fill(0, 100, $item), null)));
        self::assertGreaterThan(0, count($validator->validate(new ListTasksResult(array_fill(0, 101, $item), null))));
        self::assertGreaterThan(0, count($validator->validate(new ListTasksResult([new TaskListItemResult(Uuid::v7(), "bad\0title")], null))));
    }

    private function eventBus(\Closure $handler): EventBus
    {
        $context = new InvocationContext();
        $context->enter('command');
        $context->startTransaction(static function (): void {});
        $context->handlerTime(true);

        return new EventBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([TaskCreatedEvent::class => [$handler]]))]), $context);
    }

    private function voterManager(TaskRepository $tasks, bool $allowed): AccessDecisionManager
    {
        $queries = new QueryBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            CheckAccountExistenceQuery::class => [static fn (CheckAccountExistenceQuery $query): CheckAccountExistenceResult => new CheckAccountExistenceResult(array_map(
                static fn (Uuid $accountId): AccountExistenceResult => new AccountExistenceResult($accountId, true),
                $query->accountIds,
            ))],
            EvaluateSubjectEntitlementsQuery::class => [static fn (EvaluateSubjectEntitlementsQuery $query): EvaluateSubjectEntitlementsResult => new EvaluateSubjectEntitlementsResult(array_map(
                static fn (): EntitlementDecisionResult => new EntitlementDecisionResult($allowed),
                $query->checks,
            ))],
        ]))]));

        return new AccessDecisionManager([new TaskTrackingVoter($this->voterPermissions(), $queries, $tasks)]);
    }

    /** @return array<class-string, string> */
    private function voterPermissions(): array
    {
        return [
            CompleteTaskCommand::class => TaskPermission::Complete->value,
            CreateTaskCommand::class => TaskPermission::Create->value,
            GetTaskQuery::class => TaskPermission::View->value,
            ListTasksQuery::class => TaskPermission::View->value,
        ];
    }
}
