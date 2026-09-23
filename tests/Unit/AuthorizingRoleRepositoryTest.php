<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authorizing\Domain\Assignment\AssignmentChangeValueObject;
use App\Module\Authorizing\Domain\Assignment\AssignmentKindEnum;
use App\Module\Authorizing\Domain\Assignment\AssignmentOperationEnum;
use App\Module\Authorizing\Domain\Assignment\AssignmentReferenceValueObject;
use App\Module\Authorizing\Domain\Assignment\EntitlementCheckValueObject;
use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;
use App\Module\Authorizing\Domain\Role\RolePermissionSetValueObject;
use App\Module\Authorizing\Infrastructure\Persistence\DoctrineAssignmentRepository;
use App\Module\Authorizing\Infrastructure\Persistence\DoctrineRoleRepository;
use App\Tests\Fixtures\Authorizing\AuthorizationCatalogFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AuthorizingRoleRepositoryTest extends TestCase
{
    public function testNewRoleWritesOnlyCleanRoleAndPermissionTables(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('executeQuery')->with(
            'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
            ['authorizing.role_catalog'],
        )->willReturn($this->releasedResult());
        $connection->expects(self::once())->method('fetchAssociative')->with(
            self::stringContains('FROM public.authorizing_role r'),
            ['operations.lead'],
        )->willReturn(false);
        $connection->expects(self::once())->method('fetchOne')->with(
            self::stringContains('FROM public.authorizing_role_permission m JOIN public.authorizing_role r'),
            [],
        )->willReturn(0);
        $connection->expects(self::once())->method('insert')->with('public.authorizing_role', [
            'role_key' => 'operations.lead',
            'label' => 'Operations lead',
            'revision' => 1,
        ])->willReturn(1);
        $connection->expects(self::once())->method('delete')->with('public.authorizing_role_permission', ['role_key' => 'operations.lead'])->willReturn(0);
        $connection->expects(self::once())->method('executeStatement')->with(
            'INSERT INTO public.authorizing_role_permission (role_key, permission_key) VALUES (CAST(? AS text), CAST(? AS text)), (CAST(? AS text), CAST(? AS text))',
            ['operations.lead', 'authorizing.manage', 'operations.lead', 'task_tracking.task.view'],
        )->willReturn(2);

        $role = new DoctrineRoleRepository($this->manager($connection))->define(
            'operations.lead',
            'Operations lead',
            new RolePermissionSetValueObject(['authorizing.manage', 'task_tracking.task.view']),
            null,
            false,
        );

        self::assertSame(['operations.lead', 1, ['authorizing.manage', 'task_tracking.task.view']], [$role->key(), $role->revision(), $role->permissions()]);
    }

    public function testCreateIfAbsentUsesExactCurrentRoleDataWithoutWrites(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('executeQuery')->willReturn($this->releasedResult());
        $connection->expects(self::once())->method('fetchAssociative')->willReturn([
            'role_key' => 'runtime.reader',
            'label' => 'Task reader',
            'revision' => 9,
            'retired_at' => null,
            'permissions' => '["task_tracking.task.view"]',
        ]);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('insert');
        $connection->expects(self::never())->method('delete');
        $connection->expects(self::never())->method('executeStatement');

        $role = new DoctrineRoleRepository($this->manager($connection))->define(
            'runtime.reader',
            'Task reader',
            new RolePermissionSetValueObject(['task_tracking.task.view']),
            null,
            true,
        );

        self::assertSame(9, $role->revision());
    }

    public function testEmptyStoredRolePermissionSetIsCorruption(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->willReturn([
            'role_key' => 'runtime.empty',
            'label' => 'Empty role',
            'revision' => 1,
            'retired_at' => null,
            'permissions' => '[]',
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid stored authorization role.');
        new DoctrineRoleRepository($this->manager($connection))->get('runtime.empty');
    }

    public function testTotalActiveEdgeLimitRejectsAnAdditionalMembership(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('executeQuery')->willReturn($this->releasedResult());
        $connection->expects(self::once())->method('fetchAssociative')->willReturn(false);
        $connection->expects(self::once())->method('fetchOne')->willReturn(4096);
        $connection->expects(self::never())->method('insert');

        $this->expectException(InvalidAuthorizationInputException::class);
        new DoctrineRoleRepository($this->manager($connection))->define('runtime.role', 'Runtime role', new RolePermissionSetValueObject(['authorizing.manage']), null, false);
    }

    public function testPermissionMayBeSharedWithinTotalEdgeLimit(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('executeQuery')->willReturn($this->releasedResult());
        $connection->expects(self::once())->method('fetchAssociative')->willReturn(false);
        $connection->expects(self::once())->method('fetchOne')->willReturn(4095);
        $connection->expects(self::once())->method('insert')->willReturn(1);
        $connection->expects(self::once())->method('delete')->willReturn(0);
        $connection->expects(self::once())->method('executeStatement')->willReturn(1);

        $role = new DoctrineRoleRepository($this->manager($connection))->define('runtime.role', 'Runtime role', new RolePermissionSetValueObject(['authorizing.manage']), null, false);

        self::assertSame(['authorizing.manage'], $role->permissions());
    }

    public function testRetirementUsesDatabaseTimeForOneAtomicEntityTransition(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('executeQuery')->willReturn($this->releasedResult());
        $connection->expects(self::once())->method('fetchAssociative')->willReturn([
            'role_key' => 'operations.lead',
            'label' => 'Operations lead',
            'revision' => 1,
            'retired_at' => null,
            'permissions' => '["authorizing.manage"]',
        ]);
        $connection->expects(self::once())->method('fetchOne')->with(
            "SELECT date_trunc('second', CURRENT_TIMESTAMP AT TIME ZONE 'UTC')::text",
        )->willReturn('2026-09-22 12:00:00');
        $connection->expects(self::once())->method('executeStatement')->with(
            'UPDATE public.authorizing_role SET retired_at = CAST(? AS timestamp without time zone), revision = ? WHERE role_key = ? AND revision = ? AND retired_at IS NULL',
            ['2026-09-22 12:00:00', 2, 'operations.lead', 1],
        )->willReturn(1);

        $role = new DoctrineRoleRepository($this->manager($connection))->retire('operations.lead', 1);

        self::assertSame(2, $role->revision());
        self::assertSame('2026-09-22 12:00:00', $role->retiredAt()?->format('Y-m-d H:i:s'));
    }

    public function testRoleAdditionLocksSubjectAndCatalogueThenWritesNaturalIdentity(): void
    {
        $subject = Uuid::v7();
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $lock = 0;
        $connection->expects(self::exactly(2))->method('executeQuery')->willReturnCallback(function (string $sql, array $parameters) use ($subject, &$lock): Result {
            ++$lock;
            self::assertSame(1 === $lock ? 'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))' : 'SELECT pg_advisory_xact_lock_shared(hashtextextended(CAST(? AS text), 0))', $sql);
            self::assertSame(1 === $lock ? ['authorizing.subject_assignments:'.$subject->toRfc4122()] : ['authorizing.role_catalog'], $parameters);

            return $this->releasedResult();
        });
        $connection->expects(self::once())->method('fetchAllAssociative')->with(
            self::logicalAnd(
                self::stringContains('JOIN public.authorizing_role r'),
                self::stringContains('LEFT JOIN public.authorizing_role_permission m'),
            ),
            ['task_tracking.user'],
        )->willReturn([[
            'role_key' => 'task_tracking.user',
            'label' => 'Task user',
            'revision' => 1,
            'retired_at' => null,
            'permissions' => '["task_tracking.task.view"]',
        ]]);
        $connection->expects(self::once())->method('executeStatement')->with(
            'INSERT INTO public.authorizing_role_assignment (subject_id, role_key) VALUES (CAST(? AS uuid), CAST(? AS text)) ON CONFLICT (subject_id, role_key) DO NOTHING',
            [$subject->toRfc4122(), 'task_tracking.user'],
        )->willReturn(1);

        $result = new DoctrineAssignmentRepository($this->manager($connection), AuthorizationCatalogFixture::create())->change(
            $subject,
            new AssignmentChangeValueObject(
                AssignmentOperationEnum::Add,
                new AssignmentReferenceValueObject(AssignmentKindEnum::Role, 'task_tracking.user'),
            ),
        );

        self::assertSame([1, 0], [$result->added, $result->removed]);
    }

    public function testEvaluationReadsOnlyCleanGlobalTablesAndPreservesInputOrder(): void
    {
        $subject = Uuid::v7();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchFirstColumn')->willReturnCallback(static function (string $sql, array $parameters) use ($subject): array {
            self::assertStringContainsString('authorizing_role_assignment', $sql);
            self::assertStringContainsString('authorizing_permission_grant', $sql);
            self::assertStringContainsString('authorizing_role_permission', $sql);
            self::assertSame([0, $subject->toRfc4122(), 'task_tracking.task.view', 1, 1, $subject->toRfc4122(), 'retired.permission', 0], $parameters);

            return [1, 0];
        });

        $result = new DoctrineAssignmentRepository($this->manager($connection), AuthorizationCatalogFixture::create())->evaluate(
            new EntitlementCheckValueObject($subject, 'task_tracking.task.view'),
            new EntitlementCheckValueObject($subject, 'retired.permission'),
        );

        self::assertSame([true, false], $result);
    }

    public function testAssignmentPageReadsNaturalKindKeyRowsWithBoundCursor(): void
    {
        $subject = Uuid::v7();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturnCallback(static function (string $sql, array $parameters) use ($subject): array {
            self::assertStringContainsString('FROM public.authorizing_role_assignment', $sql);
            self::assertStringContainsString('FROM public.authorizing_permission_grant', $sql);
            self::assertStringContainsString('(kind_order, assignment_key) >', $sql);
            self::assertSame([$subject->toRfc4122(), $subject->toRfc4122(), 0, 'role.a', 3], $parameters);

            return [
                ['assignment_kind' => 'role', 'assignment_key' => 'role.b'],
                ['assignment_kind' => 'permission', 'assignment_key' => 'authorizing.manage'],
            ];
        });

        $rows = new DoctrineAssignmentRepository($this->manager($connection), AuthorizationCatalogFixture::create())->findPage(
            $subject,
            2,
            new AssignmentReferenceValueObject(AssignmentKindEnum::Role, 'role.a'),
        );

        self::assertSame(['role', 'permission'], array_map(static fn (AssignmentReferenceValueObject $row): string => $row->kind->value, $rows));
        self::assertSame(['role.b', 'authorizing.manage'], array_column($rows, 'key'));
    }

    public function testPermissionRemovalUsesCleanTableWithoutCatalogueRead(): void
    {
        $subject = Uuid::v7();
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('executeQuery')->with(
            'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
            ['authorizing.subject_assignments:'.$subject->toRfc4122()],
        )->willReturn($this->releasedResult());
        $connection->expects(self::never())->method('fetchAllAssociative');
        $connection->expects(self::once())->method('executeStatement')->with(
            'DELETE FROM public.authorizing_permission_grant WHERE (subject_id, permission_key) IN ((CAST(? AS uuid), CAST(? AS text)))',
            [$subject->toRfc4122(), 'retired.permission'],
        )->willReturn(1);

        $result = new DoctrineAssignmentRepository($this->manager($connection), AuthorizationCatalogFixture::create())->change(
            $subject,
            new AssignmentChangeValueObject(
                AssignmentOperationEnum::Remove,
                new AssignmentReferenceValueObject(AssignmentKindEnum::Permission, 'retired.permission'),
            ),
        );

        self::assertSame([0, 1], [$result->added, $result->removed]);
    }

    /** @return EntityManagerInterface&MockObject */
    private function manager(Connection $connection): EntityManagerInterface
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::atLeastOnce())->method('getConnection')->willReturn($connection);

        return $manager;
    }

    private function releasedResult(): Result
    {
        $result = $this->createMock(Result::class);
        $result->expects(self::once())->method('free');

        return $result;
    }
}
