<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authenticating\Application\CheckAccountExistence\AccountExistenceResult;
use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceQuery;
use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceResult;
use App\Module\Authorizing\Application\ChangeAccountAssignments\AssignmentChangeInput;
use App\Module\Authorizing\Application\ChangeAccountAssignments\ChangeAccountAssignmentsCommand;
use App\Module\Authorizing\Application\ChangeAccountAssignments\ChangeAccountAssignmentsHandler;
use App\Module\Authorizing\Application\EvaluatePermissions\EvaluatePermissionsHandler;
use App\Module\Authorizing\Application\EvaluatePermissions\EvaluatePermissionsQuery;
use App\Module\Authorizing\Application\EvaluatePermissions\PermissionCheckInput;
use App\Module\Authorizing\Application\ListAccountAssignments\AccountAssignmentResult;
use App\Module\Authorizing\Application\ListAccountAssignments\AssignmentCursorInput;
use App\Module\Authorizing\Application\ListAccountAssignments\ListAccountAssignmentsHandler;
use App\Module\Authorizing\Application\ListAccountAssignments\ListAccountAssignmentsQuery;
use App\Module\Authorizing\Application\ListAccountAssignments\ListAccountAssignmentsResult;
use App\Module\Authorizing\Domain\Assignment;
use App\Module\Authorizing\Domain\AssignmentChange;
use App\Module\Authorizing\Domain\AssignmentChanges;
use App\Module\Authorizing\Domain\AssignmentRepository;
use App\Module\Authorizing\Domain\AuthorizationCatalog;
use App\Module\Authorizing\Domain\InvalidAuthorizationInput;
use App\Module\Authorizing\Domain\PermissionCheck;
use App\Platform\Messaging\QueryBus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validation;

final class AuthorizingApplicationTest extends TestCase
{
    #[DataProvider('duplicateOperations')]
    public function testDuplicateNaturalKeysFailBeforeAnyReadLockOrWrite(string $secondOperation): void
    {
        $accountId = Uuid::v7();
        $resourceId = Uuid::v7();
        $assignments = $this->createMock(AssignmentRepository::class);
        $assignments->expects(self::never())->method('lockAccount');
        $assignments->expects(self::never())->method('change');
        $handler = new ChangeAccountAssignmentsHandler($assignments, new AuthorizationCatalog(), $this->unusedQueryBus());

        $this->expectException(InvalidAuthorizationInput::class);
        $handler(new ChangeAccountAssignmentsCommand($accountId, [
            new AssignmentChangeInput('add', 'role', 'task_tracking.reader', 'resource', 'task_tracking.task', $resourceId),
            new AssignmentChangeInput($secondOperation, 'role', 'task_tracking.reader', 'resource', 'task_tracking.task', Uuid::fromString($resourceId->toRfc4122())),
        ]));
    }

    /** @return iterable<string, array{string}> */
    public static function duplicateOperations(): iterable
    {
        yield 'identical' => ['add'];
        yield 'conflicting' => ['remove'];
    }

    public function testInvalidLastChangePreventsEarlierValidChangesFromReachingPersistence(): void
    {
        $assignments = $this->createMock(AssignmentRepository::class);
        $assignments->expects(self::never())->method('lockAccount');
        $assignments->expects(self::never())->method('change');
        $handler = new ChangeAccountAssignmentsHandler($assignments, new AuthorizationCatalog(), $this->unusedQueryBus());

        $this->expectException(InvalidAuthorizationInput::class);
        $handler(new ChangeAccountAssignmentsCommand(Uuid::v7(), [
            new AssignmentChangeInput('remove', 'role', 'retired.role', 'global'),
            new AssignmentChangeInput('add', 'role', 'task_tracking.creator', 'resource', 'task_tracking.task', Uuid::v7()),
        ]));
    }

    public function testMissingAccountRejectsEntireMixedBatchBeforeLockOrDmlWithFixedError(): void
    {
        $id = Uuid::v7();
        $calls = 0;
        $queries = $this->queryBus(static function (CheckAccountExistenceQuery $query) use ($id, &$calls): CheckAccountExistenceResult {
            ++$calls;
            self::assertSame([$id], $query->accountIds);

            return new CheckAccountExistenceResult([new AccountExistenceResult($id, false)]);
        });
        $assignments = $this->createMock(AssignmentRepository::class);
        $assignments->expects(self::never())->method('lockAccount');
        $assignments->expects(self::never())->method('change');

        try {
            new ChangeAccountAssignmentsHandler($assignments, new AuthorizationCatalog(), $queries)(new ChangeAccountAssignmentsCommand($id, [
                new AssignmentChangeInput('remove', 'role', 'retired.role', 'global'),
                new AssignmentChangeInput('add', 'role', 'task_tracking.reader', 'global'),
            ]));
            self::fail('A missing account must reject the batch.');
        } catch (\DomainException $failure) {
            self::assertSame('authorizing.account: Account does not exist.', $failure->getMessage());
        }
        self::assertSame(1, $calls);
    }

    public function testRemovalOnlySupportsRetiredOrphansAndLocksOnceBeforeDml(): void
    {
        $id = Uuid::v7();
        $locked = false;
        $assignments = $this->createMock(AssignmentRepository::class);
        $assignments->expects(self::once())->method('lockAccount')->with($id)->willReturnCallback(static function () use (&$locked): void {
            $locked = true;
        });
        $assignments->expects(self::once())->method('change')->with($id, self::callback(static function (mixed $changes) use (&$locked): bool {
            self::assertTrue($locked);
            self::assertIsArray($changes);
            self::assertCount(2, $changes);
            self::assertInstanceOf(AssignmentChange::class, $changes[0]);
            self::assertInstanceOf(AssignmentChange::class, $changes[1]);
            self::assertSame('retired.role', $changes[0]->key);
            self::assertSame('retired.resource', $changes[1]->resourceType);

            return true;
        }))->willReturn(new AssignmentChanges(0, 1, 1));

        $result = new ChangeAccountAssignmentsHandler($assignments, new AuthorizationCatalog(), $this->unusedQueryBus())(new ChangeAccountAssignmentsCommand($id, [
            new AssignmentChangeInput('remove', 'role', 'retired.role', 'global'),
            new AssignmentChangeInput('remove', 'permission', 'retired.permission', 'resource', 'retired.resource', Uuid::v7()),
        ]));

        self::assertSame(['requested' => 2, 'added' => 0, 'removed' => 1, 'unchanged' => 1], get_object_vars($result));
    }

    public function testMixedChangesKeepExactSourceCoordinatesAndObserveAccountBeforeLockAndDml(): void
    {
        $id = Uuid::v7();
        $resourceId = Uuid::v7();
        $observed = false;
        $locked = false;
        $queries = $this->queryBus(static function (CheckAccountExistenceQuery $query) use ($id, &$observed): CheckAccountExistenceResult {
            self::assertSame([$id], $query->accountIds);
            $observed = true;

            return new CheckAccountExistenceResult([new AccountExistenceResult($id, true)]);
        });
        $assignments = $this->createMock(AssignmentRepository::class);
        $assignments->expects(self::once())->method('lockAccount')->with($id)->willReturnCallback(static function () use (&$observed, &$locked): void {
            self::assertTrue($observed);
            $locked = true;
        });
        $assignments->expects(self::once())->method('change')->with($id, self::callback(static function (mixed $changes) use ($resourceId, &$locked): bool {
            self::assertTrue($locked);
            self::assertIsArray($changes);
            self::assertCount(2, $changes);
            self::assertInstanceOf(AssignmentChange::class, $changes[0]);
            self::assertInstanceOf(AssignmentChange::class, $changes[1]);
            self::assertSame(['add', 'role', 'task_tracking.reader', 'resource', 'task_tracking.task', $resourceId], array_values(get_object_vars($changes[0])));
            self::assertSame(['remove', 'permission', 'task_tracking.task.view', 'global', null, null], array_values(get_object_vars($changes[1])));

            return true;
        }))->willReturn(new AssignmentChanges(1, 1, 0));

        $result = new ChangeAccountAssignmentsHandler($assignments, new AuthorizationCatalog(), $queries)(new ChangeAccountAssignmentsCommand($id, [
            new AssignmentChangeInput('add', 'role', 'task_tracking.reader', 'resource', 'task_tracking.task', $resourceId),
            new AssignmentChangeInput('remove', 'permission', 'task_tracking.task.view', 'global'),
        ]));

        self::assertSame(['requested' => 2, 'added' => 1, 'removed' => 1, 'unchanged' => 0], get_object_vars($result));
    }

    public function testOneHundredChecksUseOneDeduplicatedExistenceQueryAndOneRepositoryCallInInputOrder(): void
    {
        $existingId = Uuid::v7();
        $missingId = Uuid::v7();
        $calls = 0;
        $queries = $this->queryBus(static function (CheckAccountExistenceQuery $query) use ($existingId, $missingId, &$calls): CheckAccountExistenceResult {
            ++$calls;
            self::assertSame([$existingId, $missingId], $query->accountIds);

            return new CheckAccountExistenceResult([new AccountExistenceResult($existingId, true), new AccountExistenceResult($missingId, false)]);
        });
        $checks = [];
        $expected = [];
        for ($index = 0; $index < 100; ++$index) {
            $checks[] = new PermissionCheckInput(0 === $index % 2 ? $existingId : $missingId, 'task_tracking.task.view', 'global');
            $expected[] = 0 === $index % 2;
        }
        $assignments = $this->createMock(AssignmentRepository::class);
        $assignments->expects(self::once())->method('evaluate')->with(self::callback(static function (mixed $domainChecks) use ($existingId, $missingId): bool {
            self::assertIsArray($domainChecks);
            self::assertCount(100, $domainChecks);
            foreach ($domainChecks as $index => $check) {
                self::assertInstanceOf(PermissionCheck::class, $check);
                self::assertSame(0 === $index % 2 ? $existingId : $missingId, $check->accountId);
            }

            return true;
        }), [$existingId])->willReturn($expected);

        $result = new EvaluatePermissionsHandler($assignments, new AuthorizationCatalog(), $queries)(new EvaluatePermissionsQuery($checks));

        self::assertSame(1, $calls);
        self::assertCount(100, $result->decisions);
        foreach ($result->decisions as $index => $decision) {
            self::assertSame($expected[$index], $decision->allowed);
        }
    }

    public function testInvalidLastCheckPreventsAllBusinessReads(): void
    {
        $assignments = $this->createMock(AssignmentRepository::class);
        $assignments->expects(self::never())->method('evaluate');
        $handler = new EvaluatePermissionsHandler($assignments, new AuthorizationCatalog(), $this->unusedQueryBus());

        $this->expectException(InvalidAuthorizationInput::class);
        $handler(new EvaluatePermissionsQuery([
            new PermissionCheckInput(Uuid::v7(), 'task_tracking.task.view', 'global'),
            new PermissionCheckInput(Uuid::v7(), 'authorizing.manage', 'resource', 'task_tracking.task', Uuid::v7()),
        ]));
    }

    public function testIncompleteRepositoryDecisionsAreAnInternalFailure(): void
    {
        $id = Uuid::v7();
        $queries = $this->queryBus(static fn (CheckAccountExistenceQuery $query): CheckAccountExistenceResult => new CheckAccountExistenceResult([new AccountExistenceResult($query->accountIds[0], true)]));
        $assignments = $this->createMock(AssignmentRepository::class);
        $assignments->expects(self::once())->method('evaluate')->willReturn([]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('authorizing.decisions: Invalid decision count.');
        new EvaluatePermissionsHandler($assignments, new AuthorizationCatalog(), $queries)(new EvaluatePermissionsQuery([
            new PermissionCheckInput($id, 'task_tracking.task.view', 'global'),
        ]));
    }

    public function testExistenceOutageDoesNotReachEvaluationOrReturnPartialDecisions(): void
    {
        $queries = $this->queryBus(static function (CheckAccountExistenceQuery $query): CheckAccountExistenceResult {
            throw new \RuntimeException('Existence unavailable.');
        });
        $assignments = $this->createMock(AssignmentRepository::class);
        $assignments->expects(self::never())->method('evaluate');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Existence unavailable.');
        new EvaluatePermissionsHandler($assignments, new AuthorizationCatalog(), $queries)(new EvaluatePermissionsQuery([
            new PermissionCheckInput(Uuid::v7(), 'task_tracking.task.view', 'global'),
        ]));
    }

    #[DataProvider('invalidCursors')]
    public function testInvalidOrCrossAccountCursorIsRejectedBeforeReading(bool $otherAccount, int $source): void
    {
        $id = Uuid::v7();
        $assignments = $this->createMock(AssignmentRepository::class);
        $assignments->expects(self::never())->method('assignments');

        $this->expectException(InvalidAuthorizationInput::class);
        new ListAccountAssignmentsHandler($assignments)(new ListAccountAssignmentsQuery($id, 50, new AssignmentCursorInput($otherAccount ? Uuid::v7() : $id, $source, Uuid::v7())));
    }

    /** @return iterable<string, array{bool, int}> */
    public static function invalidCursors(): iterable
    {
        yield 'another account' => [true, 0];
        yield 'negative source' => [false, -1];
        yield 'unknown source' => [false, 4];
    }

    #[DataProvider('pageSizes')]
    public function testListingMapsRetiredRowsAndUsesLastReturnedCursorOnlyWhenLookaheadExists(int $rowCount): void
    {
        $id = Uuid::v7();
        $after = new AssignmentCursorInput($id, 0, Uuid::v7());
        $rows = array_slice([
            new Assignment(Uuid::v7(), 0, 'role', 'retired.role', 'global', null, null),
            new Assignment(Uuid::v7(), 1, 'role', 'retired.role', 'resource', 'retired.resource', Uuid::v7()),
            new Assignment(Uuid::v7(), 2, 'permission', 'retired.permission', 'global', null, null),
        ], 0, $rowCount);
        $assignments = $this->createMock(AssignmentRepository::class);
        $assignments->expects(self::once())->method('assignments')->with($id, 2, 0, $after->assignmentId)->willReturn($rows);

        $result = new ListAccountAssignmentsHandler($assignments)(new ListAccountAssignmentsQuery($id, 2, $after));

        self::assertCount(min(2, $rowCount), $result->assignments);
        foreach ($result->assignments as $index => $assignment) {
            self::assertSame(get_object_vars($rows[$index]), get_object_vars($assignment));
        }
        if (3 === $rowCount) {
            self::assertNotNull($result->next);
            self::assertSame($id, $result->next->accountId);
            self::assertSame($rows[1]->id, $result->next->assignmentId);
            self::assertSame(1, $result->next->source);
        } else {
            self::assertNull($result->next);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function pageSizes(): iterable
    {
        yield 'empty' => [0];
        yield 'full final page' => [2];
        yield 'lookahead' => [3];
    }

    public function testNativeMappingsBoundListsCascadeInputsAndKeepRetiredOutputKeysListable(): void
    {
        $validator = Validation::createValidatorBuilder()->addYamlMapping(dirname(__DIR__, 2).'/src/Module/Authorizing/Resources/config/validation.yaml')->getValidator();
        $id = Uuid::v7();
        $check = new PermissionCheckInput($id, 'task_tracking.task.view', 'global');
        self::assertCount(0, $validator->validate(new EvaluatePermissionsQuery(array_fill(0, 100, $check))));
        foreach ([
            new EvaluatePermissionsQuery([]),
            new EvaluatePermissionsQuery(array_fill(0, 101, $check)),
            new EvaluatePermissionsQuery([new PermissionCheckInput($id, "private\ninvalid", 'global')]),
            new ChangeAccountAssignmentsCommand($id, [new AssignmentChangeInput('replace', 'role', 'task_tracking.reader', 'global')]),
            new ListAccountAssignmentsQuery($id, 101),
            new ListAccountAssignmentsQuery($id, 50, new AssignmentCursorInput($id, 4, Uuid::v7())),
        ] as $invalid) {
            self::assertGreaterThan(0, count($validator->validate($invalid)));
        }
        self::assertCount(0, $validator->validate(new ListAccountAssignmentsResult([
            new AccountAssignmentResult(Uuid::v7(), 1, 'role', 'retired.role', 'resource', 'retired.resource', Uuid::v7()),
        ])));
    }

    private function unusedQueryBus(): QueryBus
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        return new QueryBus($bus);
    }

    /** @param callable(CheckAccountExistenceQuery): CheckAccountExistenceResult $handler */
    private function queryBus(callable $handler): QueryBus
    {
        return new QueryBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            CheckAccountExistenceQuery::class => [$handler],
        ]))]));
    }
}
