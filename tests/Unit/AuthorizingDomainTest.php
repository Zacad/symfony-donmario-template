<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authorizing\Domain\Assignment\AssignmentChangeCountsValueObject;
use App\Module\Authorizing\Domain\Assignment\AssignmentChangeValueObject;
use App\Module\Authorizing\Domain\Assignment\AssignmentKindEnum;
use App\Module\Authorizing\Domain\Assignment\AssignmentOperationEnum;
use App\Module\Authorizing\Domain\Assignment\AssignmentReferenceValueObject;
use App\Module\Authorizing\Domain\Assignment\EntitlementCheckValueObject;
use App\Module\Authorizing\Domain\Assignment\PermissionGrantEntity;
use App\Module\Authorizing\Domain\Assignment\RoleAssignmentEntity;
use App\Module\Authorizing\Domain\Capability\AuthorizationCatalogService;
use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;
use App\Module\Authorizing\Domain\Role\RoleEntity;
use App\Module\Authorizing\Domain\Role\RolePermissionMembershipEntity;
use App\Module\Authorizing\Domain\Role\RolePermissionSetValueObject;
use App\Tests\Fixtures\Authorizing\AuthorizationCatalogFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AuthorizingDomainTest extends TestCase
{
    public function testRolePermissionSetSortsCrossModulePermissions(): void
    {
        $permissions = AuthorizationCatalogFixture::create()->rolePermissionSet([
            'task_tracking.task.view',
            'authorizing.manage',
        ]);

        self::assertSame(['authorizing.manage', 'task_tracking.task.view'], $permissions->permissions);
        self::assertSame(['permissions'], array_keys(get_object_vars($permissions)));
    }

    public function testRolePermissionSetAcceptsTheTotalMembershipBound(): void
    {
        $permissions = [];
        for ($index = 0; $index < 4096; ++$index) {
            $permissions[] = sprintf('permission.%04d', $index);
        }

        self::assertCount(4096, new RolePermissionSetValueObject($permissions)->permissions);
    }

    /** @param list<string> $permissions */
    #[DataProvider('invalidPermissionSets')]
    public function testRolePermissionSetRejectsDuplicatesInvalidKeysAndBounds(array $permissions): void
    {
        $this->expectException(InvalidAuthorizationInputException::class);
        new RolePermissionSetValueObject($permissions);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalidPermissionSets(): iterable
    {
        yield 'empty' => [[]];
        yield 'duplicate' => [['authorizing.manage', 'authorizing.manage']];
        yield 'malformed key' => [['Permission.Read']];
        yield 'over total edge bound' => [array_fill(0, 4097, 'authorizing.manage')];
    }

    public function testRoleCreationAndDefinitionOwnRevisionedLifecycle(): void
    {
        $role = RoleEntity::create('operations.lead', 'Operations lead', new RolePermissionSetValueObject(['authorizing.manage']), null);

        self::assertSame(['operations.lead', 'Operations lead', 1, null, ['authorizing.manage']], [
            $role->key(),
            $role->label(),
            $role->revision(),
            $role->retiredAt(),
            $role->permissions(),
        ]);
        self::assertTrue($role->define('Task reader', new RolePermissionSetValueObject(['task_tracking.task.view']), 1, false));
        self::assertSame(['Task reader', 2, ['task_tracking.task.view']], [$role->label(), $role->revision(), $role->permissions()]);
    }

    public function testNewRoleCannotClaimAnExistingRevision(): void
    {
        $this->expectException(InvalidAuthorizationInputException::class);
        RoleEntity::create('operations.lead', 'Operations lead', new RolePermissionSetValueObject(['authorizing.manage']), 1);
    }

    public function testCreateIfAbsentAcceptsOnlyAnExactActiveDefinition(): void
    {
        $permissions = new RolePermissionSetValueObject(['authorizing.manage']);
        $role = RoleEntity::create('operations.lead', 'Operations lead', $permissions, null);

        self::assertFalse($role->define('Operations lead', $permissions, null, true));
        foreach ([
            ['Different label', $permissions],
            ['Operations lead', new RolePermissionSetValueObject(['task_tracking.task.view'])],
        ] as [$label, $candidate]) {
            try {
                $role->define($label, $candidate, null, true);
                self::fail('A mismatched create-if-absent definition was accepted.');
            } catch (InvalidAuthorizationInputException) {
            }
        }
    }

    public function testRoleRevisionChecksRetirementAndIrreversibility(): void
    {
        $role = RoleEntity::create('operations.lead', 'Operations lead', new RolePermissionSetValueObject(['authorizing.manage']), null);
        foreach ([null, 2] as $revision) {
            try {
                $role->define('Updated', new RolePermissionSetValueObject(['authorizing.manage']), $revision, false);
                self::fail('An invalid expected revision was accepted.');
            } catch (InvalidAuthorizationInputException) {
            }
        }

        $retiredAt = new \DateTimeImmutable('2026-09-22T12:00:00Z');
        self::assertTrue($role->retire(1, $retiredAt));
        self::assertSame([2, $retiredAt], [$role->revision(), $role->retiredAt()]);
        self::assertFalse($role->retire(2, $retiredAt));

        $this->expectException(InvalidAuthorizationInputException::class);
        $role->define('Updated', new RolePermissionSetValueObject(['authorizing.manage']), 2, false);
    }

    public function testRoleRevisionCannotOverflow(): void
    {
        $permissions = new RolePermissionSetValueObject(['authorizing.manage']);
        $role = RoleEntity::reconstitute('operations.lead', 'Operations lead', 2147483647, null, $permissions->permissions);
        try {
            $role->define('Updated', $permissions, 2147483647, false);
            self::fail('A role definition overflow was accepted.');
        } catch (InvalidAuthorizationInputException) {
        }

        $this->expectException(InvalidAuthorizationInputException::class);
        $role->retire(2147483647, new \DateTimeImmutable('2026-09-22T12:00:00Z'));
    }

    /** @param list<string> $permissions */
    #[DataProvider('corruptStoredRoles')]
    public function testCorruptRoleReconstitutionFailsClosed(string $key, string $label, int $revision, array $permissions): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid stored authorization role.');
        RoleEntity::reconstitute($key, $label, $revision, null, $permissions);
    }

    /** @return iterable<string, array{string, string, int, list<string>}> */
    public static function corruptStoredRoles(): iterable
    {
        yield 'malformed key' => ['Operations Lead', 'Operations lead', 1, ['authorizing.manage']];
        yield 'malformed label' => ['operations.lead', "Operations\nlead", 1, ['authorizing.manage']];
        yield 'zero revision' => ['operations.lead', 'Operations lead', 0, ['authorizing.manage']];
        yield 'empty permission set' => ['operations.lead', 'Operations lead', 1, []];
        yield 'duplicate permission' => ['operations.lead', 'Operations lead', 1, ['authorizing.manage', 'authorizing.manage']];
    }

    public function testRetiredStoredRoleMustHaveAdvancedRevision(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid stored authorization role.');
        RoleEntity::reconstitute(
            'operations.lead',
            'Operations lead',
            1,
            new \DateTimeImmutable('2026-09-22T12:00:00Z'),
            ['authorizing.manage'],
        );
    }

    public function testAssignmentValuesUseEnumAndNaturalKindKeyShapes(): void
    {
        $reference = new AssignmentReferenceValueObject(AssignmentKindEnum::Permission, 'authorizing.manage');
        $change = new AssignmentChangeValueObject(AssignmentOperationEnum::Add, $reference);
        $counts = new AssignmentChangeCountsValueObject(2, 1);

        self::assertSame(['role', 'permission'], array_column(AssignmentKindEnum::cases(), 'value'));
        self::assertSame(['add', 'remove'], array_column(AssignmentOperationEnum::cases(), 'value'));
        self::assertSame(['kind', 'key'], array_keys(get_object_vars($reference)));
        self::assertSame(['operation', 'reference'], array_keys(get_object_vars($change)));
        self::assertSame(['added', 'removed'], array_keys(get_object_vars($counts)));
        self::assertSame([2, 1], [$counts->added, $counts->removed]);
    }

    public function testAssignmentEntitiesExposeNaturalIdentityAndGuardRetiredRoles(): void
    {
        $subjectId = Uuid::v7();
        $role = RoleEntity::create('operations.lead', 'Operations lead', new RolePermissionSetValueObject(['authorizing.manage']), null);
        $assignment = RoleAssignmentEntity::assign($subjectId, $role);
        $grant = new PermissionGrantEntity($subjectId, 'authorizing.manage');
        $membership = new RolePermissionMembershipEntity($role, 'authorizing.manage');

        self::assertSame($subjectId, $assignment->subjectId());
        self::assertSame($role, $assignment->role());
        self::assertSame([$subjectId, 'authorizing.manage'], [$grant->subjectId(), $grant->permissionKey()]);
        self::assertSame([$role, 'authorizing.manage'], [$membership->role(), $membership->permissionKey()]);

        self::assertTrue($role->retire(1, new \DateTimeImmutable('2026-09-22T12:00:00Z')));
        $this->expectException(InvalidAuthorizationInputException::class);
        RoleAssignmentEntity::assign(Uuid::v7(), $role);
    }

    public function testNegativeAssignmentCountsAreRejected(): void
    {
        $this->expectException(InvalidAuthorizationInputException::class);
        new AssignmentChangeCountsValueObject(-1, 0);
    }

    public function testUnknownWellFormedPermissionIsAValidDenyingCheck(): void
    {
        $check = new EntitlementCheckValueObject(Uuid::v7(), 'retired.permission');

        self::assertFalse(AuthorizationCatalogFixture::create()->isKnownPermission($check->permission));
        self::assertSame(['subjectId', 'permission'], array_keys(get_object_vars($check)));
    }

    public function testCatalogAcceptsKnownPermissionAdditionsAndAnyRemoval(): void
    {
        $catalog = AuthorizationCatalogFixture::create();
        $catalog->validateChange(new AssignmentChangeValueObject(
            AssignmentOperationEnum::Add,
            new AssignmentReferenceValueObject(AssignmentKindEnum::Permission, 'authorizing.manage'),
        ));
        $catalog->validateChange(new AssignmentChangeValueObject(
            AssignmentOperationEnum::Remove,
            new AssignmentReferenceValueObject(AssignmentKindEnum::Permission, 'retired.permission'),
        ));
        $this->addToAssertionCount(1);
    }

    public function testCatalogRejectsUnknownPermissionAddition(): void
    {
        $this->expectException(InvalidAuthorizationInputException::class);
        AuthorizationCatalogFixture::create()->validateChange(new AssignmentChangeValueObject(
            AssignmentOperationEnum::Add,
            new AssignmentReferenceValueObject(AssignmentKindEnum::Permission, 'retired.permission'),
        ));
    }

    public function testCatalogAppliesPermissionSetBoundAfterConstruction(): void
    {
        $capabilities = [];
        for ($index = 0; $index < 4096; ++$index) {
            $key = sprintf('permission.%04d', $index);
            $capabilities[] = ['module' => 'permission', 'key' => $key, 'label' => $key, 'access' => 'read', 'operations' => ['PermissionQuery']];
        }
        $catalog = new AuthorizationCatalogService($capabilities);

        self::assertCount(4096, $catalog->rolePermissionSet(array_column($capabilities, 'key'))->permissions);
    }

    #[DataProvider('invalidKeys')]
    public function testMalformedKeysAndWildcardsRemainRejected(string $key): void
    {
        $this->expectException(InvalidAuthorizationInputException::class);
        new EntitlementCheckValueObject(Uuid::v7(), $key);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Permission.Read'];
        yield 'wildcard' => ['permission.*'];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'newline' => ["permission.read\n"];
    }
}
