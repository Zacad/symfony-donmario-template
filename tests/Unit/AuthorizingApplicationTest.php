<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authorizing\Application\ChangeSubjectAssignments\AssignmentChangeInput;
use App\Module\Authorizing\Application\ChangeSubjectAssignments\ChangeSubjectAssignmentsCommand;
use App\Module\Authorizing\Application\ChangeSubjectAssignments\ChangeSubjectAssignmentsHandler;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EntitlementCheckInput;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsHandler;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsQuery;
use App\Module\Authorizing\Application\ListSubjectAssignments\AssignmentCursorInput;
use App\Module\Authorizing\Application\ListSubjectAssignments\ListSubjectAssignmentsHandler;
use App\Module\Authorizing\Application\ListSubjectAssignments\ListSubjectAssignmentsQuery;
use App\Module\Authorizing\Domain\Assignment\AssignmentChangeCountsValueObject;
use App\Module\Authorizing\Domain\Assignment\AssignmentChangeValueObject;
use App\Module\Authorizing\Domain\Assignment\AssignmentKindEnum;
use App\Module\Authorizing\Domain\Assignment\AssignmentOperationEnum;
use App\Module\Authorizing\Domain\Assignment\AssignmentReferenceValueObject;
use App\Module\Authorizing\Domain\Assignment\AssignmentRepository;
use App\Module\Authorizing\Domain\Assignment\EntitlementCheckValueObject;
use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;
use App\Module\Authorizing\Infrastructure\Framework\Symfony\Security\AuthorizingVoter;
use App\Platform\Messaging\QueryBus;
use App\Tests\Fixtures\Authorizing\AuthorizationCatalogFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validation;

final class AuthorizingApplicationTest extends TestCase
{
    public function testDuplicateNaturalKindKeyFailsBeforeWrite(): void
    {
        $repository = $this->createMock(AssignmentRepository::class);
        $repository->expects(self::never())->method('change');

        $this->expectException(InvalidAuthorizationInputException::class);
        new ChangeSubjectAssignmentsHandler($repository, AuthorizationCatalogFixture::create())(new ChangeSubjectAssignmentsCommand(Uuid::v7(), [
            new AssignmentChangeInput('add', 'role', 'task_tracking.user'),
            new AssignmentChangeInput('remove', 'role', 'task_tracking.user'),
        ]));
    }

    public function testOpaqueSubjectChangesUseVariadicNaturalReferencesAndCounts(): void
    {
        $subject = Uuid::v7();
        $repository = $this->createMock(AssignmentRepository::class);
        $repository->expects(self::once())->method('change')->with(
            $subject,
            self::callback(static fn (AssignmentChangeValueObject $change): bool => AssignmentOperationEnum::Add === $change->operation
                && AssignmentKindEnum::Role === $change->reference->kind
                && 'task_tracking.user' === $change->reference->key),
            self::callback(static fn (AssignmentChangeValueObject $change): bool => AssignmentOperationEnum::Remove === $change->operation
                && AssignmentKindEnum::Permission === $change->reference->kind
                && 'retired.permission' === $change->reference->key),
        )->willReturn(new AssignmentChangeCountsValueObject(1, 1));

        $result = new ChangeSubjectAssignmentsHandler($repository, AuthorizationCatalogFixture::create())(new ChangeSubjectAssignmentsCommand($subject, [
            new AssignmentChangeInput('add', 'role', 'task_tracking.user'),
            new AssignmentChangeInput('remove', 'permission', 'retired.permission'),
        ]));

        self::assertSame([2, 1, 1, 0], [$result->requested, $result->added, $result->removed, $result->unchanged]);
    }

    public function testUnknownPermissionAdditionFailsBeforePersistence(): void
    {
        $repository = $this->createMock(AssignmentRepository::class);
        $repository->expects(self::never())->method('change');

        $this->expectException(InvalidAuthorizationInputException::class);
        new ChangeSubjectAssignmentsHandler($repository, AuthorizationCatalogFixture::create())(new ChangeSubjectAssignmentsCommand(Uuid::v7(), [
            new AssignmentChangeInput('add', 'permission', 'retired.permission'),
        ]));
    }

    public function testImpossibleRepositoryCountsFailClosed(): void
    {
        $repository = $this->createStub(AssignmentRepository::class);
        $repository->method('change')->willReturn(new AssignmentChangeCountsValueObject(2, 0));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('authorizing.assignments: Invalid change counts.');
        new ChangeSubjectAssignmentsHandler($repository, AuthorizationCatalogFixture::create())(new ChangeSubjectAssignmentsCommand(Uuid::v7(), [
            new AssignmentChangeInput('add', 'role', 'task_tracking.user'),
        ]));
    }

    public function testOneHundredChecksUseOneVariadicRepositoryCallInInputOrder(): void
    {
        $subject = Uuid::v7();
        $checks = array_fill(0, 100, new EntitlementCheckInput($subject, 'task_tracking.task.view'));
        $repository = $this->createMock(AssignmentRepository::class);
        $repository->expects(self::once())->method('evaluate')->willReturnCallback(static function (EntitlementCheckValueObject ...$checks) use ($subject): array {
            self::assertCount(100, $checks);
            self::assertTrue($subject->equals($checks[0]->subjectId));

            return array_fill(0, 100, true);
        });

        $result = new EvaluateSubjectEntitlementsHandler($repository)(new EvaluateSubjectEntitlementsQuery($checks));

        self::assertCount(100, $result->decisions);
        self::assertTrue($result->decisions[99]->allowed);
    }

    public function testListingUsesNaturalReferenceCursorAndCleanOutputShape(): void
    {
        $subject = Uuid::v7();
        $cursor = new AssignmentCursorInput($subject, 'role', 'active.role');
        $rows = [
            new AssignmentReferenceValueObject(AssignmentKindEnum::Role, 'next.role'),
            new AssignmentReferenceValueObject(AssignmentKindEnum::Permission, 'authorizing.manage'),
            new AssignmentReferenceValueObject(AssignmentKindEnum::Permission, 'task_tracking.task.view'),
        ];
        $repository = $this->createMock(AssignmentRepository::class);
        $repository->expects(self::once())->method('findPage')->with(
            $subject,
            2,
            self::callback(static fn (?AssignmentReferenceValueObject $after): bool => null !== $after
                && AssignmentKindEnum::Role === $after->kind
                && 'active.role' === $after->key),
        )->willReturn($rows);

        $result = new ListSubjectAssignmentsHandler($repository)(new ListSubjectAssignmentsQuery($subject, 2, $cursor));

        self::assertCount(2, $result->assignments);
        self::assertSame(['kind', 'key'], array_keys(get_object_vars($result->assignments[0])));
        self::assertSame(['role', 'next.role'], [$result->assignments[0]->kind, $result->assignments[0]->key]);
        self::assertNotNull($result->next);
        self::assertSame(['subjectId', 'kind', 'key'], array_keys(get_object_vars($result->next)));
        self::assertSame([$subject, 'permission', 'authorizing.manage'], [$result->next->subjectId, $result->next->kind, $result->next->key]);
    }

    public function testCursorMustBelongToTheQueriedSubject(): void
    {
        $repository = $this->createMock(AssignmentRepository::class);
        $repository->expects(self::never())->method('findPage');

        $this->expectException(InvalidAuthorizationInputException::class);
        new ListSubjectAssignmentsHandler($repository)(new ListSubjectAssignmentsQuery(
            Uuid::v7(),
            after: new AssignmentCursorInput(Uuid::v7(), 'role', 'active.role'),
        ));
    }

    public function testNativeValidationMatchesCleanAssignmentContracts(): void
    {
        $validator = Validation::createValidatorBuilder()->addYamlMapping(dirname(__DIR__, 2).'/src/Module/Authorizing/Resources/config/validation.yaml')->getValidator();
        $subject = Uuid::v7();

        self::assertCount(0, $validator->validate(new EvaluateSubjectEntitlementsQuery([
            new EntitlementCheckInput($subject, 'task_tracking.task.view'),
        ])));
        self::assertCount(0, $validator->validate(new ChangeSubjectAssignmentsCommand($subject, [
            new AssignmentChangeInput('remove', 'role', 'retired.role'),
        ])));
        self::assertCount(0, $validator->validate(new ListSubjectAssignmentsQuery(
            $subject,
            after: new AssignmentCursorInput($subject, 'permission', 'authorizing.manage'),
        )));
        self::assertGreaterThan(0, count($validator->validate(new EvaluateSubjectEntitlementsQuery([]))));
        self::assertGreaterThan(0, count($validator->validate(new ChangeSubjectAssignmentsCommand($subject, [
            new AssignmentChangeInput('replace', 'role', 'retired.role'),
        ]))));
    }

    public function testVoterSupportsContextualRouteWithNullPermission(): void
    {
        $voter = new AuthorizingVoter([EvaluateSubjectEntitlementsQuery::class => null], new QueryBus(new MessageBus()));

        self::assertTrue($voter->supportsType(EvaluateSubjectEntitlementsQuery::class));
    }
}
